$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$projectName = if ($env:COMPOSE_PROJECT_NAME) { $env:COMPOSE_PROJECT_NAME } else { Split-Path -Leaf $repoRoot }
$network = if ($env:OSPOS_DOCKER_NETWORK) { $env:OSPOS_DOCKER_NETWORK } else { $projectName + '_app_net' }
$dbHost = if ($env:OSPOS_DB_HOST) { $env:OSPOS_DB_HOST } else { 'mysql' }
$dataDirectory = if ($env:OSPOS_DATA_DIR) { $env:OSPOS_DATA_DIR } else { $null }
$dataDirectoryArg = $null
$networkArg = $null
$waitForDocker = 0
$destinationArg = $null
$uploadsArg = $null
$envArg = $null
$configArg = $null
$configValue = $null
$configDestination = $null
$configDestinationName = $null
$configuredKeep = '7'
$configuredCopyTo = ''
$copyDirectory = $null
$destination = $null
$logFile = $null
$launcherFailureLogged = $false
$destinationSet = $false
$uploadsSet = $false
$envSet = $false
$dataDirectorySet = $false
$networkSet = $false
$waitForDockerSet = $false
$shopLockDirectory = $null
$shopLockOwned = $false

# Read one client setting without executing the config file.
function Read-ConfiguredValue {
    param([string]$Path, [string]$Key, [switch]$Required)

    $pattern = '^\s*' + [regex]::Escape($Key) + '\s*=\s*(.*)$'
    $matchingLines = @(Get-Content -LiteralPath $Path | Where-Object {
        $_ -match $pattern
    })
    if ($matchingLines.Count -gt 1) { throw "The config file contains more than one value for $Key." }
    if ($Required -and $matchingLines.Count -ne 1) { throw "The config file must contain one value for $Key." }
    if ($matchingLines.Count -eq 0) { return [pscustomobject]@{ Found = $false; Value = '' } }

    $line = [string]$matchingLines[0]
    if ($line -match $pattern) {
        $raw = [string]$Matches[1]
    } else {
        throw "Invalid $Key in $Path."
    }
    $raw = $raw.Trim()
    if ($raw.StartsWith("'")) {
        if (-not $raw.EndsWith("'")) { throw "Invalid $Key in $Path." }
        $value = $raw.Substring(1, $raw.Length - 2)
    } elseif ($raw.StartsWith('"')) {
        if (-not $raw.EndsWith('"')) { throw "Invalid $Key in $Path." }
        $value = $raw.Substring(1, $raw.Length - 2)
    } else {
        $value = ($raw -split '#', 2)[0].Trim()
    }
    return [pscustomobject]@{ Found = $true; Value = $value }
}

# Read and validate the client backup destination before choosing the log path.
function Read-ClientBackupDestination {
    $setting = Read-ConfiguredValue -Path $configArg -Key 'OSPOS_BACKUP_DESTINATION' -Required
    $script:configValue = $setting.Value
    if ([string]::IsNullOrWhiteSpace($configValue)) { throw 'OSPOS_BACKUP_DESTINATION must not be empty.' }
    if ([System.IO.Path]::IsPathRooted($configValue) -or $configValue -match '(^|[\\/])\.\.([\\/]|$)' -or $configValue -in @('.', '..')) {
        throw 'OSPOS_BACKUP_DESTINATION must be a safe path relative to the client directory.'
    }
    $script:configDestinationName = $configValue
    $script:configDestination = Join-Path $dataDirectory $configValue
}

# Read and validate client retention and copy settings after the log path is known.
function Read-ClientBackupOptions {
    $setting = Read-ConfiguredValue -Path $configArg -Key 'OSPOS_BACKUP_KEEP'
    if ($setting.Found) {
        if ($setting.Value -notmatch '^[0-9]+$') { throw 'OSPOS_BACKUP_KEEP must be a non-negative integer.' }
        $script:configuredKeep = $setting.Value
    }

    $setting = Read-ConfiguredValue -Path $configArg -Key 'OSPOS_BACKUP_COPY_TO'
    if ($setting.Found) {
        $script:configuredCopyTo = $setting.Value
        if ($configuredCopyTo -and $configuredCopyTo -notmatch '^[A-Za-z]:[\\/]' -and $configuredCopyTo -notmatch '^\\\\[^\\]+\\[^\\]+(?:\\.*)?$') {
            throw 'OSPOS_BACKUP_COPY_TO must be an absolute Windows path.'
        }
    }
}

# Append one host-side launcher failure line to the known backup log.
function Write-LauncherFailureLog {
    param([string]$Path, [string]$Reason)

    if ($script:launcherFailureLogged -or [string]::IsNullOrWhiteSpace($Path)) { return }
    $script:launcherFailureLogged = $true
    $message = ($Reason -replace '[^A-Za-z0-9_.:-]+', '_').Trim('_')
    if ($message.Length -gt 120) { $message = $message.Substring(0, 120) }
    if ([string]::IsNullOrWhiteSpace($message)) { $message = 'Launcher_failed' }
    $timestamp = [DateTime]::UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ")
    $line = "$timestamp result=failed archive=- size=- deleted=0 copy=- copy_deleted=0 message=$message`n"
    $encoding = [System.Text.UTF8Encoding]::new($false)
    try {
        [System.IO.File]::AppendAllText($Path, $line, $encoding)
    } catch {
        Write-Warning "Could not write the backup failure log: $Path"
    }
}

# Lock a client backup, record its owner, and report a held-lock failure.
function Enter-BackupLock {
    if ($null -eq $dataDirectory -or $env:OSPOS_SHOP_LOCK_HELD -eq '1') { return }
    $script:shopLockDirectory = Join-Path $dataDirectory '.shop-command.lock'
    try {
        New-Item -ItemType Directory -Path $shopLockDirectory -ErrorAction Stop | Out-Null
    } catch {
        $ownerPid = if (Test-Path -LiteralPath (Join-Path $shopLockDirectory 'pid')) { Get-Content -LiteralPath (Join-Path $shopLockDirectory 'pid') -Raw } else { 'unknown' }
        $ownerHost = if (Test-Path -LiteralPath (Join-Path $shopLockDirectory 'host')) { Get-Content -LiteralPath (Join-Path $shopLockDirectory 'host') -Raw } else { 'unknown' }
        $ownerCommand = if (Test-Path -LiteralPath (Join-Path $shopLockDirectory 'command')) { Get-Content -LiteralPath (Join-Path $shopLockDirectory 'command') -Raw } else { 'unknown' }
        $startedPath = Join-Path $shopLockDirectory 'started_epoch'
        $age = 'unknown'
        if (Test-Path -LiteralPath $startedPath) {
            $startedEpoch = Get-Content -LiteralPath $startedPath -Raw
            if ($startedEpoch -match '^[0-9]+$') { $age = [Math]::Max(0, [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() - [long]$startedEpoch).ToString() + 's' }
        }
        throw "Shop_lock_held_age_${age}_clear_stale_lock_with_shop_unlock_pid_${ownerPid}_host_${ownerHost}_command_${ownerCommand}"
    }
    $script:shopLockOwned = $true
    $started = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
    Set-Content -LiteralPath (Join-Path $shopLockDirectory 'pid') -Value $PID -NoNewline
    Set-Content -LiteralPath (Join-Path $shopLockDirectory 'host') -Value $env:COMPUTERNAME -NoNewline
    Set-Content -LiteralPath (Join-Path $shopLockDirectory 'command') -Value 'backup' -NoNewline
    Set-Content -LiteralPath (Join-Path $shopLockDirectory 'started') -Value $started -NoNewline
    Set-Content -LiteralPath (Join-Path $shopLockDirectory 'started_epoch') -Value ([DateTimeOffset]::UtcNow.ToUnixTimeSeconds()) -NoNewline
}

# Remove the standalone backup lock after the launcher exits.
function Exit-BackupLock {
    if (-not $shopLockOwned) { return }
    Remove-Item -LiteralPath (Join-Path $shopLockDirectory 'pid') -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $shopLockDirectory 'host') -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $shopLockDirectory 'command') -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $shopLockDirectory 'started') -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $shopLockDirectory 'started_epoch') -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $shopLockDirectory -Force -ErrorAction SilentlyContinue
    $script:shopLockOwned = $false
}

# Refuse backups while a restore or rollback may have left the database partial.
function Assert-BackupDatabaseSafe {
    if ($null -eq $dataDirectory) { return }
    if (Test-Path -LiteralPath (Join-Path $dataDirectory 'restore.unfinished')) {
        throw 'A restore is unfinished; backup and retention were not run because the database may be partial.'
    }
    if (Test-Path -LiteralPath (Join-Path $dataDirectory 'rollback.unfinished')) {
        throw 'A rollback is unfinished; backup and retention were not run because the database may be partial.'
    }
}

# Check Docker and wait in ten-second steps when a wait limit was given.
function Test-DockerAvailable {
    param([int]$WaitSeconds)

    $elapsed = 0
    while ($true) {
        try {
            & docker info *> $null
            if ($LASTEXITCODE -eq 0) { return $true }
        } catch {
            $null = $_
        }
        if ($elapsed -ge $WaitSeconds) { return $false }
        $pauseSeconds = [Math]::Min(10, $WaitSeconds - $elapsed)
        Start-Sleep -Seconds $pauseSeconds
        $elapsed += $pauseSeconds
    }
}

for ($index = 0; $index -lt $args.Count; $index++) {
    switch ($args[$index]) {
        '--help' {
            & docker run --rm --network $network `
                --mount "type=bind,source=$repoRoot,target=/work,readonly" `
                --entrypoint bash mariadb:10.5 /work/scripts/container/backup.sh --help --db-host $dbHost
            exit $LASTEXITCODE
        }
        '-h' {
            & docker run --rm --network $network `
                --mount "type=bind,source=$repoRoot,target=/work,readonly" `
                --entrypoint bash mariadb:10.5 /work/scripts/container/backup.sh --help --db-host $dbHost
            exit $LASTEXITCODE
        }
        '--destination' {
            if ($index + 1 -ge $args.Count) { throw '--destination needs a directory.' }
            if ($destinationSet) { throw '--destination was given more than once.' }
            $destinationArg = $args[++$index]
            $destinationSet = $true
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
        '--data-directory' {
            if ($index + 1 -ge $args.Count) { throw '--data-directory needs a directory.' }
            if ($dataDirectorySet) { throw '--data-directory was given more than once.' }
            $dataDirectoryArg = $args[++$index]
            $dataDirectorySet = $true
        }
        '--network' {
            if ($index + 1 -ge $args.Count) { throw '--network needs a name.' }
            if ($networkSet) { throw '--network was given more than once.' }
            $networkArg = $args[++$index]
            if ([string]::IsNullOrWhiteSpace($networkArg)) { throw '--network needs a name.' }
            $networkSet = $true
        }
        '--wait-for-docker' {
            if ($index + 1 -ge $args.Count) { throw '--wait-for-docker needs a non-negative number of seconds.' }
            if ($waitForDockerSet) { throw '--wait-for-docker was given more than once.' }
            $waitValue = [string]$args[++$index]
            if ($waitValue -notmatch '^[0-9]+$' -or $null -eq ($waitValue -as [int])) { throw '--wait-for-docker needs a non-negative number of seconds.' }
            $waitForDocker = [int]$waitValue
            $waitForDockerSet = $true
        }
        default { throw "Unknown option: $($args[$index])" }
    }
}

if ($dataDirectorySet) { $dataDirectory = $dataDirectoryArg }
if ($networkSet) { $network = $networkArg }
if ($null -ne $dataDirectory) {
    if (-not (Test-Path -LiteralPath $dataDirectory -PathType Container)) { throw "Client data directory does not exist: $dataDirectory" }
    $dataDirectory = (Resolve-Path -LiteralPath $dataDirectory).Path
    if ($null -eq $uploadsArg) { $uploadsArg = Join-Path $dataDirectory 'uploads' }
    if ($null -eq $envArg) { $envArg = Join-Path $dataDirectory 'secrets\app.env' }
    $configArg = Join-Path $dataDirectory 'ospos.conf'
} else {
    if (-not $destinationSet) { throw '--destination is required unless OSPOS_DATA_DIR is set.' }
    if ($null -eq $uploadsArg) { $uploadsArg = Join-Path $repoRoot 'public\uploads' }
    if ($null -eq $envArg) { $envArg = Join-Path $repoRoot '.env' }
}
if (-not $destinationSet -and $null -eq $dataDirectory) { throw '--destination is required unless OSPOS_DATA_DIR is set.' }
if ($null -ne $dataDirectory) {
    # Until ospos.conf is read, log config errors to the default client backups folder when it exists.
    $defaultBackups = Join-Path $dataDirectory 'backups'
    if (Test-Path -LiteralPath $defaultBackups -PathType Container) { $logFile = Join-Path $defaultBackups 'backup.log' }
    try {
        if (-not (Test-Path -LiteralPath $configArg -PathType Leaf)) { throw "Config file does not exist: $configArg" }
        Read-ClientBackupDestination
        if (-not (Test-Path -LiteralPath $configDestination -PathType Container)) { throw "Configured backup destination does not exist: $configDestination" }
    } catch {
        Write-LauncherFailureLog -Path $logFile -Reason $_.Exception.Message
        throw
    }
    $configDestination = (Resolve-Path -LiteralPath $configDestination).Path
    $destination = $configDestination
    $logFile = Join-Path $configDestination 'backup.log'
} else {
    if (-not (Test-Path -LiteralPath $destinationArg -PathType Container)) { throw "Destination directory does not exist: $destinationArg" }
    $destination = (Resolve-Path -LiteralPath $destinationArg).Path
    $logFile = Join-Path $destination 'backup.log'
}

try {
    Enter-BackupLock
    Assert-BackupDatabaseSafe
    if ($null -ne $dataDirectory) { Read-ClientBackupOptions }
    if ($destinationSet) {
        if (-not (Test-Path -LiteralPath $destinationArg -PathType Container)) { throw "Destination directory does not exist: $destinationArg" }
        $destination = (Resolve-Path -LiteralPath $destinationArg).Path
    }
    if (-not [System.IO.Path]::IsPathRooted($uploadsArg)) { $uploadsArg = Join-Path $repoRoot $uploadsArg }
    if (-not (Test-Path -LiteralPath $uploadsArg -PathType Container)) { throw "Uploads directory does not exist: $uploadsArg" }
    if (-not (Test-Path -LiteralPath $envArg -PathType Leaf)) { throw "Environment file does not exist: $envArg" }
    $uploads = (Resolve-Path -LiteralPath $uploadsArg).Path
    $uploadsParent = [System.IO.Path]::GetDirectoryName($uploads)
    $uploadsName = [System.IO.Path]::GetFileName($uploads)
    $envFile = (Resolve-Path -LiteralPath $envArg).Path

    $dockerArgs = @(
        'run', '--rm', '--network', $network,
        '--mount', "type=bind,source=$repoRoot,target=/work,readonly",
        '--mount', "type=bind,source=$uploadsParent,target=/uploads-parent,readonly",
        '--mount', "type=bind,source=$envFile,target=/client.env,readonly"
    )
    if ($destinationSet) { $dockerArgs += @('--mount', "type=bind,source=$destination,target=/destination") }
    if ($null -ne $dataDirectory -and -not $destinationSet) {
        $dockerArgs += @(
            '--mount', "type=bind,source=$configArg,target=/client/ospos.conf,readonly",
            '--mount', "type=bind,source=$configDestination,target=/client/$configDestinationName"
        )
    }
    if ($null -ne $dataDirectory -and $destinationSet) {
        $dockerArgs += @('--mount', "type=bind,source=$configDestination,target=/log-destination")
    }
    if ($null -ne $dataDirectory -and $configuredCopyTo -and (Test-Path -LiteralPath $configuredCopyTo -PathType Container)) {
        $copyDirectory = (Resolve-Path -LiteralPath $configuredCopyTo).Path
        $dockerArgs += @('--mount', "type=bind,source=$copyDirectory,target=/copy")
    }
    $dockerArgs += @('--entrypoint', 'bash', 'mariadb:10.5', '/work/scripts/container/backup.sh')
    if ($destinationSet) {
        $dockerArgs += @('--destination', '/destination')
        if ($null -ne $dataDirectory) { $dockerArgs += @('--log', '/log-destination/backup.log') }
    } else {
        $dockerArgs += @('--config', '/client/ospos.conf')
    }
    $dockerArgs += @('--uploads', "/uploads-parent/$uploadsName", '--env', '/client.env', '--db-host', $dbHost)
    if ($null -ne $dataDirectory) {
        $dockerArgs += @('--keep', $configuredKeep)
        if ($configuredCopyTo) {
            if ($null -ne $copyDirectory) {
                $dockerArgs += @('--copy-to', '/copy')
            } else {
                $dockerArgs += @('--copy-missing', $configuredCopyTo)
            }
        }
    }

    if (-not (Test-DockerAvailable -WaitSeconds $waitForDocker)) { throw 'Docker_is_not_running' }

    # Rewrite mounted container paths while keeping Docker's exit status.
    & docker @dockerArgs | ForEach-Object {
        $line = [string]$_
        if ($line.StartsWith('/destination/')) {
            Write-Output (Join-Path -Path $destination -ChildPath $line.Substring('/destination/'.Length))
        } elseif ($line.StartsWith('/copy/')) {
            Write-Output (Join-Path -Path $copyDirectory -ChildPath $line.Substring('/copy/'.Length))
        } elseif ($line.StartsWith('/client/') -and $null -ne $dataDirectory) {
            Write-Output (Join-Path -Path $dataDirectory -ChildPath $line.Substring('/client/'.Length))
        } else {
            Write-Output $_
        }
    }
    $dockerExitCode = $LASTEXITCODE
    if ($dockerExitCode -ne 0 -and $dockerExitCode -ne 1) {
        Write-LauncherFailureLog -Path $logFile -Reason "Backup_container_failed_exit_$dockerExitCode"
    }
    exit $dockerExitCode
} catch {
    Write-LauncherFailureLog -Path $logFile -Reason $_.Exception.Message
    throw
} finally {
    Exit-BackupLock
}
