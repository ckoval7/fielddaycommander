# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later

# Start menu: "Restore Field Day Commander from a Backup". Asks for a backup
# file, shows what is in it, then stops the server, restores and starts it
# again. The data being replaced is backed up first.

. "$PSScriptRoot\common.ps1"

Assert-Administrator $PSCommandPath

$picker = New-Object System.Windows.Forms.OpenFileDialog
$picker.Title = 'Choose a Field Day Commander backup'
$picker.Filter = 'Field Day Commander backups (*.zip)|*.zip'
if (Test-Path $BackupDir) {
    $picker.InitialDirectory = $BackupDir
}

if ($picker.ShowDialog() -ne 'OK') {
    exit
}

$file = $picker.FileName
$check = Invoke-ArtisanJson 'Checking the backup...' @('backup:restore', $file, '--inspect', '--json')

if (-not $check.ok) {
    Show-Message "This backup can't be restored.`n`n$($check.error)" 'Error' | Out-Null
    exit 1
}

$backup = $check.backup
$eventName = if ($backup.summary.event) { $backup.summary.event } else { 'none' }
$photos = if ($backup.includes_files) {
    'included'
} else {
    'not included (photos and files on this computer will be kept)'
}

$answer = Show-Message (
    "Restore this backup?`n`n" +
    "Created: $(Format-BackupTime $backup.created_at)`n" +
    "Event: $eventName`n" +
    "Contacts: $('{0:N0}' -f [int] $backup.summary.contacts)`n" +
    "Photos and files: $photos`n`n" +
    "This backup will replace the data and settings on this server. " +
    "Your current data will be saved in a `"backup before restore`" file. Restore that file to undo this.`n`n" +
    "The server will stop for a minute or two during the restore. " +
    "Nobody can log contacts during that time."
) 'Warning' 'YesNo'

if ($answer -ne 'Yes') {
    exit
}

try {
    Stop-FieldDayCommander
    $result = Invoke-ArtisanJson 'Restoring Field Day Commander...' @('backup:restore', $file, '--force', '--json')
} finally {
    Start-FieldDayCommander
}

if (-not $result.ok) {
    Show-Message (
        "The restore failed. The server has been started again.`n`n$($result.error)"
    ) 'Error' | Out-Null
    exit 1
}

Show-Message (
    "Backup restored. The server is running again.`n`n" +
    "To undo this restore, restore this file:`n$(Split-Path -Leaf $result.safety_backup)"
) | Out-Null
