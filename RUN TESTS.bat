@echo off
REM ===========================================================================
REM  Shared Laboratory Equipment System - run the regression tests.
REM
REM  DOUBLE-CLICK THIS FILE before putting a change on the server.
REM
REM  It builds a scratch database in the Windows temporary folder from the same
REM  schema install.php uses, checks the rules that cost money or leak data when
REM  they break, and prints a line per check. It never opens data\lab.sqlite, so
REM  it is safe to run while the application is in use.
REM
REM  Green means the rules still hold. Anything that says FAIL is listed again
REM  at the bottom with what was expected and what happened instead.
REM ===========================================================================

setlocal
set "PHPDIR=%~dp0tools\php\"
if not exist "%PHPDIR%php.exe" set "PHPDIR=%LOCALAPPDATA%\php83\"
set "PHP=%PHPDIR%php.exe"

title Lab Equipment System - tests

if not exist "%PHP%" (
  echo.
  echo   PHP was not found. Looked in:
  echo     %~dp0tools\php\php.exe
  echo     %LOCALAPPDATA%\php83\php.exe
  echo.
  echo   If this folder is on OneDrive, it has probably not finished downloading.
  echo   Right-click the folder, choose "Always keep on this device", wait for the
  echo   green checks, and start this again.
  echo.
  pause
  exit /b 1
)

cd /d "%~dp0"

"%PHP%" -c "%PHPDIR%php.ini" -d "extension_dir=%PHPDIR%ext" "tests\run-tests.php"
set "RESULT=%errorlevel%"

echo.
if "%RESULT%"=="0" (
  echo   Everything holds. Safe to deploy.
) else (
  echo   SOMETHING IS BROKEN. Do not deploy until the failures above are fixed.
)
echo.
pause
exit /b %RESULT%
