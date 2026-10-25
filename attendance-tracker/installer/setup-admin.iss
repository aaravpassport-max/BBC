; Workforce Attendance — Admin console (WPF + core engine + Node sidecar)
#ifndef AppVersion
  #define AppVersion "1.0.3"
#endif

#define AppName "Workforce Attendance Console"
#define AppPublisher "Workforce Attendance"
#define AdminExe "AttendanceTracker.exe"

[Setup]
AppId={{8F4E2A91-6C3D-4B1E-9F0A-ATTENDANCE-ADMIN}}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher={#AppPublisher}
DefaultDirName={autopf}\WorkforceAttendance\Admin
DefaultGroupName={#AppName}
OutputDir=output
OutputBaseFilename=WorkforceAttendance-Admin-Setup-{#AppVersion}
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
ArchitecturesInstallIn64BitMode=x64
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
UninstallDisplayIcon={app}\{#AdminExe}
MinVersion=10.0
SetupIconFile=..\desktop-wpf\AttendanceTracker.Desktop\Assets\app.ico

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Shortcuts:"; Flags: checkedonce

[Files]
Source: "..\dist\AttendanceTracker-win-x64\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\{#AppName}"; Filename: "{app}\{#AdminExe}"
Name: "{group}\Uninstall {#AppName}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#AppName}"; Filename: "{app}\{#AdminExe}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#AdminExe}"; Description: "Launch {#AppName} now"; Flags: postinstall nowait skipifsilent
