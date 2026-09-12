@echo off
title ILRS Installer

echo.
echo ==========================================
echo   ILRS - Intelligent Life Reminder System
echo          Installing... Please wait...
echo ==========================================
echo.

:: Check Node.js
where node >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: Node.js is not installed!
    echo.
    echo Please install Node.js from: https://nodejs.org
    echo Download the LTS version and run this script again.
    echo.
    pause
    exit /b 1
)

for /f "tokens=*" %%i in ('node --version') do set NODE_VER=%%i
echo [OK] Node.js found: %NODE_VER%

:: Install dependencies
echo.
echo Installing dependencies...
call npm install

if %ERRORLEVEL% NEQ 0 (
    echo.
    echo Installation failed! Trying with legacy peer deps...
    call npm install --legacy-peer-deps
    if %ERRORLEVEL% NEQ 0 (
        echo.
        echo ERROR: Could not install dependencies.
        echo Please check your internet connection and try again.
        pause
        exit /b 1
    )
)

echo.
echo ==========================================
echo   Installation Complete!
echo ==========================================
echo.
echo ILRS is ready to use.
echo.
echo To start ILRS: double-click "START ILRS.bat"
echo To build installer: run "BUILD.bat"
echo.

:: Create start shortcut
echo @echo off > "START ILRS.bat"
echo title ILRS >> "START ILRS.bat"
echo npm start >> "START ILRS.bat"

echo [OK] Created "START ILRS.bat" shortcut
echo.
pause
