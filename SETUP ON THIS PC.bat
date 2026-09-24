@echo off
REM ===========================================================================
REM  Shared Laboratory Equipment - set up THIS computer.
REM
REM  Run this once on each machine you use. The application folder travels with
REM  OneDrive; the instruction to start it at logon does not, because that lives
REM  in this computer's own user profile.
REM
REM  It installs the logon shortcut and a Desktop shortcut, starts the server,
REM  and tells you if OneDrive has not finished downloading PHP yet.
REM ===========================================================================

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "tools\setup-machine.ps1"
