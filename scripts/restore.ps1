$ErrorActionPreference = 'Stop'
$restoreContainerAttempted = $false
$restoreMarker = $null
$restoreMarkerOwner = $false
$restoreMarkerPreexisting = $false
$restoreLockDirectory = $null
$restoreLockOwned = $false

# Lock a direct restore and record its owner unless ./shop already holds the lock.
function Enter-RestoreLock {
    if ($env:OSPOS_SHOP_LOCK_HELD -eq '1') { return }
    $script:restoreLockDirectory = Join-Path $dataDirectory '.shop-command.lock'
    try {
        New-Item -ItemType Directory -Path $restoreLockDirectory -ErrorAction Stop | Out-Null
    } catch {
        throw "Another shop command or backup is running. If none is running, remove the lock folder: $restoreLockDirectory"
    }
    $script:restoreLockOwned = $true
    $script:restoreMarkerOwner = $true
    $started = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
    Set-Content -LiteralPath (Join-Path $restoreLockDirectory 'pid') -Value $PID -NoNewline
    Set-Content -LiteralPath (Join-Path $restoreLockDirectory 'host') -Value $env:COMPUTERNAME -NoNewline
    Set-Content -LiteralPath (Join-Path $restoreLockDirectory 'command') -Value 'restore' -NoNewline
    Set-Content -LiteralPath (Join-Path $restoreLockDirectory 'started') -Value $started -NoNewline
    Set-Content -LiteralPath (Join-Path $restoreLockDirectory 'started_epoch') -Value ([DateTimeOffset]::UtcNow.ToUnixTimeSeconds()) -NoNewline
}

# Remove a standalone restore's shared client-data lock when it exits.
function Exit-RestoreLock {
    if (-not $restoreLockOwned) { return }
    Remove-Item -LiteralPath (Join-Path $restoreLockDirectory 'pid') -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $restoreLockDirectory 'host') -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $restoreLockDirectory 'command') -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $restoreLockDirectory 'started') -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $restoreLockDirectory 'started_epoch') -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $restoreLockDirectory -Force -ErrorAction SilentlyContinue
    $script:restoreLockOwned = $false
}

# Read whether an unfinished update already has a saved rollback point.
function Get-RestoreUpdatePending {
    $rollbackConfig = Join-Path $dataDirectory 'rollback.conf'
    if (-not (Test-Path -LiteralPath $rollbackConfig -PathType Leaf)) { return 0 }
    $pendingValues = @(
        Get-Content -LiteralPath $rollbackConfig |
            Where-Object { $_ -match '^UPDATE_PENDING=' } |
            ForEach-Object { $_.Substring(15) }
    )
    if ($pendingValues.Count -gt 1) { throw 'The config file contains more than one value for UPDATE_PENDING.' }
    if ($pendingValues.Count -eq 0) { return 0 }
    if ($pendingValues[0] -notin @('0', '1')) { throw 'UPDATE_PENDING must be 0 or 1.' }
    return [int]$pendingValues[0]
}

# Refuse direct restores unless ./shop explicitly marked this launcher call as rollback recovery.
function Refuse-RestoreRecovery {
    if ($env:OSPOS_SHOP_LOCK_HELD -eq '1' -and $env:OSPOS_RESTORE_FOR_ROLLBACK -eq '1') { return }
    $rollbackUnfinished = Join-Path $dataDirectory 'rollback.unfinished'
    $updateInProgress = Join-Path $dataDirectory 'update.in-progress'
    if (Test-Path -LiteralPath $rollbackUnfinished) {
        [Console]::Error.WriteLine('Error: A rollback did not finish, so the database state is unknown.')
        [Console]::Error.WriteLine('Recovery command: ./shop rollback')
        exit 20
    }
    $updatePending = Get-RestoreUpdatePending
    if ((Test-Path -LiteralPath $updateInProgress) -or $updatePending -eq 1) {
        $recoveryCommand = './shop update'
        if ($updatePending -eq 1) {
            $recoveryCommand = './shop rollback'
        } elseif (Test-Path -LiteralPath (Join-Path $dataDirectory 'restore.unfinished')) {
            $recoveryCommand = './shop status'
        }
        [Console]::Error.WriteLine('Error: Restore is refused while an update is unfinished.')
        [Console]::Error.WriteLine('Nothing was changed. Safe next step: ' + $recoveryCommand)
        exit 20
    }
}

trap {
    [Console]::Error.WriteLine($_.Exception.Message)
    Exit-RestoreLock
    if ($restoreContainerAttempted) { exit 21 }
    exit 20
}

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$projectName = if ($env:COMPOSE_PROJECT_NAME) { $env:COMPOSE_PROJECT_NAME } else { Split-Path -Leaf $repoRoot }
$network = if ($env:OSPOS_DOCKER_NETWORK) { $env:OSPOS_DOCKER_NETWORK } else { $projectName + '_app_net' }
$dbHost = if ($env:OSPOS_DB_HOST) { $env:OSPOS_DB_HOST } else { 'mysql' }
$dataDirectory = if ($env:OSPOS_DATA_DIR) { $env:OSPOS_DATA_DIR } else { 'C:/OSPOS/Client' }
$archiveArg = $null
$uploadsArg = $null
$envArg = $null
$databaseArg = $null
$yesFlag = $false
$archiveSet = $false
$uploadsSet = $false
$envSet = $false
$databaseSet = $false

for ($index = 0; $index -lt $args.Count; $index++) {
    switch ($args[$index]) {
        '--help' {
            & docker run --rm --network $network `
                --mount "type=bind,source=$repoRoot,target=/work,readonly" `
                --entrypoint bash mariadb:10.5 /work/scripts/container/restore.sh --help --db-host $dbHost
            exit $LASTEXITCODE
        }
        '-h' {
            & docker run --rm --network $network `
                --mount "type=bind,source=$repoRoot,target=/work,readonly" `
                --entrypoint bash mariadb:10.5 /work/scripts/container/restore.sh --help --db-host $dbHost
            exit $LASTEXITCODE
        }
        '--archive' {
            if ($index + 1 -ge $args.Count) { throw '--archive needs a file path.' }
            if ($archiveSet) { throw '--archive was given more than once.' }
            $archiveArg = $args[++$index]
            $archiveSet = $true
        }
        '--uploads' {
            if ($index + 1 -ge $args.Count) { throw '--uploads needs a directory.' }
            if ($uploadsSet) { throw '--uploads was given more than once.' }
            $uploadsArg = $args[++$index]
            $uploadsSet = $true
        }
        '--env' {
            if ($index + 1 -ge $args.Count) { throw '--env needs a file path.' }
            if ($envSet) { throw '--env was given more than once.' }
            $envArg = $args[++$index]
            $envSet = $true
        }
        '--database' {
            if ($index + 1 -ge $args.Count) { throw '--database needs a database name.' }
            if ($databaseSet) { throw '--database was given more than once.' }
            $databaseArg = $args[++$index]
            $databaseSet = $true
        }
        '--yes' {
            if ($yesFlag) { throw '--yes was given more than once.' }
            $yesFlag = $true
        }
        default { throw "Unknown option: $($args[$index])" }
    }
}

if (-not $archiveSet) { throw '--archive is required.' }
if (-not (Test-Path -LiteralPath $dataDirectory -PathType Container)) { throw "Client data directory does not exist: $dataDirectory" }
$dataDirectory = (Resolve-Path -LiteralPath $dataDirectory).Path
try {
    Enter-RestoreLock
    Refuse-RestoreRecovery
    if ($null -eq $uploadsArg) { $uploadsArg = Join-Path $dataDirectory 'uploads' }
    if ($null -eq $envArg) { $envArg = Join-Path $dataDirectory 'secrets\app.env' }
    if (-not [System.IO.Path]::IsPathRooted($uploadsArg)) { $uploadsArg = Join-Path $repoRoot $uploadsArg }
    if (-not (Test-Path -LiteralPath $archiveArg -PathType Leaf)) { throw "Archive file does not exist: $archiveArg" }
    if (-not (Test-Path -LiteralPath $uploadsArg -PathType Container)) { throw "Uploads directory does not exist: $uploadsArg" }
    if (-not (Test-Path -LiteralPath $envArg -PathType Leaf)) { throw "Environment file does not exist: $envArg" }

    $archive = (Resolve-Path -LiteralPath $archiveArg).Path
    $archiveParent = [System.IO.Path]::GetDirectoryName($archive)
    $archiveName = [System.IO.Path]::GetFileName($archive)
    $uploads = (Resolve-Path -LiteralPath $uploadsArg).Path
    $uploadsParent = [System.IO.Path]::GetDirectoryName($uploads)
    $uploadsName = [System.IO.Path]::GetFileName($uploads)
    $envFile = (Resolve-Path -LiteralPath $envArg).Path

    $dockerArgs = @(
        'run', '--rm', '--network', $network,
        '--mount', "type=bind,source=$repoRoot,target=/work,readonly",
        '--mount', "type=bind,source=$archiveParent,target=/archive-parent,readonly",
        '--mount', "type=bind,source=$uploadsParent,target=/uploads-parent",
        '--mount', "type=bind,source=$envFile,target=/client.env,readonly",
        '--entrypoint', 'bash', 'mariadb:10.5',
        '/work/scripts/container/restore.sh',
        '--archive', "/archive-parent/$archiveName",
        '--uploads', "/uploads-parent/$uploadsName",
        '--env', '/client.env',
        '--db-host', $dbHost,
        '--platform', 'windows'
    )

    if ($databaseSet) { $dockerArgs += @('--database', $databaseArg) }
    if ($yesFlag) { $dockerArgs += '--yes' }

    if ($restoreMarkerOwner) {
        $restoreMarker = Join-Path $dataDirectory 'restore.unfinished'
        if (Test-Path -LiteralPath $restoreMarker) {
            $restoreMarkerPreexisting = $true
        } else {
            $markerStream = [System.IO.File]::Open($restoreMarker, [System.IO.FileMode]::CreateNew)
            $markerStream.Dispose()
        }
    }

    # Rewrite the uploads path in streamed output and retain the Docker status for phase mapping.
    $restoreContainerAttempted = $true
    & docker @dockerArgs | ForEach-Object {
        $line = [string]$_
        if ($line -like 'Restored uploads: /uploads-parent/*') {
            Write-Output "Restored uploads: $uploads"
        } else {
            Write-Output $_
        }
    }
    $dockerExitCode = $LASTEXITCODE
    if ($dockerExitCode -eq 20) {
        if ($restoreMarkerOwner -and -not $restoreMarkerPreexisting) {
            Remove-Item -LiteralPath $restoreMarker -Force -ErrorAction Stop
        }
        exit 20
    }
    if ($dockerExitCode -eq 21) {
        exit 21
    }
    if ($dockerExitCode -ne 0) {
        [Console]::Error.WriteLine('Restore could not be confirmed safe; the database may be partial.')
        exit 21
    }
    if ($restoreMarkerOwner) {
        Remove-Item -LiteralPath $restoreMarker -Force -ErrorAction Stop
    }
    exit 0
} finally {
    Exit-RestoreLock
}
