; Business OS Desktop — Inno Setup Script
; Creates a single .exe installer (~80MB compressed)
; No internet required after install.
;
; BUNDLE REQUIREMENTS (place in project root before running Inno Setup):
;   /php/        — PHP 8.2 NTS Windows x64 (from https://windows.php.net/download/)
;                  Required extensions: pdo_mysql, mbstring, openssl, json
;   /mariadb/    — MariaDB 10.11 portable Windows x64
;                  (extract mariadb-*-winx64.zip, rename folder to 'mariadb')
;   /dist/       — Electron NSIS installer output (from npm run build)
;
; Alternatively: adjust BUNDLE_DIR and SOURCE_DIR below to match your paths.

#define AppName "Business OS"
#define AppVersion "1.0.0"
#define AppPublisher "Business OS"
#define AppURL "https://businessos.local"
#define AppExeName "BusinessOS.exe"
#define SourceDir ".."

[Setup]
AppId={{F3A82D4C-7B1E-4C2A-9E5F-0D8A6B3C4E7F}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher={#AppPublisher}
AppPublisherURL={#AppURL}
AppSupportURL={#AppURL}
DefaultDirName={autopf}\BusinessOS
DefaultGroupName={#AppName}
AllowNoIcons=yes
OutputDir={#SourceDir}\installer\output
OutputBaseFilename=BusinessOS-Setup-v{#AppVersion}
SetupIconFile={#SourceDir}\app\favicon.ico
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
UninstallDisplayIcon={app}\{#AppExeName}
ChangesEnvironment=no
MinVersion=10.0
ArchitecturesInstallIn64BitMode=x64

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"; Flags: unchecked
Name: "startupicon"; Description: "Start Business OS automatically at login"; GroupDescription: "Startup:"; Flags: unchecked

[Files]
; Main Electron app
Source: "{#SourceDir}\dist\win-unpacked\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

; PHP 8.2 NTS Windows x64
Source: "{#SourceDir}\php\*"; DestDir: "{app}\php"; Flags: ignoreversion recursesubdirs createallsubdirs

; MariaDB 10.11 portable
Source: "{#SourceDir}\mariadb\*"; DestDir: "{app}\mariadb"; Flags: ignoreversion recursesubdirs createallsubdirs

; PHP server (backend)
Source: "{#SourceDir}\server\*"; DestDir: "{app}\server"; Flags: ignoreversion recursesubdirs createallsubdirs

; React SPA assets
Source: "{#SourceDir}\app\*"; DestDir: "{app}\app"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\{#AppName}"; Filename: "{app}\{#AppExeName}"
Name: "{group}\{cm:UninstallProgram,{#AppName}}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#AppName}"; Filename: "{app}\{#AppExeName}"; Tasks: desktopicon
Name: "{userstartup}\{#AppName}"; Filename: "{app}\{#AppExeName}"; Tasks: startupicon

[Registry]
; Auto-start at Windows login (optional, user-controlled via task)
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "BusinessOS"; ValueData: """{app}\{#AppExeName}"""; Flags: uninsdeletevalue; Tasks: startupicon

[Run]
Filename: "{app}\{#AppExeName}"; Description: "{cm:LaunchProgram,{#StringChange(AppName, '&', '&&')}}"; Flags: nowait postinstall skipifsilent

[UninstallRun]
Filename: "taskkill"; Parameters: "/F /IM BusinessOS.exe"; Flags: runhidden; RunOnceId: "KillApp"

[UninstallDelete]
Type: filesandordirs; Name: "{app}\server\vendor"

[Code]
// Check for existing instance on install
function InitializeSetup(): Boolean;
begin
  Result := True;
end;

// Show installation summary
procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssPostInstall then
  begin
    // Nothing needed — app initialises DB on first run
  end;
end;
