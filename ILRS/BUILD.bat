@echo off
title ILRS Builder
echo.
echo Building ILRS Windows Installer...
echo This may take a few minutes...
echo.
call npm run build:win
echo.
echo Build complete! Check the "dist" folder for the installer.
pause
