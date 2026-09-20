@echo off
cd /d "%~dp0"
docker compose down
if errorlevel 1 (
  echo.
  echo Could not stop the scraper. Please make sure Docker Desktop is running.
  pause
  exit /b 1
)
echo.
echo Google Maps Scraper stopped.
