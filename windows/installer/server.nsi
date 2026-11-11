; SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
; SPDX-License-Identifier: GPL-3.0-or-later

; The Windows server installer. Build it with windows/build-installer.sh.
;
; It copies the code to Program Files and hands the rest to server\setup.ps1:
; the data folder in ProgramData, the Windows services, the firewall rules and
; the database. Running a newer installer upgrades in place and keeps the data.
; Uninstalling keeps the data unless the person says otherwise, and always
; keeps backups.

Unicode true
SetCompressor /SOLID lzma

!include "MUI2.nsh"
!include "LogicLib.nsh"
!include "WinVer.nsh"
!include "x64.nsh"

!define PRODUCT "Field Day Commander"
!define PUBLISHER "Corey Koval"
!define UNINSTALL_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT} Server"
; NSIS is a 32-bit program; sysnative reaches the 64-bit PowerShell, which
; sees the real Program Files and System32.
!define POWERSHELL '"$WINDIR\sysnative\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -NonInteractive -ExecutionPolicy Bypass'

; Passed in by windows/build-installer.sh.
!ifndef VERSION | NUMERIC_VERSION | STAGE | OUT_FILE
  !error "Build with windows/build-installer.sh"
!endif

Name "${PRODUCT}"
OutFile "${OUT_FILE}"
InstallDir "$PROGRAMFILES64\${PRODUCT}"
RequestExecutionLevel admin
ShowInstDetails show
ShowUninstDetails show
BrandingText " "

VIProductVersion "${NUMERIC_VERSION}"
VIAddVersionKey "ProductName" "${PRODUCT}"
VIAddVersionKey "ProductVersion" "${VERSION}"
VIAddVersionKey "FileVersion" "${NUMERIC_VERSION}"
VIAddVersionKey "FileDescription" "${PRODUCT} server installer"
VIAddVersionKey "LegalCopyright" "2026 ${PUBLISHER}. GPL-3.0-or-later."

Var SetupLog
; Read from server\address.txt after setup; shown on the finish page.
Var ServerAddress

!define MUI_ICON "windows\installer\server.ico"
!define MUI_UNICON "windows\installer\server.ico"
!define MUI_ABORTWARNING

!define MUI_WELCOMEPAGE_TEXT "This sets up the ${PRODUCT} ${VERSION}$\r$\nserver on this computer.$\r$\n$\r$\nYour operators can use a web browser$\r$\non their own phones, tablets, or laptops.$\r$\nThey need to be on the same network.$\r$\n$\r$\nThere's nothing to install on their devices."
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_LICENSE "${STAGE}\LICENSES\GPL-3.0-or-later.txt"
!insertmacro MUI_PAGE_INSTFILES
!define MUI_FINISHPAGE_TEXT "${PRODUCT} is running.$\r$\nIt will start whenever this computer starts.$\r$\n$\r$\nThe first time you open it, you'll set$\r$\nthe administrator password.$\r$\n$\r$\nOperators on the same network can open:$\r$\n$ServerAddress"
!define MUI_FINISHPAGE_RUN
!define MUI_FINISHPAGE_RUN_TEXT "Open ${PRODUCT} on this computer"
!define MUI_FINISHPAGE_RUN_FUNCTION OpenInBrowser
!insertmacro MUI_PAGE_FINISH

!define MUI_UNCONFIRMPAGE_TEXT_TOP "Uninstalling stops and removes the ${PRODUCT} server.$\r$\n$\r$\nYou'll be asked whether to also delete your log, settings, and photos. No is selected by default. Backups in Public Documents are always kept."
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

!insertmacro MUI_LANGUAGE "English"


Function .onInit
  ${IfNot} ${AtLeastWin10}
  ${OrIfNot} ${RunningX64}
    MessageBox MB_OK|MB_ICONSTOP "${PRODUCT} needs 64-bit Windows 10 or 11." /SD IDOK
    Abort
  ${EndIf}
  SetRegView 64
  StrCpy $SetupLog "$COMMONPROGRAMDATA\${PRODUCT}\logs\setup.log"
FunctionEnd

Function un.onInit
  SetRegView 64
  StrCpy $SetupLog "$COMMONPROGRAMDATA\${PRODUCT}\logs\setup.log"
FunctionEnd

Function OpenInBrowser
  ExecShell "open" "$INSTDIR\${PRODUCT}.url"
FunctionEnd

; A junction left in the code folder would let a recursive delete reach the
; uploaded files, so remove it first whatever setup.ps1 managed to do.
!macro REMOVE_STORAGE_JUNCTION
  nsExec::Exec 'cmd.exe /c rmdir "$INSTDIR\app\public\storage"'
  Pop $0
!macroend

Section
  SetShellVarContext all

  ; Stop a running copy before replacing its files. The new setup.ps1 does it,
  ; so a fix to stopping reaches older installs too.
  ${If} ${FileExists} "$INSTDIR\app\artisan"
    DetailPrint "Stopping the ${PRODUCT} server..."
    InitPluginsDir
    File "/oname=$PLUGINSDIR\setup.ps1" "${STAGE}\server\setup.ps1"
    nsExec::ExecToLog '${POWERSHELL} -File "$PLUGINSDIR\setup.ps1" -Action Stop -InstallDir "$INSTDIR"'
    Pop $0
    !insertmacro REMOVE_STORAGE_JUNCTION
    ; Old code is removed so files dropped from this version don't linger.
    RMDir /r "$INSTDIR\app"
  ${EndIf}

  DetailPrint "Copying server files..."
  SetDetailsPrint listonly
  SetOutPath "$INSTDIR"
  File /r "${STAGE}\app"
  File /r "${STAGE}\frankenphp"
  File /r "${STAGE}\services"
  File /r "${STAGE}\server"
  File /r "${STAGE}\tools"
  File /r "${STAGE}\LICENSES"
  SetDetailsPrint both

  WriteUninstaller "$INSTDIR\uninstall.exe"
  WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayName" "${PRODUCT} Server"
  WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayVersion" "${VERSION}"
  WriteRegStr HKLM "${UNINSTALL_KEY}" "Publisher" "${PUBLISHER}"
  WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayIcon" "$INSTDIR\uninstall.exe"
  WriteRegStr HKLM "${UNINSTALL_KEY}" "InstallLocation" "$INSTDIR"
  WriteRegStr HKLM "${UNINSTALL_KEY}" "UninstallString" '"$INSTDIR\uninstall.exe"'
  WriteRegStr HKLM "${UNINSTALL_KEY}" "QuietUninstallString" '"$INSTDIR\uninstall.exe" /S'
  WriteRegDWORD HKLM "${UNINSTALL_KEY}" "NoModify" 1
  WriteRegDWORD HKLM "${UNINSTALL_KEY}" "NoRepair" 1
  SectionGetSize 0 $0
  WriteRegDWORD HKLM "${UNINSTALL_KEY}" "EstimatedSize" $0

  DetailPrint "Setting up the server and Windows services. This takes a minute or two..."
  nsExec::ExecToLog '${POWERSHELL} -File "$INSTDIR\server\setup.ps1" -Action Install -InstallDir "$INSTDIR"'
  Pop $0
  ${If} $0 != 0
    MessageBox MB_OK|MB_ICONEXCLAMATION "${PRODUCT} setup could not finish. Check the details in:$\r$\n$\r$\n$SetupLog$\r$\n$\r$\nRun this installer again after fixing the problem." /SD IDOK
    SetErrorLevel 2
  ${EndIf}

  StrCpy $ServerAddress "http://<this computer's name>.local"
  ClearErrors
  FileOpen $2 "$INSTDIR\server\address.txt" r
  ${IfNot} ${Errors}
    FileRead $2 $ServerAddress
    FileClose $2
  ${EndIf}

  CreateDirectory "$SMPROGRAMS\${PRODUCT}"
  CreateShortcut "$SMPROGRAMS\${PRODUCT}\${PRODUCT}.lnk" "$INSTDIR\${PRODUCT}.url" "" "$INSTDIR\uninstall.exe"
  CreateShortcut "$SMPROGRAMS\${PRODUCT}\Back Up ${PRODUCT}.lnk" \
    "$WINDIR\sysnative\WindowsPowerShell\v1.0\powershell.exe" \
    '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "$INSTDIR\tools\backup.ps1"' \
    "$INSTDIR\uninstall.exe"
  CreateShortcut "$SMPROGRAMS\${PRODUCT}\Restore ${PRODUCT} from a Backup.lnk" \
    "$WINDIR\sysnative\WindowsPowerShell\v1.0\powershell.exe" \
    '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "$INSTDIR\tools\restore.ps1"' \
    "$INSTDIR\uninstall.exe"
  CreateShortcut "$SMPROGRAMS\${PRODUCT}\${PRODUCT} Backups.lnk" "$DOCUMENTS\${PRODUCT} Backups"
SectionEnd

Section "Uninstall"
  SetShellVarContext all

  MessageBox MB_YESNO|MB_ICONQUESTION|MB_DEFBUTTON2 "Also delete your log, settings, and photos from this computer?$\r$\n$\r$\nChoose No to keep them for a later reinstall. Backups in Public Documents are always kept." /SD IDNO IDYES removeData
  StrCpy $1 ""
  Goto uninstall
  removeData:
  StrCpy $1 "-RemoveData"
  uninstall:

  InitPluginsDir
  CopyFiles /SILENT "$INSTDIR\server\setup.ps1" "$PLUGINSDIR\setup.ps1"
  nsExec::ExecToLog '${POWERSHELL} -File "$PLUGINSDIR\setup.ps1" -Action Uninstall -InstallDir "$INSTDIR" $1'
  Pop $0
  !insertmacro REMOVE_STORAGE_JUNCTION

  RMDir /r "$SMPROGRAMS\${PRODUCT}"
  RMDir /r "$INSTDIR\app"
  RMDir /r "$INSTDIR\frankenphp"
  RMDir /r "$INSTDIR\services"
  RMDir /r "$INSTDIR\server"
  RMDir /r "$INSTDIR\tools"
  RMDir /r "$INSTDIR\LICENSES"
  Delete "$INSTDIR\${PRODUCT}.url"
  Delete "$INSTDIR\uninstall.exe"
  RMDir "$INSTDIR"
  DeleteRegKey HKLM "${UNINSTALL_KEY}"
SectionEnd
