# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later

# Start menu: "Back Up Field Day Commander". Makes a backup while the server
# keeps running, then offers to show it in File Explorer.

. "$PSScriptRoot\common.ps1"

Assert-Administrator $PSCommandPath

$result = Invoke-ArtisanJson 'Backing up Field Day Commander...' @('backup:create', '--json')

if (-not $result.ok) {
    Show-Message "The backup could not be saved.`n`n$($result.error)" 'Error' | Out-Null
    exit 1
}

$answer = Show-Message (
    "Backup saved:`n`n$(Split-Path -Leaf $result.path)`n`n" +
    "Copy this file to a USB stick or another computer in case this computer fails.`n`n" +
    "Show it in File Explorer now?"
) 'Information' 'YesNo'

if ($answer -eq 'Yes') {
    Start-Process -FilePath 'explorer.exe' -ArgumentList "/select,`"$($result.path)`""
}
