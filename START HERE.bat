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
REM ===========================================================================

setlocal
set "PHP=%LOCALAPPDATA%\php83\php.exe"
set "PORT=8080"
set "URL=http://localhost:%PORT%/home.php"

title Lab Equipment System - server (keep this window open)

if not exist "%PHP%" (
  echo.
  echo   PHP was not found at:
  echo     %PHP%
  echo.
  echo   Extract the Windows "non thread safe" PHP zip from windows.php.net
  echo   into that folder, and give its php.ini these two lines:
  echo.
  echo     extension_dir = "%LOCALAPPDATA%\php83\ext"
  echo     extension=pdo_sqlite
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
echo.
echo   Not installed yet? Go to http://localhost:%PORT%/install.php
echo.
echo   KEEP THIS WINDOW OPEN. Closing it stops the server.
echo.

start "" "%URL%"
"%PHP%" -c "%LOCALAPPDATA%\php83\php.ini" -S localhost:%PORT% -t "%~dp0"

endlocal
