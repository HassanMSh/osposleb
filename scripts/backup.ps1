$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$destinationArg = $null
$uploadsArg = Join-Path $repoRoot 'public\uploads'
$envArg = Join-Path $repoRoot '.env'
$destinationSet = $false
$uploadsSet = $false
$envSet = $false

for ($index = 0; $index -lt $args.Count; $index++) {
    switch ($args[$index]) {
        '--help' {
            $helpNetwork = if ($env:OSPOS_DOCKER_NETWORK) { $env:OSPOS_DOCKER_NETWORK } else { 'ospos_app_net' }
            & docker run --rm --network $helpNetwork `
                --mount "type=bind,source=$repoRoot,target=/work,readonly" `
                --entrypoint bash mariadb:10.5 /work/scripts/container/backup.sh --help
            exit $LASTEXITCODE
        }
        '-h' {
            $helpNetwork = if ($env:OSPOS_DOCKER_NETWORK) { $env:OSPOS_DOCKER_NETWORK } else { 'ospos_app_net' }
            & docker run --rm --network $helpNetwork `
                --mount "type=bind,source=$repoRoot,target=/work,readonly" `
                --entrypoint bash mariadb:10.5 /work/scripts/container/backup.sh --help
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
$network = if ($env:OSPOS_DOCKER_NETWORK) { $env:OSPOS_DOCKER_NETWORK } else { 'ospos_app_net' }

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
    '--db-host', 'mysql'
)

& docker @dockerArgs
exit $LASTEXITCODE
