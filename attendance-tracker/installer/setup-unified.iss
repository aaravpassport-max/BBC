; Workforce Attendance — single app for employee PCs (monitoring + live dashboard)
#ifndef AppVersion
  #define AppVersion "1.1.0"
#endif

#define AppName "Workforce Attendance"
#define AppPublisher "Workforce Attendance"
#define MainExe "AttendanceTracker.exe"

[Setup]
AppId={{8F4E2A91-6C3D-4B1E-9F0A-ATTENDANCE-UNIFIED}}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher={#AppPublisher}
DefaultDirName={autopf}\WorkforceAttendance
DefaultGroupName={#AppName}
OutputDir=output
OutputBaseFilename=WorkforceAttendance-Setup-{#AppVersion}
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
ArchitecturesInstallIn64BitMode=x64
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
UninstallDisplayIcon={app}\{#MainExe}
MinVersion=10.0
SetupIconFile=..\desktop-wpf\AttendanceTracker.Desktop\Assets\app.ico

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Shortcuts:"; Flags: checkedonce
Name: "runatlogin"; Description: "Open Workforce Attendance when I sign in to Windows"; GroupDescription: "Startup:"; Flags: checkedonce

[Dirs]
Name: "{commonappdata}\WorkforceAttendance"; Permissions: users-modify

[Files]
Source: "..\dist\AttendanceTracker-win-x64\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\browser-extension\*"; DestDir: "{app}\browser-extension"; Flags: ignoreversion recursesubdirs createallsubdirs; Excludes: "README.md"

[Icons]
Name: "{group}\{#AppName}"; Filename: "{app}\{#MainExe}"
Name: "{group}\Uninstall {#AppName}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#AppName}"; Filename: "{app}\{#MainExe}"; Tasks: desktopicon

[Registry]
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "WorkforceAttendance"; ValueData: """{app}\{#MainExe}"""; Flags: uninsdeletevalue; Tasks: runatlogin

[Run]
Filename: "{app}\{#MainExe}"; Description: "Launch {#AppName} now"; Flags: postinstall nowait skipifsilent

[Code]
var
  EmployeeIdPage: TInputQueryWizardPage;

function JsonEscape(const S: String): String;
var
  I: Integer;
  C: Char;
begin
  Result := '';
  for I := 1 to Length(S) do
  begin
    C := S[I];
    if C = '\' then Result := Result + '\\'
    else if C = '"' then Result := Result + '\"'
    else Result := Result + C;
  end;
end;

function DefaultEmployeeId: String;
var
  UserName, Computer: String;
begin
  UserName := ExpandConstant('{username}');
  Computer := GetEnv('COMPUTERNAME');
  if Computer = '' then Computer := 'PC';
  if UserName = '' then Result := Computer
  else Result := UserName + '@' + Computer;
end;

procedure InitializeWizard;
begin
  EmployeeIdPage := CreateInputQueryPage(wpSelectDir,
    'Employee ID',
    'Who uses this PC?',
    'Enter the ID from HR (email or staff number). Leave blank to use your Windows sign-in name + PC name.');
  EmployeeIdPage.Add('Employee ID (optional):', False);
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  ConfigPath, Json, EmpId, DevId: String;
begin
  if CurStep = ssPostInstall then
  begin
    EmpId := Trim(EmployeeIdPage.Values[0]);
    if EmpId = '' then EmpId := DefaultEmployeeId;
    DevId := GetEnv('COMPUTERNAME');
    if DevId = '' then DevId := 'PC';
    ConfigPath := ExpandConstant('{commonappdata}\WorkforceAttendance\agent-config.json');
    ForceDirectories(ExtractFilePath(ConfigPath));
    Json := '{ "employee_id": "' + JsonEscape(EmpId) + '", "device_id": "' + JsonEscape(DevId) + '" }' + #13#10;
    SaveStringToFile(ConfigPath, Json, False);
  end;
end;
