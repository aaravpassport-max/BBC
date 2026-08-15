; Auto-close running Business OS processes before install/update
!macro customInit
  nsExec::ExecToLog 'taskkill /F /IM "Business OS.exe" /T'
  nsExec::ExecToLog 'taskkill /F /IM business-os-desktop.exe /T'
  Sleep 2000
!macroend

!macro customInstall
  nsExec::ExecToLog 'taskkill /F /IM "Business OS.exe" /T'
  nsExec::ExecToLog 'taskkill /F /IM business-os-desktop.exe /T'
!macroend
