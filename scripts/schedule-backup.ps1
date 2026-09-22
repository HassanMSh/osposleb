# Usage: .\scripts\schedule-backup.ps1 --time HH:MM [--data-directory <dir>]
# Use --remove to delete the daily task.
$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$backupScript = Join-Path $repoRoot 'scripts\backup.ps1'
$taskName = 'OSPOS daily backup'
$timeValue = $null
$dataDirectory = if ($env:OSPOS_DATA_DIR) { $env:OSPOS_DATA_DIR } else { $null }
$removeTask = $false
$timeSet = $false
$dataDirectorySet = $false
$removeSet = $false

# Print the required scheduling commands.
function Show-Usage {
    Write-Output 'Use .\scripts\schedule-backup.ps1 --time HH:MM [--data-directory <dir>] or --remove.'
}

for ($index = 0; $index -lt $args.Count; $index++) {
    switch ($args[$index]) {
        '--help' {
            Show-Usage
            exit 0
        }
        '--time' {
            if ($index + 1 -ge $args.Count) { throw '--time needs a time in HH:MM format.' }
            if ($timeSet) { throw '--time was given more than once.' }
            $timeValue = [string]$args[++$index]
            $timeSet = $true
        }
        '--data-directory' {
            if ($index + 1 -ge $args.Count) { throw '--data-directory needs a directory.' }
            if ($dataDirectorySet) { throw '--data-directory was given more than once.' }
            $dataDirectory = [string]$args[++$index]
            $dataDirectorySet = $true
        }
        '--remove' {
            if ($removeSet) { throw '--remove was given more than once.' }
            $removeTask = $true
            $removeSet = $true
        }
        default { throw "Unknown option: $($args[$index])" }
    }
}

if ($timeSet -eq $removeSet) {
    Show-Usage
    throw 'Choose exactly one action: --time HH:MM or --remove.'
}
if ($timeSet -and $timeValue -notmatch '^([01][0-9]|2[0-3]):[0-5][0-9]$') {
    Show-Usage
    throw 'The time must use 24-hour HH:MM format, for example 23:30.'
}

if ($removeTask) {
    $existingTask = $null
    try {
        $existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    } catch {
        $existingTask = $null
    }
    if ($null -eq $existingTask) {
        Write-Output "The task '$taskName' does not exist."
        exit 0
    }
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    Write-Output "Removed the task '$taskName'."
    exit 0
}

if ([string]::IsNullOrWhiteSpace($dataDirectory)) {
    Show-Usage
    throw 'Set OSPOS_DATA_DIR or pass --data-directory before scheduling a backup.'
}
if (-not (Test-Path -LiteralPath $dataDirectory -PathType Container)) {
    throw "Client data directory does not exist: $dataDirectory"
}
$dataDirectory = (Resolve-Path -LiteralPath $dataDirectory).Path
if (-not (Test-Path -LiteralPath (Join-Path $dataDirectory 'ospos.conf') -PathType Leaf)) {
    throw "Client data directory has no ospos.conf: $dataDirectory"
}

$scheduledAt = [DateTime]::ParseExact($timeValue, 'HH:mm', [Globalization.CultureInfo]::InvariantCulture)
$quotedBackupScript = '"{0}"' -f $backupScript
$quotedDataDirectory = '"{0}"' -f $dataDirectory
$actionArguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File {0} --data-directory {1} --wait-for-docker 300' -f $quotedBackupScript, $quotedDataDirectory
if ($env:OSPOS_DOCKER_NETWORK) {
    $quotedNetwork = '"{0}"' -f $env:OSPOS_DOCKER_NETWORK
    $actionArguments += " --network $quotedNetwork"
}

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $actionArguments
$trigger = New-ScheduledTaskTrigger -Daily -At $scheduledAt
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Hours 1) -MultipleInstances IgnoreNew
$currentUser = [Environment]::UserName
try {
    $currentUser = [Security.Principal.WindowsIdentity]::GetCurrent().Name
} catch {
    $currentUser = [Environment]::UserName
}
$principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force

Write-Output "Scheduled '$taskName' every day at $timeValue."
Write-Output 'Run this script with a new --time HH:MM value to change the time.'
Write-Output 'Run this script with --remove to delete the task.'
