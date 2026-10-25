# Downloads portable Node.js Windows x64 into runtime/node/ for offline installs.
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Version = "22.12.0"
$Zip = "node-v$Version-win-x64.zip"
$Url = "https://nodejs.org/dist/v$Version/$Zip"
$Dest = Join-Path $Root "runtime\node"
New-Item -ItemType Directory -Force -Path $Dest | Out-Null
$ZipPath = Join-Path $env:TEMP $Zip
Invoke-WebRequest -Uri $Url -OutFile $ZipPath
Expand-Archive -Path $ZipPath -DestinationPath $env:TEMP -Force
Copy-Item -Force (Join-Path $env:TEMP "node-v$Version-win-x64\node.exe") (Join-Path $Dest "node.exe")
Write-Host "Installed node.exe to $Dest"
