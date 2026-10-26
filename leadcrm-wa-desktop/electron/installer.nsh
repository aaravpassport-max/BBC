; LeadCRM WA — NSIS hooks (close app before install/update)

!macro customInit
  nsExec::ExecToLog 'taskkill /F /IM "LeadCRM WA.exe" /T'
  Sleep 1500
!macroend

!macro customInstall
  nsExec::ExecToLog 'taskkill /F /IM "LeadCRM WA.exe" /T'
!macroend

!macro customUnInstall
  MessageBox MB_YESNO|MB_ICONEXCLAMATION "Uninstall LeadCRM WA?$\n$\nYour leads and CRM data are stored separately in:$\n%APPDATA%\leadcrm-wa-desktop\LeadCRM-WA$\n$\nThat folder is NOT removed automatically (safe upgrade/reinstall).$\n$\nDelete ALL local CRM data now? (Choose No to keep your data)" IDNO skip_delete
  SetShellVarContext current
  RMDir /r "$APPDATA\leadcrm-wa-desktop\LeadCRM-WA"
  skip_delete:
!macroend
