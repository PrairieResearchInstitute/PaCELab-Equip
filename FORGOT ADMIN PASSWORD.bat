@echo off
REM ===========================================================================
REM  Shared Laboratory Equipment System - get back into the admin panel.
REM
REM  DOUBLE-CLICK THIS FILE when you are locked out: forgotten password, or too
REM  many failed sign-ins and the throttle will not let you try again.
REM
REM  It lists the administrator accounts, then asks which one to reset and what
REM  to set it to. The password is not echoed as you type, and entering nothing
REM  at the username prompt leaves everything untouched.
REM
REM  This changes YOUR LOCAL copy only. It never goes to the server: the build
REM  refuses to ship it, and admin-recovery.php itself answers 403 to any web
REM  request, so it only ever runs from a command line.
REM
REM  The php flags are written out at each call rather than kept in a variable.
REM  A variable holding quoted paths has to nest quotes inside SET, and cmd gets
REM  that wrong -- it fails with "The syntax of the command is incorrect."
REM ===========================================================================

setlocal
set "PHPDIR=%~dp0tools\php\"
if not exist "%PHPDIR%php.exe" set "PHPDIR=%LOCALAPPDATA%\php83\"
set "PHP=%PHPDIR%php.exe"

title Lab Equipment System - administrator recovery

if not exist "%PHP%" (
  echo.
  echo   PHP was not found. Looked in:
  echo     %~dp0tools\php\php.exe
  echo     %LOCALAPPDATA%\php83\php.exe
  echo.
  pause
  exit /b 1
)

cd /d "%~dp0"

echo.
echo   Administrator accounts on this computer
echo   ======================================
echo.
"%PHP%" -c "%PHPDIR%php.ini" -d "extension_dir=%PHPDIR%ext" admin-recovery.php list
echo.

set "WHO="
set /p "WHO=  Which username? (blank to cancel): "
if not defined WHO goto :cancelled

echo.
echo   Clearing any failed sign-in attempts first...
"%PHP%" -c "%PHPDIR%php.ini" -d "extension_dir=%PHPDIR%ext" admin-recovery.php unlock "%WHO%"

echo.
echo   Now the new password. Ten characters or more.
echo.
"%PHP%" -c "%PHPDIR%php.ini" -d "extension_dir=%PHPDIR%ext" admin-recovery.php reset "%WHO%"

echo.
echo   Start the application with START HERE.bat and sign in at
echo     http://localhost:8080/admin/login.php
echo.
pause
exit /b 0

:cancelled
echo.
echo   Cancelled. Nothing was changed.
echo.
pause
exit /b 0
