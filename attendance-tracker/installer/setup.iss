; Inno Setup script — compile on Windows after running scripts\build-windows.ps1
#define AppVersion "1.0.0"

[Setup]
AppId={{8F4E2A91-6C3D-4B1E-9F0A-ATTENDANCE-WPF}}
AppName=Workforce Attendance Console
AppVersion={#AppVersion}
DefaultDirName={autopf}\AttendanceTracker
DefaultGroupName=Workforce Attendance Console
OutputBaseFilename=AttendanceTracker-Setup-{#AppVersion}
Compression=lzma2
SolidCompression=yes
ArchitecturesInstallIn64BitMode=x64
PrivilegesRequired=lowest

[Files]
Source: "..\dist\AttendanceTracker-win-x64\*"; DestDir: "{app}"; Flags: recursesubdirs

[Icons]
Name: "{group}\Workforce Attendance Console"; Filename: "{app}\AttendanceTracker.exe"
Name: "{autodesktop}\Workforce Attendance Console"; Filename: "{app}\AttendanceTracker.exe"

[Run]
Filename: "{app}\AttendanceTracker.exe"; Description: "Launch Workforce Attendance Console"; Flags: postinstall nowait skipifsilent
