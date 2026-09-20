$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$projectName = if ($env:COMPOSE_PROJECT_NAME) { $env:COMPOSE_PROJECT_NAME } else { Split-Path -Leaf $repoRoot }
$network = if ($env:OSPOS_DOCKER_NETWORK) { $env:OSPOS_DOCKER_NETWORK } else { $projectName + '_app_net' }
$dbHost = if ($env:OSPOS_DB_HOST) { $env:OSPOS_DB_HOST } else { 'mysql' }
$dataDirectory = if ($env:OSPOS_DATA_DIR) { $env:OSPOS_DATA_DIR } else { $null }
$destinationArg = $null
$uploadsArg = $null
$envArg = $null
$configArg = $null
$configValue = $null
$configDestination = $null
$destinationSet = $false
$uploadsSet = $false
$envSet = $false

# Read the client backup destination without executing the config file.
function Read-ConfiguredDestination {
    param([string]$Path)

    $matchingLines = @(Get-Content -LiteralPath $Path | Where-Object {
        $_ -match '^\s*OSPOS_BACKUP_DESTINATION\s*=\s*(.*)$'
    })
    if ($matchingLines.Count -ne 1) { throw "The config file must contain one value for OSPOS_BACKUP_DESTINATION." }

    $line = [string]$matchingLines[0]
    if ($line -match '^\s*OSPOS_BACKUP_DESTINATION\s*=\s*(.*)$') {
        $raw = [string]$Matches[1]
    } else {
        throw "Invalid OSPOS_BACKUP_DESTINATION in $Path."
    }
    $raw = $raw.Trim()
    if ($raw.StartsWith("'")) {
        if (-not $raw.EndsWith("'")) { throw "Invalid OSPOS_BACKUP_DESTINATION in $Path." }
        return $raw.Substring(1, $raw.Length - 2)
    }
    if ($raw.StartsWith('"')) {
        if (-not $raw.EndsWith('"')) { throw "Invalid OSPOS_BACKUP_DESTINATION in $Path." }
        return $raw.Substring(1, $raw.Length - 2)
    }
    return ($raw -split '#', 2)[0].Trim()
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
        default { throw "Unknown option: $($args[$index])" }
    }
}

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
if (-not [System.IO.Path]::IsPathRooted($uploadsArg)) { $uploadsArg = Join-Path $repoRoot $uploadsArg }
if (-not (Test-Path -LiteralPath $uploadsArg -PathType Container)) { throw "Uploads directory does not exist: $uploadsArg" }
if (-not (Test-Path -LiteralPath $envArg -PathType Leaf)) { throw "Environment file does not exist: $envArg" }
if (-not $destinationSet) {
    if (-not (Test-Path -LiteralPath $configArg -PathType Leaf)) { throw "Config file does not exist: $configArg" }
    $configValue = Read-ConfiguredDestination -Path $configArg
    if ([string]::IsNullOrWhiteSpace($configValue)) { throw 'OSPOS_BACKUP_DESTINATION must not be empty.' }
    if ([System.IO.Path]::IsPathRooted($configValue) -or $configValue -match '(^|[\\/])\.\.([\\/]|$)' -or $configValue -in @('.', '..')) {
        throw 'OSPOS_BACKUP_DESTINATION contains an unsafe path.'
    }
    $configDestination = Join-Path $dataDirectory $configValue
    if (-not (Test-Path -LiteralPath $configDestination -PathType Container)) { throw "Configured backup destination does not exist: $configDestination" }
    $configDestination = (Resolve-Path -LiteralPath $configDestination).Path
}
if ($destinationSet -and -not (Test-Path -LiteralPath $destinationArg -PathType Container)) { throw "Destination directory does not exist: $destinationArg" }

if ($destinationSet) { $destination = (Resolve-Path -LiteralPath $destinationArg).Path }
$uploads = (Resolve-Path -LiteralPath $uploadsArg).Path
$uploadsParent = Split-Path -LiteralPath $uploads -Parent
$uploadsName = Split-Path -LiteralPath $uploads -Leaf
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
        '--mount', "type=bind,source=$configDestination,target=/client/$configValue"
    )
}
$dockerArgs += @('--entrypoint', 'bash', 'mariadb:10.5', '/work/scripts/container/backup.sh')
if ($destinationSet) { $dockerArgs += @('--destination', '/destination') } else { $dockerArgs += @('--config', '/client/ospos.conf') }
$dockerArgs += @('--uploads', "/uploads-parent/$uploadsName", '--env', '/client.env', '--db-host', $dbHost)

# Rewrite the container destination in streamed success output while preserving the Docker status.
& docker @dockerArgs | ForEach-Object {
    $line = [string]$_
    if ($line.StartsWith('/destination/')) {
        Write-Output (Join-Path -Path $destination -ChildPath $line.Substring('/destination/'.Length))
    } elseif ($line.StartsWith('/client/') -and $null -ne $dataDirectory) {
        Write-Output (Join-Path -Path $dataDirectory -ChildPath $line.Substring('/client/'.Length))
    } else {
        Write-Output $_
    }
}
$dockerExitCode = $LASTEXITCODE
exit $dockerExitCode
