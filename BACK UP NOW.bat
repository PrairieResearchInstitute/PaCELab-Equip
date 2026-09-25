@echo off
REM ===========================================================================
REM  Take a backup of the database now, and prove it can be restored.
REM
REM  A backup is taken automatically once a day while the server is running, so
REM  you should not normally need this. Use it before anything risky.
REM
REM  Backups go to the "PaCELab Equip Backups" folder beside this one. To send
REM  them somewhere else - Taiga, once this is on the server - put that path on
REM  a single line in data\backup-path.txt.
REM ===========================================================================

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "tools\backup.ps1"
echo.
echo   Now proving the newest backup can be restored...
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "tools\backup.ps1" -Restore
echo.
pause
