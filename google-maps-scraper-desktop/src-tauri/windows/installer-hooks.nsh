; Close running app/engine/browsers before upgrade so NSIS can overwrite files.
!macro KILL_GMAPS_PROCESSES
  nsExec::ExecToLog 'taskkill /F /IM "Google Maps Scraper.exe" /T'
  nsExec::ExecToLog 'taskkill /F /IM scraper-engine.exe /T'
  nsExec::ExecToLog 'taskkill /F /IM scraper-engine-x86_64-pc-windows-msvc.exe /T'
  nsExec::ExecToLog 'taskkill /F /IM chrome-headless-shell.exe /T'
  nsExec::ExecToLog 'taskkill /F /IM chrome.exe /T'
  Sleep 3000
!macroend

!macro NSIS_HOOK_PREINSTALL
  !insertmacro KILL_GMAPS_PROCESSES
  ; Remove bundled browser tree so a partial/failed install cannot lock chrome-headless-shell.exe
  IfFileExists "$INSTDIR\ms-playwright" 0 gmaps_preinstall_done
    RMDir /r "$INSTDIR\ms-playwright"
  gmaps_preinstall_done:
!macroend

!macro NSIS_HOOK_PREUNINSTALL
  !insertmacro KILL_GMAPS_PROCESSES
!macroend
