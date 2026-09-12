; ILRS NSIS hooks — force-close background/tray instances before install/update.
; Fixes: "ILRS cannot be closed. Please close it manually and click Retry"

!macro customInit
  ; Graceful close attempt (hidden tray apps often ignore WM_CLOSE)
  nsExec::ExecToLog 'taskkill /IM ILRS.exe /T'
  Sleep 800
  ; Force kill if still running
  nsExec::ExecToLog 'taskkill /F /IM ILRS.exe /T'
  Sleep 1200
!macroend

!macro customInstall
  nsExec::ExecToLog 'taskkill /F /IM ILRS.exe /T'
  Sleep 500
!macroend

!macro customUnInstall
  nsExec::ExecToLog 'taskkill /F /IM ILRS.exe /T'
  Sleep 500
!macroend
