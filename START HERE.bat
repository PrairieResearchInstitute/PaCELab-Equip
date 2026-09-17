@echo off
REM ===========================================================================
REM  Shared Laboratory Equipment System - start it and open it.
REM
REM  DOUBLE-CLICK THIS FILE. Do not double-click index.php: Windows hands a
REM  .php file straight to Chrome, and Chrome cannot run PHP, so all you see is
REM  the source code. PHP runs on a web server. This starts one.
REM
REM  Leave the console window open while you use the application. Closing it
REM  stops the server.
REM
REM  PHP travels inside this folder, in tools\php, so the system runs on any
REM  computer the folder syncs to. It used to live only in this computer's
REM  %LOCALAPPDATA%\php83, which is still used if the folder copy is missing.
REM ===========================================================================

setlocal
set "PHPDIR=%~dp0tools\php\"
if not exist "%PHPDIR%php.exe" set "PHPDIR=%LOCALAPPDATA%\php83\"
set "PHP=%PHPDIR%php.exe"
set "PORT=8080"
set "URL=http://localhost:%PORT%/home.php"

title Lab Equipment System - server (keep this window open)

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

REM If a server is already listening on the port, just open the browser at it
REM rather than starting a second one that would fail to bind.
netstat -ano | findstr /r /c:"LISTENING" | findstr /c:":%PORT% " >nul 2>&1
if %errorlevel%==0 (
  echo.
  echo   A server is already running on port %PORT%. Opening the browser.
  echo.
  start "" "%URL%"
  exit /b 0
)

echo.
echo   Shared Laboratory Equipment System
echo   ==================================
echo.
echo   Serving : %~dp0
echo   Open at : %URL%
echo   PHP     : %PHP%
echo.
echo   Not installed yet? Go to http://localhost:%PORT%/install.php
echo.
echo   KEEP THIS WINDOW OPEN. Closing it stops the server.
echo.

start "" "%URL%"
REM extension_dir is given here rather than in php.ini, so the ini names no
REM path on any one computer.
"%PHP%" -c "%PHPDIR%php.ini" -d "extension_dir=%PHPDIR%ext" -S localhost:%PORT% -t "%~dp0."

endlocal
