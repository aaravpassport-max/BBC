; LeadCRM WA — NSIS hooks (close app before install/update)

!macro customInit
  nsExec::ExecToLog 'taskkill /F /IM "LeadCRM WA.exe" /T'
  Sleep 1500
!macroend

!macro customInstall
  nsExec::ExecToLog 'taskkill /F /IM "LeadCRM WA.exe" /T'
!macroend

!macro customUnInstall
  MessageBox MB_YESNO|MB_ICONQUESTION "Delete LeadCRM local data in AppData?$\n$\nFolder:$\n%APPDATA%\leadcrm-wa-desktop$\n$\nChoose Yes only for a clean reinstall." IDNO skip_delete
  SetShellVarContext current
  RMDir /r "$APPDATA\leadcrm-wa-desktop\LeadCRM-WA"
  skip_delete:
!macroend
