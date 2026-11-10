# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later

# Shared helpers for the Start menu backup and restore tools. Dot-source it.
#
# The installer can write install-paths.ps1 next to this file to override
# any of the paths below.

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()

$ProductName = 'Field Day Commander'
$InstallDir = Split-Path -Parent $PSScriptRoot
$Php = Join-Path $InstallDir 'php\php.exe'
$Artisan = Join-Path $InstallDir 'app\artisan'
$BackupDir = Join-Path $env:PUBLIC 'Documents\Field Day Commander Backups'

# Every service that opens the database, in the order they start.
$Services = @(
    'FieldDayCommanderWeb',
    'FieldDayCommanderReverb',
    'FieldDayCommanderQueue',
    'FieldDayCommanderScheduler'
)

$installPaths = Join-Path $PSScriptRoot 'install-paths.ps1'
if (Test-Path $installPaths) {
    . $installPaths
}

# Relaunch the calling script as administrator. The database and services
# belong to the system, so both tools need it. Exits the current copy.
function Assert-Administrator([string] $ScriptPath) {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)

    if ($principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        return
    }

    try {
        Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden', '-File', "`"$ScriptPath`""
        )
    } catch {
        # The person said No to the Windows prompt. Nothing to do.
    }

    exit
}

function Show-Message([string] $Text, [string] $Icon = 'Information', [string] $Buttons = 'OK') {
    return [System.Windows.Forms.MessageBox]::Show($Text, $ProductName, $Buttons, $Icon)
}

# Run an artisan command that prints one line of JSON, showing a small
# "working" window until it finishes. Returns the parsed JSON, or an object
# with ok = false and the error text.
function Invoke-ArtisanJson([string] $Message, [string[]] $Arguments) {
    $quoted = @($Artisan) + $Arguments | ForEach-Object { '"' + ($_ -replace '"', '\"') + '"' }

    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $Php
    $startInfo.Arguments = $quoted -join ' '
    $startInfo.WorkingDirectory = Split-Path -Parent $Artisan
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true

    $form = New-Object System.Windows.Forms.Form
    $form.Text = $ProductName
    $form.FormBorderStyle = 'FixedDialog'
    $form.ControlBox = $false
    $form.StartPosition = 'CenterScreen'
    $form.TopMost = $true
    $form.ClientSize = New-Object System.Drawing.Size(360, 90)

    $label = New-Object System.Windows.Forms.Label
    $label.Text = $Message
    $label.AutoSize = $false
    $label.SetBounds(16, 14, 328, 24)
    $form.Controls.Add($label)

    $bar = New-Object System.Windows.Forms.ProgressBar
    $bar.Style = 'Marquee'
    $bar.SetBounds(16, 48, 328, 18)
    $form.Controls.Add($bar)

    $form.Show()

    try {
        $process = [System.Diagnostics.Process]::Start($startInfo)
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()

        while (-not $process.HasExited) {
            [System.Windows.Forms.Application]::DoEvents()
            Start-Sleep -Milliseconds 100
        }

        $process.WaitForExit()
        $output = $stdout.Result
        $errors = $stderr.Result
    } finally {
        $form.Close()
        $form.Dispose()
    }

    $json = ($output -split "`r?`n") | Where-Object { $_.StartsWith('{') } | Select-Object -Last 1

    if ($json) {
        return $json | ConvertFrom-Json
    }

    $detail = (($output + "`n" + $errors).Trim() -split "`r?`n") | Select-Object -Last 5

    return [pscustomobject]@{ ok = $false; error = ($detail -join "`n") }
}

function Stop-FieldDayCommander {
    [array]::Reverse($Services)
    foreach ($name in $Services) {
        if (Get-Service -Name $name -ErrorAction SilentlyContinue) {
            Stop-Service -Name $name -Force -ErrorAction SilentlyContinue
        }
    }
    [array]::Reverse($Services)

    # Logger listeners run on their own and keep the database open.
    Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" |
        Where-Object { $_.CommandLine -like '*artisan*external-logger:*' } |
        ForEach-Object { Invoke-CimMethod -InputObject $_ -MethodName Terminate | Out-Null }
}

function Start-FieldDayCommander {
    foreach ($name in $Services) {
        if (Get-Service -Name $name -ErrorAction SilentlyContinue) {
            Start-Service -Name $name -ErrorAction SilentlyContinue
        }
    }
}

# "Saturday, June 27, 2026 2:05 PM (1805Z)" from an ISO 8601 time.
function Format-BackupTime([string] $Iso) {
    try {
        $time = [DateTimeOffset]::Parse($Iso)
        return '{1}Z (UTC), {0} local time' -f $time.LocalDateTime.ToString('f'), $time.UtcDateTime.ToString('HHmm')
    } catch {
        return $Iso
    }
}
