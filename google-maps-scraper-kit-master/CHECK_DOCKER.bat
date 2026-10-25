@echo off
docker info >nul 2>&1
if errorlevel 1 (
  echo Docker Desktop is not running or Docker is not available.
  echo Please start Docker Desktop and run START_ONE_CLICK.vbs.
  pause
  exit /b 1
)
echo Docker Desktop is running.
