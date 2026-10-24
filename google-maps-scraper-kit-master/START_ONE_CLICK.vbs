Option Explicit
Dim shell, fso, folder, cmd, rc, i, ok
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
folder = fso.GetParentFolderName(WScript.ScriptFullName)
shell.CurrentDirectory = folder

' Start Docker Compose silently.
cmd = "cmd /c docker compose up -d"
rc = shell.Run(cmd, 0, True)
If rc <> 0 Then
  MsgBox "Docker could not start the Google Maps Scraper." & vbCrLf & vbCrLf & _
         "Please make sure Docker Desktop is running, then double-click START_ONE_CLICK.vbs again." & vbCrLf & _
         "Error code: " & rc, vbExclamation, "Google Maps Scraper"
  WScript.Quit rc
End If

' Wait for the local web UI/API to become available.
ok = False
For i = 1 To 60
  If CheckUrl("http://127.0.0.1:8080") Then
    ok = True
    Exit For
  End If
  WScript.Sleep 1000
Next

If Not ok Then
  MsgBox "The scraper container started, but the web interface did not become ready within 60 seconds." & vbCrLf & vbCrLf & _
         "Docker may still be downloading/starting the image. Wait a little and double-click START_ONE_CLICK.vbs again." & vbCrLf & _
         "You can also try OPEN_APP.bat.", vbInformation, "Google Maps Scraper"
  WScript.Quit 1
End If

' Open the real browser UI. No console window is shown.
shell.Run "http://127.0.0.1:8080", 1, False

Function CheckUrl(url)
  Dim http
  On Error Resume Next
  Set http = CreateObject("WinHttp.WinHttpRequest.5.1")
  http.SetTimeouts 1500, 1500, 1500, 1500
  http.Open "GET", url, False
  http.Send
  CheckUrl = (Err.Number = 0 And http.Status >= 200 And http.Status < 600)
  Err.Clear
  On Error GoTo 0
End Function
