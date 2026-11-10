# SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
# SPDX-License-Identifier: GPL-3.0-or-later

# Sets up, stops or removes the Windows server. The installer runs it as
# administrator:
#
#   setup.ps1 -Action Stop      -InstallDir <dir>   before copying new files
#   setup.ps1 -Action Install   -InstallDir <dir>   after copying them (install or upgrade)
#   setup.ps1 -Action Uninstall -InstallDir <dir> [-RemoveData]
#
# Code lives read-only in InstallDir (Program Files). Everything the server
# writes lives in DataDir (ProgramData), which the services reach through
# FDC_DATA_PATH. Backups go to Public Documents. Upgrades keep the data;
# uninstalling keeps it unless -RemoveData is given, and never removes backups.
#
# It doesn't rely on tools\common.ps1, because Stop runs before the files it
# would load have been copied. Keep $ServiceIds in step with common.ps1.

param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Install', 'Stop', 'Uninstall')]
    [string] $Action,

    [Parameter(Mandatory = $true)]
    [string] $InstallDir,

    [string] $DataDir = (Join-Path $env:ProgramData 'Field Day Commander'),

    [string] $BackupDir = (Join-Path $env:PUBLIC 'Documents\Field Day Commander Backups'),

    # 0 picks one: the port this install already uses, else 80, else 8000.
    [int] $HttpPort = 0,

    [switch] $RemoveData
)

$ErrorActionPreference = 'Stop'

$InstallDir = $InstallDir.TrimEnd('\')
$AppDir = Join-Path $InstallDir 'app'
$PhpDir = Join-Path $InstallDir 'frankenphp'
$Php = Join-Path $PhpDir 'php.exe'
$FrankenPhp = Join-Path $PhpDir 'frankenphp.exe'
$Artisan = Join-Path $AppDir 'artisan'
$ServicesDir = Join-Path $InstallDir 'services'
$EnvFile = Join-Path $DataDir '.env'
$Database = Join-Path $DataDir 'database.sqlite'
$LogDir = Join-Path $DataDir 'logs'
$ReverbPort = 8080
$FirewallGroup = 'Field Day Commander'

# In start order. Keep in step with $Services in tools\common.ps1.
$ServiceIds = @(
    'FieldDayCommanderWeb',
    'FieldDayCommanderReverb',
    'FieldDayCommanderQueue',
    'FieldDayCommanderScheduler'
)

# Well-known SIDs, so this works on Windows in any language.
$SidSystem = '*S-1-5-18'
$SidAdministrators = '*S-1-5-32-544'
$SidLocalService = '*S-1-5-19'

$env:PHPRC = $PhpDir
$env:FDC_DATA_PATH = $DataDir

function Write-Step([string] $Message) {
    Write-Output "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $Message"
}

function Invoke-Native([string] $FilePath, [string[]] $Arguments) {
    $output = & $FilePath @Arguments 2>&1
    $output | ForEach-Object { Write-Output "    $_" }

    if ($LASTEXITCODE -ne 0) {
        throw "$(Split-Path -Leaf $FilePath) $($Arguments -join ' ') failed with exit code $LASTEXITCODE"
    }
}

function Invoke-Artisan([string[]] $Arguments) {
    Push-Location $AppDir
    try {
        Invoke-Native $Php (@($Artisan) + $Arguments)
    } finally {
        Pop-Location
    }
}

# Only SYSTEM, Administrators and the services' LocalService account can
# open the folder: it holds APP_KEY, password hashes and the whole log.
function Set-PrivateAcl([string] $Path, [string[]] $ExtraGrants = @()) {
    $grants = @("${SidSystem}:(OI)(CI)F", "${SidAdministrators}:(OI)(CI)F", "${SidLocalService}:(OI)(CI)M") + $ExtraGrants
    $arguments = @($Path, '/inheritance:r', '/Q')
    foreach ($grant in $grants) {
        $arguments += @('/grant:r', $grant)
    }
    Invoke-Native 'icacls.exe' $arguments
}

function Initialize-DataFolders {
    Write-Step "Preparing the data folder $DataDir"

    foreach ($folder in @(
        $DataDir,
        "$DataDir\storage\app\private",
        "$DataDir\storage\app\public",
        "$DataDir\storage\framework\cache\data",
        "$DataDir\storage\framework\sessions",
        "$DataDir\storage\framework\views",
        "$DataDir\storage\logs",
        "$DataDir\cache",
        "$DataDir\caddy",
        $LogDir,
        $BackupDir
    )) {
        New-Item -ItemType Directory -Force -Path $folder | Out-Null
    }

    Set-PrivateAcl $DataDir

    # The person installing can open the backups folder from File Explorer
    # without the "You don't have permission" prompt.
    $installer = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    Set-PrivateAcl $BackupDir @("*${installer}:(OI)(CI)M")
}

function Write-PhpIni {
    $template = Get-Content -Raw (Join-Path $PSScriptRoot 'php.ini')
    $ini = $template.Replace('@EXTENSION_DIR@', (Join-Path $PhpDir 'ext'))
    [IO.File]::WriteAllText((Join-Path $PhpDir 'php.ini'), $ini)
}

function New-Secret([int] $Bytes) {
    $buffer = New-Object byte[] $Bytes
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buffer)
    return $buffer
}

function New-Token([int] $Length, [string] $Alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789') {
    $bytes = New-Secret $Length
    return -join ($bytes | ForEach-Object { $Alphabet[$_ % $Alphabet.Length] })
}

# .env values with backslashes go in single quotes: inside double quotes,
# dotenv reads a backslash as the start of an escape.
function Format-EnvPath([string] $Path) {
    return "'$Path'"
}

function Read-EnvValue([string] $Key) {
    if (-not (Test-Path $EnvFile)) {
        return $null
    }
    $line = Get-Content $EnvFile | Where-Object { $_ -match "^$Key=" } | Select-Object -First 1
    if ($null -eq $line) {
        return $null
    }
    return ($line -replace "^$Key=", '').Trim("'", '"')
}

function Test-PortFree([int] $Port) {
    $listeners = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    return $null -eq $listeners
}

function Select-HttpPort {
    $existing = Read-EnvValue 'FDC_HTTP_PORT'
    if ($existing) {
        return [int] $existing
    }
    if ($HttpPort -gt 0) {
        return $HttpPort
    }
    foreach ($candidate in @(80, 8000, 8001)) {
        if (Test-PortFree $candidate) {
            return $candidate
        }
    }
    throw 'Ports 80, 8000 and 8001 are all in use. Run the installer again after closing the program using one of them.'
}

# Writes settings a new install needs and adds any a newer version needs,
# leaving everything already in .env alone.
function Initialize-Env([int] $Port) {
    $lines = [Collections.Generic.List[string]]::new()
    if (Test-Path $EnvFile) {
        foreach ($line in @(Get-Content $EnvFile)) {
            $lines.Add($line)
        }
    }

    $hostName = $env:COMPUTERNAME.ToLowerInvariant()
    $portSuffix = if ($Port -eq 80) { '' } else { ":$Port" }

    $defaults = [ordered] @{
        'APP_NAME' = '"Field Day Commander"'
        'APP_ENV' = 'production'
        'APP_DEBUG' = 'false'
        'APP_KEY' = 'base64:' + [Convert]::ToBase64String((New-Secret 32))
        'APP_URL' = "http://$hostName.local$portSuffix"
        'LOG_LEVEL' = 'warning'
        'LOG_STACK' = 'daily'
        'LOG_DAILY_DAYS' = '14'
        'DB_CONNECTION' = 'sqlite'
        'DB_DATABASE' = Format-EnvPath $Database
        'SESSION_DRIVER' = 'file'
        'CACHE_STORE' = 'file'
        'QUEUE_CONNECTION' = 'database'
        'BROADCAST_CONNECTION' = 'reverb'
        'REVERB_APP_ID' = New-Token 8 '0123456789'
        'REVERB_APP_KEY' = New-Token 20
        'REVERB_APP_SECRET' = New-Token 20
        'REVERB_SERVER_HOST' = '127.0.0.1'
        'REVERB_SERVER_PORT' = "$ReverbPort"
        'BACKUP_PATH' = Format-EnvPath $BackupDir
        'BACKUP_AUTOMATIC' = 'true'
        'PHP_BINARY' = Format-EnvPath $Php
        'FDC_HTTP_PORT' = "$Port"
    }

    $added = @()
    foreach ($key in $defaults.Keys) {
        if (-not ($lines | Where-Object { $_ -match "^$key=" })) {
            $lines.Add("$key=$($defaults[$key])")
            $added += $key
        }
    }

    if ($added.Count -gt 0) {
        Write-Step "Writing settings to .env: $($added -join ', ')"
        [IO.File]::WriteAllLines($EnvFile, $lines)
    }
}

function Initialize-Database {
    $firstInstall = -not (Test-Path $Database) -or (Get-Item $Database).Length -eq 0
    if (-not (Test-Path $Database)) {
        New-Item -ItemType File -Path $Database | Out-Null
    }

    Write-Step 'Updating the database'
    Invoke-Artisan @('optimize:clear')
    Invoke-Artisan @('migrate', '--force')

    if ($firstInstall) {
        Write-Step 'Adding the bands, modes, sections and starting accounts'
        # The same seeders deploy.sh runs on a new server.
        foreach ($seeder in @('EventTypeSeeder', 'BandSeeder', 'ModeSeeder', 'SectionSeeder', 'OperatingClassSeeder', 'BonusTypeSeeder', 'PermissionSeeder', 'RoleSeeder', 'SystemAdminSeeder')) {
            Invoke-Artisan @('db:seed', "--class=$seeder", '--force')
        }
    }

    Invoke-Artisan @('optimize')
}

# public\storage points at the uploaded files in the data folder. A junction
# needs no special rights, unlike a symbolic link.
function Set-StorageJunction {
    $link = Join-Path $AppDir 'public\storage'
    Remove-StorageJunction
    New-Item -ItemType Junction -Path $link -Target "$DataDir\storage\app\public" | Out-Null
}

# Removed before the installer deletes or replaces the code folder, so a
# recursive delete can't follow it into the uploaded files.
function Remove-StorageJunction {
    $link = Join-Path $AppDir 'public\storage'
    if (Test-Path $link) {
        cmd.exe /c rmdir "$link" | Out-Null
    }
}

function ConvertTo-XmlText([string] $Value) {
    return [Security.SecurityElement]::Escape($Value)
}

function Write-ServiceConfig([string] $Id, [string] $Name, [string] $Description, [string] $Executable, [string] $Arguments, [hashtable] $ExtraEnv = @{}) {
    $environment = @{
        'FDC_DATA_PATH' = $DataDir
        'PHPRC' = $PhpDir
        'PHP_BINARY' = $Php
    } + $ExtraEnv

    $envXml = ($environment.Keys | Sort-Object | ForEach-Object {
        "  <env name=`"$_`" value=`"$(ConvertTo-XmlText $environment[$_])`" />"
    }) -join "`r`n"

    $xml = @"
<!-- Written by setup.ps1 on every install. Changes here are replaced on upgrade. -->
<service>
  <id>$Id</id>
  <name>$(ConvertTo-XmlText $Name)</name>
  <description>$(ConvertTo-XmlText $Description)</description>
  <executable>$(ConvertTo-XmlText $Executable)</executable>
  <arguments>$(ConvertTo-XmlText $Arguments)</arguments>
  <workingdirectory>$(ConvertTo-XmlText $AppDir)</workingdirectory>
$envXml
  <startmode>Automatic</startmode>
  <onfailure action="restart" delay="10 sec" />
  <onfailure action="restart" delay="30 sec" />
  <onfailure action="restart" delay="60 sec" />
  <resetfailure>1 hour</resetfailure>
  <stoptimeout>15 sec</stoptimeout>
  <logpath>$(ConvertTo-XmlText $LogDir)</logpath>
  <log mode="roll-by-size">
    <sizeThreshold>10240</sizeThreshold>
    <keepFiles>3</keepFiles>
  </log>
</service>
"@

    [IO.File]::WriteAllText((Join-Path $ServicesDir "$Id.xml"), $xml)
}

function Install-Services([int] $Port) {
    Write-Step 'Setting up the Windows services'

    $wrapper = Join-Path $ServicesDir 'WinSW.exe'
    $appPathForCaddy = $AppDir.Replace('\', '/')

    Write-ServiceConfig 'FieldDayCommanderWeb' 'Field Day Commander web server' `
        'Serves Field Day Commander to browsers on this computer and the local network.' `
        $FrankenPhp "run -c `"$(Join-Path $PSScriptRoot 'Caddyfile')`"" @{
            'FDC_APP_PATH' = $appPathForCaddy
            'FDC_HTTP_PORT' = "$Port"
            'REVERB_SERVER_PORT' = "$ReverbPort"
            # Caddy keeps its state here instead of the service account's profile.
            'XDG_DATA_HOME' = "$DataDir\caddy"
            'XDG_CONFIG_HOME' = "$DataDir\caddy"
        }

    Write-ServiceConfig 'FieldDayCommanderReverb' 'Field Day Commander live updates' `
        'Sends live score, log and map updates to open browsers.' `
        $Php "`"$Artisan`" reverb:start --host=127.0.0.1 --port=$ReverbPort"

    Write-ServiceConfig 'FieldDayCommanderQueue' 'Field Day Commander background jobs' `
        'Runs imports, notifications and other work in the background.' `
        $Php "`"$Artisan`" queue:work --sleep=1 --tries=3"

    Write-ServiceConfig 'FieldDayCommanderScheduler' 'Field Day Commander scheduler' `
        'Runs scheduled tasks: logger listeners, reminders, weather and automatic backups.' `
        $Php "`"$Artisan`" schedule:work"

    foreach ($id in $ServiceIds) {
        $exe = Join-Path $ServicesDir "$id.exe"
        Copy-Item -Force $wrapper $exe

        if (-not (Get-Service -Name $id -ErrorAction SilentlyContinue)) {
            Invoke-Native $exe @('install')
        }

        # Run as LocalService, not SYSTEM: it can only write the data folder.
        Invoke-Native 'sc.exe' @('config', $id, 'obj=', 'NT AUTHORITY\LocalService', 'password=', '')
    }
}

function Set-FirewallRules([int] $Port) {
    Write-Step 'Opening the firewall to the local network'

    Get-NetFirewallRule -Group $FirewallGroup -ErrorAction SilentlyContinue | Remove-NetFirewallRule

    # LocalSubnet on every profile: Windows often calls a Field Day network
    # Public, but only devices on the same network get in.
    New-NetFirewallRule -Group $FirewallGroup -DisplayName 'Field Day Commander web server' `
        -Direction Inbound -Action Allow -Program $FrankenPhp -Protocol TCP -LocalPort $Port `
        -RemoteAddress LocalSubnet -Profile Any | Out-Null

    foreach ($protocol in @('UDP', 'TCP')) {
        New-NetFirewallRule -Group $FirewallGroup -DisplayName "Field Day Commander logger listeners ($protocol)" `
            -Direction Inbound -Action Allow -Program $Php -Protocol $protocol `
            -RemoteAddress LocalSubnet -Profile Any | Out-Null
    }
}

function Start-Services {
    foreach ($id in $ServiceIds) {
        Start-Service -Name $id
    }
}

function Stop-Services {
    $reversed = $ServiceIds.Clone()
    [array]::Reverse($reversed)

    foreach ($id in $reversed) {
        if (Get-Service -Name $id -ErrorAction SilentlyContinue) {
            Write-Step "Stopping $id"
            Stop-Service -Name $id -Force -ErrorAction SilentlyContinue
        }
    }

    # Logger listeners run on their own, and anything still running from the
    # install folder would keep its files locked during an upgrade.
    Get-CimInstance Win32_Process -Filter "Name = 'php.exe' OR Name = 'frankenphp.exe'" |
        Where-Object {
            ($_.ExecutablePath -and $_.ExecutablePath.StartsWith($InstallDir, [StringComparison]::OrdinalIgnoreCase)) -or
            ($_.CommandLine -like '*artisan*external-logger:*')
        } |
        ForEach-Object {
            Write-Step "Stopping process $($_.ProcessId) ($($_.Name))"
            Invoke-CimMethod -InputObject $_ -MethodName Terminate | Out-Null
        }
}

function Wait-ForWebServer([int] $Port) {
    Write-Step "Waiting for the web server on port $Port"
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -TimeoutSec 5 "http://127.0.0.1:$Port/up"
            if ($response.StatusCode -eq 200) {
                return
            }
        } catch {
            Start-Sleep -Seconds 1
        }
    }
    throw "The web server didn't answer on port $Port. Its log is in $LogDir."
}

# A shortcut the installer puts in the Start menu and opens when it finishes.
function Write-BrowserShortcut([int] $Port) {
    $portSuffix = if ($Port -eq 80) { '' } else { ":$Port" }
    $shortcut = "[InternetShortcut]`r`nURL=http://localhost$portSuffix/`r`n"
    [IO.File]::WriteAllText((Join-Path $InstallDir 'Field Day Commander.url'), $shortcut)
}

function Uninstall-Services {
    foreach ($id in $ServiceIds) {
        if (-not (Get-Service -Name $id -ErrorAction SilentlyContinue)) {
            continue
        }
        $exe = Join-Path $ServicesDir "$id.exe"
        if (Test-Path $exe) {
            Invoke-Native $exe @('uninstall')
        } else {
            Invoke-Native 'sc.exe' @('delete', $id)
        }
    }
}

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
Start-Transcript -Append -Path (Join-Path $LogDir 'setup.log') | Out-Null

try {
    switch ($Action) {
        'Stop' {
            Stop-Services
            Remove-StorageJunction
        }
        'Install' {
            Stop-Services
            Initialize-DataFolders
            Write-PhpIni
            $port = Select-HttpPort
            Initialize-Env $port
            Initialize-Database
            Set-StorageJunction
            Install-Services $port
            Set-FirewallRules $port
            Write-Step 'Starting Field Day Commander'
            Start-Services
            Wait-ForWebServer $port
            Write-BrowserShortcut $port
            Write-Step "Field Day Commander is running on port $port"
        }
        'Uninstall' {
            Stop-Services
            Uninstall-Services
            Get-NetFirewallRule -Group $FirewallGroup -ErrorAction SilentlyContinue | Remove-NetFirewallRule
            Remove-StorageJunction
            if ($RemoveData) {
                Write-Step "Removing $DataDir"
                Stop-Transcript | Out-Null
                Remove-Item -Recurse -Force $DataDir
                exit 0
            }
        }
    }
} catch {
    Write-Output "ERROR: $($_.Exception.Message)"
    Stop-Transcript | Out-Null
    exit 1
}

Stop-Transcript | Out-Null
exit 0
