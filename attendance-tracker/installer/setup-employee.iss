; Workforce Attendance — Employee monitoring agent (tray app)
#ifndef AppVersion
  #define AppVersion "1.0.3"
#endif

#define AppName "Workforce Attendance Agent"
#define AppPublisher "Workforce Attendance"
#define AgentExe "AttendanceAgent.exe"

[Setup]
AppId={{8F4E2A91-6C3D-4B1E-9F0A-ATTENDANCE-AGENT}}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher={#AppPublisher}
DefaultDirName={autopf}\WorkforceAttendance\Agent
DefaultGroupName={#AppName}
OutputDir=output
OutputBaseFilename=WorkforceAttendance-Employee-Setup-{#AppVersion}
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
ArchitecturesInstallIn64BitMode=x64
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
UninstallDisplayIcon={app}\{#AgentExe}
MinVersion=10.0

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut (optional)"; GroupDescription: "Shortcuts:"; Flags: unchecked
Name: "runatlogin"; Description: "Start monitoring automatically when I sign in to Windows"; GroupDescription: "Startup:"; Flags: checkedonce

[Files]
Source: "..\dist\AttendanceAgent-win-x64\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\browser-extension\*"; DestDir: "{app}\browser-extension"; Flags: ignoreversion recursesubdirs createallsubdirs; Excludes: "README.md"

[Icons]
Name: "{group}\{#AppName}"; Filename: "{app}\{#AgentExe}"
Name: "{group}\Uninstall {#AppName}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#AppName}"; Filename: "{app}\{#AgentExe}"; Tasks: desktopicon

[Registry]
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "WorkforceAttendanceAgent"; ValueData: """{app}\{#AgentExe}"""; Flags: uninsdeletevalue; Tasks: runatlogin

[Run]
Filename: "{app}\{#AgentExe}"; Description: "Start {#AppName} now"; Flags: postinstall nowait skipifsilent
