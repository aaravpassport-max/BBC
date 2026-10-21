; Business OS — custom NSIS installer/uninstaller hooks

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

; ── Uninstall: ask whether to delete local app data ───────────────────────────
!macro customUnInstall
  MessageBox MB_YESNO|MB_ICONQUESTION "Do you want to delete all Business OS application data?$\n$\nThis removes your database, settings, and uploaded files from:$\n%APPDATA%\business-os-desktop$\n$\nChoose Yes for a clean reinstall. This cannot be undone." IDNO skip_delete

  SetShellVarContext current
  RMDir /r "$APPDATA\business-os-desktop"

  skip_delete:
!macroend
