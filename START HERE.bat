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
REM  The work is done by tools\start-server.ps1, because finding out whether a
REM  server on a port is OURS needs more than batch can do. It is not enough to
REM  ask whether the port is busy: PRI Facilities Request also serves itself on
REM  8080, and answering "something is listening, that must be us" sent the
REM  browser to that application instead, which replied 404 for /home.php.
REM ===========================================================================

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "tools\start-server.ps1"

if errorlevel 1 (
  echo.
  echo   The server did not start. The message above says why.
  echo.
  pause
)
