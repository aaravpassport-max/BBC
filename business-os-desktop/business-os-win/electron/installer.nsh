; Business OS — custom NSIS installer/uninstaller hooks
!include "nsDialogs.nsh"
!include "LogicLib.nsh"

Var un.DeleteData
Var un.DeleteDataCheckbox
Var un.DeleteDataDialog

; ── Install: close running app before install/update ────────────────────────
!macro customInit
  nsExec::ExecToLog 'taskkill /F /IM "Business OS.exe" /T'
  nsExec::ExecToLog 'taskkill /F /IM business-os-desktop.exe /T'
  Sleep 2000
!macroend

!macro customInstall
  nsExec::ExecToLog 'taskkill /F /IM "Business OS.exe" /T'
  nsExec::ExecToLog 'taskkill /F /IM business-os-desktop.exe /T'
!macroend

; ── Uninstall: init checkbox state ─────────────────────────────────────────
!macro customUnInit
  StrCpy $un.DeleteData 0
!macroend

; ── Uninstall: optional page with checkbox (assisted uninstaller) ────────────
!macro customUninstallPage
  PageEx un
    PageCallbacks un.DeleteDataPageShow un.DeleteDataPageLeave
  PageExEnd
!macroend

Function un.DeleteDataPageShow
  !insertmacro MUI_HEADER_TEXT "Remove application data" "Choose whether to delete your Business OS files"
  !insertmacro MUI_UNPAGE_INTERFACE

  nsDialogs::Create 1018
  Pop $un.DeleteDataDialog
  ${IfThen} $(^ec) != 0 ${|} Abort ${|}

  ${NSD_CreateLabel} 0 0 100% 28u "Tick the box below to delete your local database, settings, and uploaded files.$\nRecommended when reinstalling or if login stops working."
  Pop $0

  ${NSD_CreateCheckbox} 0 36u 100% 16u "Delete all application data from this computer"
  Pop $un.DeleteDataCheckbox

  nsDialogs::Show
FunctionEnd

Function un.DeleteDataPageLeave
  ${NSD_GetState} $un.DeleteDataCheckbox $un.DeleteData
FunctionEnd

; ── Uninstall: remove user data when checkbox was ticked ─────────────────────
!macro customUnInstall
  !ifdef ONE_CLICK
    MessageBox MB_YESNO|MB_ICONQUESTION "Do you want to delete all Business OS application data?$\n$\nThis removes your database, settings, and uploaded files. Recommended for a clean reinstall.$\n$\nThis cannot be undone." IDNO skip_delete
  !else
    IntCmp $un.DeleteData ${BST_CHECKED} do_delete skip_delete skip_delete
  !endif

  do_delete:
    SetShellVarContext current
    RMDir /r "$APPDATA\business-os-desktop"

  skip_delete:
!macroend
