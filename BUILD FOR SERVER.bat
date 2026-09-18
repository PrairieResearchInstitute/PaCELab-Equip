@echo off
REM ===========================================================================
REM  Shared Laboratory Equipment System - build the folder that goes to IT.
REM
REM  DOUBLE-CLICK THIS FILE. It runs the tests, and if they pass it writes a
REM  clean copy of the application into dist\ along with a zip file.
REM
REM  Give IT the zip. Do NOT give them this folder: it carries 84 MB of Windows
REM  PHP, your local test database, and a script that invents fake charges,
REM  none of which belong on a university server.
REM ===========================================================================

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "tools\build-release.ps1"

if errorlevel 1 (
  echo.
  echo   The build did not finish. Nothing was written.
  echo.
) else (
  echo   Opening the folder...
  if exist "dist" start "" "dist"
)

pause
