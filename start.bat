@echo off
title Locadev Server Launcher
cd /d "%~dp0"
call "%~dp0bin\locadev.cmd" start
echo.
echo This window will close automatically in 3 seconds...
%SystemRoot%\System32\timeout.exe /t 3 /nobreak >nul 2>&1
exit /b 0
