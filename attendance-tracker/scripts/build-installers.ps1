# Compiles Inno Setup installers for Admin + Employee (Windows only).
param(
  [string]$AppVersion = "1.0.3"
)
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Iscc = "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe"
if (-not (Test-Path $Iscc)) {
  throw "Inno Setup 6 not found. Install from https://jrsoftware.org/isinfo.php or use GitHub Actions."
}
$IssDir = Join-Path $Root "installer"
$args = @("/DAppVersion=$AppVersion")
foreach ($script in @("setup-unified.iss", "setup-admin.iss", "setup-employee.iss")) {
  $path = Join-Path $IssDir $script
  Write-Host "Compiling $script ..."
  & $Iscc @args $path
}
Write-Host "Installers written to $IssDir\output\"
