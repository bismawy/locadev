@echo off
title Stop Locadev
cd /d "%~dp0"
call "%~dp0bin\locadev.cmd" stop
%SystemRoot%\System32\timeout.exe /t 2 >nul 2>&1
