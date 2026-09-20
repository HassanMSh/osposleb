$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$dataArg = $null

# Print the Windows setup command help.
function Show-Help {
    @'
Usage: scripts/setup-client.ps1 --data-directory <directory>

Create a new client configuration directory.

The directory must not already exist. Docker and Windows built-in ACL tools are the only host dependencies.
'@ | Write-Output
}

for ($index = 0; $index -lt $args.Count; $index++) {
    switch ($args[$index]) {
        '--help' { Show-Help; exit 0 }
        '-h' { Show-Help; exit 0 }
        '--data-directory' {
            if ($index + 1 -ge $args.Count) { throw '--data-directory needs a directory.' }
            if ($null -ne $dataArg) { throw '--data-directory was given more than once.' }
            $dataArg = $args[++$index]
        }
        default { throw "Unknown option: $($args[$index])" }
    }
}

if ($null -eq $dataArg) { throw '--data-directory is required.' }
$dataDirectory = [System.IO.Path]::GetFullPath($dataArg)
$dataParent = Split-Path -LiteralPath $dataDirectory -Parent
$dataName = Split-Path -LiteralPath $dataDirectory -Leaf
if ([string]::IsNullOrWhiteSpace($dataName) -or $dataName -in @('.', '..')) { throw "Invalid data directory: $dataArg" }
New-Item -ItemType Directory -Force -Path $dataParent | Out-Null

$dockerArgs = @(
    'run', '--rm',
    '--mount', "type=bind,source=$repoRoot,target=/work,readonly",
    '--mount', "type=bind,source=$dataParent,target=/client-parent",
    '--entrypoint', 'bash', 'mariadb:10.5',
    '/work/scripts/container/setup-client.sh',
    '--data-directory', "/client-parent/$dataName",
    '--host-data-directory', $dataDirectory,
    '--platform', 'windows'
)

& docker @dockerArgs
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

$secretsDirectory = Join-Path $dataDirectory 'secrets'
$driveRoot = [System.IO.Path]::GetPathRoot($dataDirectory)
$driveLetter = $driveRoot.Substring(0, 1)
$fileSystem = (Get-Volume -DriveLetter $driveLetter).FileSystem

if ($fileSystem -eq 'NTFS') {
    $account = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    & icacls $secretsDirectory /inheritance:r /grant:r "$account`:(OI)(CI)F" /T /C
    if ($LASTEXITCODE -ne 0) { throw "icacls could not protect $secretsDirectory." }
    Write-Output "Protected secrets for $account on NTFS."
} elseif ($fileSystem -in @('exFAT', 'FAT32')) {
    Write-Warning "The secrets directory is on $fileSystem. This file system has no file permissions, so the secrets are not protected."
} else {
    Write-Warning "The secrets directory is on $fileSystem. Protection was not applied because this file system was not recognized as NTFS."
}
