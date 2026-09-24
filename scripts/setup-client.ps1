$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$dataArg = $null
$lockedDirectory = $false

# Print the Windows setup command help.
function Show-Help {
    @'
Usage: scripts/setup-client.ps1 --data-directory <directory> [--locked-directory]

Create a new client configuration directory.

The directory must not already exist unless --locked-directory is used while the shop command lock is held.
Docker and Windows built-in ACL tools are the only host dependencies.
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
        '--locked-directory' {
            if ($lockedDirectory) { throw '--locked-directory was given more than once.' }
            $lockedDirectory = $true
        }
        default { throw "Unknown option: $($args[$index])" }
    }
}

if ($null -eq $dataArg) { throw '--data-directory is required.' }
$dataDirectory = [System.IO.Path]::GetFullPath($dataArg).TrimEnd('\', '/')
$dataParent = [System.IO.Path]::GetDirectoryName($dataDirectory)
$dataName = [System.IO.Path]::GetFileName($dataDirectory)
if ([string]::IsNullOrWhiteSpace($dataName) -or $dataName -in @('.', '..')) { throw "Invalid data directory: $dataArg" }
New-Item -ItemType Directory -Force -Path $dataParent | Out-Null

$secretsDirectory = Join-Path $dataDirectory 'secrets'
$targetCreated = $false
$secretsCreated = $false

try {
    if ($lockedDirectory) {
        $lockDirectory = Join-Path $dataDirectory '.shop-command.lock'
        $entries = @(Get-ChildItem -LiteralPath $dataDirectory -Force -ErrorAction Stop)
        if (-not (Test-Path -LiteralPath $lockDirectory -PathType Container) -or
            -not (Test-Path -LiteralPath (Join-Path $lockDirectory 'pid') -PathType Leaf) -or
            $entries.Count -ne 1 -or $entries[0].Name -ne '.shop-command.lock') {
            throw "The client directory must contain only an active shop command lock: $dataDirectory"
        }
    } elseif ($null -ne (Get-Item -LiteralPath $dataDirectory -Force -ErrorAction SilentlyContinue)) {
        throw "Refusing to run against an existing installation: $dataDirectory"
    }

    if (-not $lockedDirectory) {
        New-Item -ItemType Directory -Path $dataDirectory -ErrorAction Stop | Out-Null
        $targetCreated = $true
    }
    New-Item -ItemType Directory -Path $secretsDirectory -ErrorAction Stop | Out-Null
    $secretsCreated = $true

    $driveRoot = [System.IO.Path]::GetPathRoot($dataDirectory)
    if ([string]::IsNullOrWhiteSpace($driveRoot)) { throw "Could not determine the filesystem for $dataDirectory." }
    $driveLetter = $driveRoot.Substring(0, 1)
    $volume = Get-Volume -DriveLetter $driveLetter -ErrorAction Stop
    if ($null -eq $volume) { throw "Could not determine the filesystem for $dataDirectory." }
    $fileSystem = $volume.FileSystem

    if ($fileSystem -eq 'NTFS') {
        $account = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
        & icacls $secretsDirectory /inheritance:r /grant:r "$account`:(OI)(CI)F" /T /C
        if ($LASTEXITCODE -ne 0) { throw "icacls could not protect $secretsDirectory." }
        Write-Output "Protected secrets for $account on NTFS before secret creation."
    } elseif ($fileSystem -in @('exFAT', 'FAT32')) {
        throw "The secrets directory is on $fileSystem. This file system has no file permissions, so setup cannot protect the secrets."
    } else {
        throw "The secrets directory is on $fileSystem. Setup cannot protect the secrets because this file system was not recognized as NTFS."
    }

    $dockerArgs = @(
        'run', '--rm',
        '--mount', "type=bind,source=$repoRoot,target=/work,readonly",
        '--mount', "type=bind,source=$dataParent,target=/client-parent",
        '--entrypoint', 'bash', 'mariadb:10.5',
        '/work/scripts/container/setup-client.sh',
        '--data-directory', "/client-parent/$dataName",
        '--host-data-directory', $dataDirectory,
        '--platform', 'windows',
        '--prepared-directory'
    )
    if ($lockedDirectory) { $dockerArgs += '--locked-directory' }

    & docker @dockerArgs
    if ($LASTEXITCODE -ne 0) { throw "Docker setup failed with exit code $LASTEXITCODE." }
} catch {
    $failure = $_
    if ($targetCreated) {
        try {
            Remove-Item -LiteralPath $dataDirectory -Recurse -Force -ErrorAction Stop
        } catch {
            throw "Setup failed and the new target could not be removed: $dataDirectory. $($_.Exception.Message)"
        }
    } elseif ($secretsCreated) {
        try {
            Remove-Item -LiteralPath $secretsDirectory -Recurse -Force -ErrorAction Stop
        } catch {
            throw "Setup failed and the new secrets directory could not be removed: $secretsDirectory. $($_.Exception.Message)"
        }
    }
    throw $failure
}
