# Builds the native WPF admin console + bundles the proven core engine for Windows x64.
# Run on Windows 10/11 with .NET 8 SDK and Node.js 20+ installed.
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

Write-Host "Building core engine..."
Push-Location (Join-Path $Root "core")
npm install
npm test
npm run build
Pop-Location

Write-Host "Publishing WPF desktop..."
Push-Location (Join-Path $Root "desktop-wpf")
dotnet publish "AttendanceTracker.Desktop\AttendanceTracker.Desktop.csproj" `
  -c Release -r win-x64 --self-contained true `
  -p:PublishSingleFile=true `
  -o (Join-Path $Root "dist\AttendanceTracker-win-x64")
Pop-Location

$Out = Join-Path $Root "dist\AttendanceTracker-win-x64"
Write-Host "Copying core runtime files..."
New-Item -ItemType Directory -Force -Path (Join-Path $Out "core") | Out-Null
Copy-Item -Recurse -Force (Join-Path $Root "core\dist") (Join-Path $Out "core\dist")
Copy-Item -Recurse -Force (Join-Path $Root "core\scripts") (Join-Path $Out "core\scripts")
Copy-Item -Force (Join-Path $Root "core\package.json") (Join-Path $Out "core\package.json")

Write-Host "Done. Output: $Out\AttendanceTracker.exe"
Write-Host "Optional: run scripts\download-node-win.ps1 to bundle node.exe into runtime\node\"
