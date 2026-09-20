; Close running app/engine before upgrade so files are not locked.
!macro NSIS_HOOK_PREINSTALL
  nsExec::ExecToLog 'taskkill /F /IM "Google Maps Scraper.exe" /T'
  nsExec::ExecToLog 'taskkill /F /IM scraper-engine.exe /T'
  nsExec::ExecToLog 'taskkill /F /IM scraper-engine-x86_64-pc-windows-msvc.exe /T'
  Sleep 2000
!macroend

!macro NSIS_HOOK_PREUNINSTALL
  nsExec::ExecToLog 'taskkill /F /IM "Google Maps Scraper.exe" /T'
  nsExec::ExecToLog 'taskkill /F /IM scraper-engine.exe /T'
  nsExec::ExecToLog 'taskkill /F /IM scraper-engine-x86_64-pc-windows-msvc.exe /T'
  Sleep 2000
!macroend
