$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$projectName = if ($env:COMPOSE_PROJECT_NAME) { $env:COMPOSE_PROJECT_NAME } else { Split-Path -Leaf $repoRoot }
$network = if ($env:OSPOS_DOCKER_NETWORK) { $env:OSPOS_DOCKER_NETWORK } else { $projectName + '_app_net' }
$dbHost = if ($env:OSPOS_DB_HOST) { $env:OSPOS_DB_HOST } else { 'mysql' }
$archiveArg = $null
$uploadsArg = Join-Path $repoRoot 'public\uploads'
$databaseArg = $null
$yesFlag = $false
$archiveSet = $false
$uploadsSet = $false
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
if (-not [System.IO.Path]::IsPathRooted($uploadsArg)) { $uploadsArg = Join-Path $repoRoot $uploadsArg }
if (-not (Test-Path -LiteralPath $archiveArg -PathType Leaf)) { throw "Archive file does not exist: $archiveArg" }
if (-not (Test-Path -LiteralPath $uploadsArg -PathType Container)) { throw "Uploads directory does not exist: $uploadsArg" }
if (-not (Test-Path -LiteralPath (Join-Path $repoRoot '.env') -PathType Leaf)) { throw "Environment file does not exist: $(Join-Path $repoRoot '.env')" }

$archive = (Resolve-Path -LiteralPath $archiveArg).Path
$archiveParent = Split-Path -LiteralPath $archive -Parent
$archiveName = Split-Path -LiteralPath $archive -Leaf
$uploads = (Resolve-Path -LiteralPath $uploadsArg).Path
$uploadsParent = Split-Path -LiteralPath $uploads -Parent
$uploadsName = Split-Path -LiteralPath $uploads -Leaf

$dockerArgs = @(
    'run', '--rm', '--network', $network,
    '--mount', "type=bind,source=$repoRoot,target=/work,readonly",
    '--mount', "type=bind,source=$archiveParent,target=/archive-parent,readonly",
    '--mount', "type=bind,source=$uploadsParent,target=/uploads-parent",
    '--entrypoint', 'bash', 'mariadb:10.5',
    '/work/scripts/container/restore.sh',
    '--archive', "/archive-parent/$archiveName",
    '--uploads', "/uploads-parent/$uploadsName",
    '--db-host', $dbHost
)

if ($databaseSet) { $dockerArgs += @('--database', $databaseArg) }
if ($yesFlag) { $dockerArgs += '--yes' }

# Rewrite the container uploads path in streamed success output while preserving the Docker status.
& docker @dockerArgs | ForEach-Object {
    $line = [string]$_
    if ($line -like 'Restored uploads: /uploads-parent/*') {
        Write-Output "Restored uploads: $uploads"
    } else {
        Write-Output $_
    }
}
$dockerExitCode = $LASTEXITCODE
exit $dockerExitCode
