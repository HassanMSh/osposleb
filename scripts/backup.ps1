$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$projectName = if ($env:COMPOSE_PROJECT_NAME) { $env:COMPOSE_PROJECT_NAME } else { Split-Path -Leaf $repoRoot }
$network = if ($env:OSPOS_DOCKER_NETWORK) { $env:OSPOS_DOCKER_NETWORK } else { $projectName + '_app_net' }
$dbHost = if ($env:OSPOS_DB_HOST) { $env:OSPOS_DB_HOST } else { 'mysql' }
$destinationArg = $null
$uploadsArg = Join-Path $repoRoot 'public\uploads'
$envArg = Join-Path $repoRoot '.env'
$destinationSet = $false
$uploadsSet = $false
$envSet = $false

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

if (-not $destinationSet) { throw '--destination is required.' }
if (-not [System.IO.Path]::IsPathRooted($uploadsArg)) { $uploadsArg = Join-Path $repoRoot $uploadsArg }
if (-not (Test-Path -LiteralPath $destinationArg -PathType Container)) { throw "Destination directory does not exist: $destinationArg" }
if (-not (Test-Path -LiteralPath $uploadsArg -PathType Container)) { throw "Uploads directory does not exist: $uploadsArg" }
if (-not (Test-Path -LiteralPath $envArg -PathType Leaf)) { throw "Environment file does not exist: $envArg" }

$destination = (Resolve-Path -LiteralPath $destinationArg).Path
$uploads = (Resolve-Path -LiteralPath $uploadsArg).Path
$uploadsParent = Split-Path -LiteralPath $uploads -Parent
$uploadsName = Split-Path -LiteralPath $uploads -Leaf
$envFile = (Resolve-Path -LiteralPath $envArg).Path
$envParent = Split-Path -LiteralPath $envFile -Parent
$envName = Split-Path -LiteralPath $envFile -Leaf

$dockerArgs = @(
    'run', '--rm', '--network', $network,
    '--mount', "type=bind,source=$repoRoot,target=/work,readonly",
    '--mount', "type=bind,source=$destination,target=/destination",
    '--mount', "type=bind,source=$uploadsParent,target=/uploads-parent,readonly",
    '--mount', "type=bind,source=$envParent,target=/env-parent,readonly",
    '--entrypoint', 'bash', 'mariadb:10.5',
    '/work/scripts/container/backup.sh',
    '--destination', '/destination',
    '--uploads', "/uploads-parent/$uploadsName",
    '--env', "/env-parent/$envName",
    '--db-host', $dbHost
)

# Rewrite the container destination in streamed success output while preserving the Docker status.
& docker @dockerArgs | ForEach-Object {
    $line = [string]$_
    if ($line.StartsWith('/destination/')) {
        Write-Output (Join-Path -Path $destination -ChildPath $line.Substring('/destination/'.Length))
    } else {
        Write-Output $_
    }
}
$dockerExitCode = $LASTEXITCODE
exit $dockerExitCode
