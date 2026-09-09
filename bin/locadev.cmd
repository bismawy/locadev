@echo off
setlocal

rem Locadev install root (normalized: strips the trailing \bin\..)
for %%i in ("%~dp0..") do set "LOCADEV_DIR=%%~fi"
if not exist "%LOCADEV_DIR%\Caddyfile" if exist "D:\Locadev\Caddyfile" set "LOCADEV_DIR=D:\Locadev"
if not exist "%LOCADEV_DIR%\Caddyfile" if exist "%USERPROFILE%\Locadev\Caddyfile" set "LOCADEV_DIR=%USERPROFILE%\Locadev"

rem ANSI escape codes (native, Windows 10+ terminal)
rem NOTE: the char right after ESC= is a literal 0x1B byte - do not retype this line
set "ESC="
set "C_RESET=%ESC%[0m"
set "C_GREEN=%ESC%[32m"
set "C_RED=%ESC%[31m"
set "C_YELLOW=%ESC%[33m"

set "PHPRC=%LOCADEV_DIR%\bin"
set "PATH=%LOCADEV_DIR%\bin;%LOCADEV_DIR%\bin\mariadb\bin;%PATH%"

set "ACTION=%~1"
if "%ACTION%"=="" set "ACTION=start"

if /i "%ACTION%"=="menu" goto do_menu
if /i "%ACTION%"=="start" goto do_start
if /i "%ACTION%"=="stop" goto do_stop
if /i "%ACTION%"=="restart" goto do_restart
if /i "%ACTION%"=="reload" goto do_reload
if /i "%ACTION%"=="status" goto do_status
if /i "%ACTION%"=="open" goto do_open
if /i "%ACTION%"=="help" goto do_help
if /i "%ACTION%"=="--help" goto do_help
if /i "%ACTION%"=="-h" goto do_help

echo %C_RED%[Locadev] Unknown command: %ACTION%%C_RESET%
goto do_help

:do_start
call :banner
call :body_start
echo.
call :summary
goto :eof

:do_stop
call :banner
call :body_stop
call :summary
goto :eof

:do_restart
call :banner
call :body_restart
echo.
call :summary
goto :eof

:do_reload
call :banner
call :body_reload
call :summary
goto :eof

:do_status
call :banner
call :summary
goto :eof

:do_open
start https://localhost
goto :eof

:do_help
call :banner
echo Usage: locadev [command]
echo.
echo Commands:
echo   start       Start FrankenPHP and MariaDB in background (default)
echo   stop        Stop all Locadev services
echo   restart     Restart all Locadev services
echo   reload      Hot reload Caddyfile without downtime
echo   status      Show running status of services
echo   menu        Interactive control menu
echo   open        Open dashboard in default web browser
goto :eof

:do_menu
:menu_loop
call :banner
call :summary
echo ===================================================
echo   1) Start      2) Stop       3) Restart
echo   4) Reload     5) Status     6) Open
echo   0) Exit
echo ===================================================
set "MENU_CHOICE="
set /p MENU_CHOICE=   Choose a number and press Enter: 
if not defined MENU_CHOICE exit /b 0
if "%MENU_CHOICE%"=="0" exit /b 0
if "%MENU_CHOICE%"=="1" call :body_start
if "%MENU_CHOICE%"=="2" call :body_stop
if "%MENU_CHOICE%"=="3" call :body_restart
if "%MENU_CHOICE%"=="4" call :body_reload
if "%MENU_CHOICE%"=="6" start https://localhost
goto menu_loop

:: ===== Action bodies (no banner / no summary) =====
:body_start
call :start_db
call :start_web
goto :eof

:body_stop
echo %C_YELLOW%[Locadev] Stopping services...%C_RESET%
call :stop_cmds
echo %C_GREEN%[Locadev] All services stopped.%C_RESET%
goto :eof

:body_restart
echo %C_YELLOW%[Locadev] Stopping services...%C_RESET%
call :stop_cmds
ping -n 2 127.0.0.1 >nul 2>&1
echo %C_YELLOW%[Locadev] Starting services...%C_RESET%
call :start_db
call :start_web
goto :eof

:body_reload
echo %C_YELLOW%[Locadev] Reloading Caddyfile...%C_RESET%
"%LOCADEV_DIR%\bin\frankenphp.exe" reload --config "%LOCADEV_DIR%\Caddyfile"
if not errorlevel 1 (
    echo %C_GREEN%[Locadev] Configuration reloaded.%C_RESET%
) else (
    echo %C_RED%[Locadev] Reload failed. Is FrankenPHP running?%C_RESET%
)
goto :eof

:: ===== Shared helpers (single visual source) =====
:ensure_db_files
mkdir "%LOCADEV_DIR%\data\mariadb" 2>nul
rem my.ini needs forward slashes - backslash is an escape char to MariaDB's parser
set "LOCADEV_DIR_FWD=%LOCADEV_DIR:\=/%"
set "DB_FRESH=0"
if not exist "%LOCADEV_DIR%\data\mariadb\mysql" (
    rem mariadb-install-db requires an EMPTY datadir - drop our my.ini first
    del "%LOCADEV_DIR%\data\mariadb\my.ini" >nul 2>&1
    echo %C_YELLOW%[Locadev] Initializing MariaDB data directory - first run...%C_RESET%
    "%LOCADEV_DIR%\bin\mariadb\bin\mariadb-install-db.exe" --datadir="%LOCADEV_DIR%\data\mariadb" >nul 2>&1
    set "DB_FRESH=1"
)
if not exist "%LOCADEV_DIR%\data\mariadb\my.ini" set "DB_FRESH=1"
if "%DB_FRESH%"=="1" (
    echo %C_YELLOW%[Locadev] Writing MariaDB config - first run...%C_RESET%
    (
        echo [mysqld]
        echo datadir=%LOCADEV_DIR_FWD%/data/mariadb
        echo port=3306
        echo bind-address=127.0.0.1
        echo innodb_use_native_aio=0
        echo lower_case_table_names=1
        echo max_allowed_packet=64M
        echo character-set-server=utf8mb4
        echo collation-server=utf8mb4_unicode_ci
        echo performance_schema=OFF
        echo max_connections=20
        echo key_buffer_size=8M
        echo aria_pagecache_buffer_size=8M
        echo tmp_table_size=16M
        echo max_heap_table_size=16M
        echo innodb_flush_log_at_trx_commit=2
        echo innodb_flush_neighbors=0
        echo skip_name_resolve=1
        echo innodb_log_file_size=32M
        echo innodb_buffer_pool_size=64M
        echo [client]
        echo port=3306
        echo default-character-set=utf8mb4
    ) > "%LOCADEV_DIR%\data\mariadb\my.ini"
)
goto :eof

:banner
echo ===================================================
echo   Locadev - Just runs. Natively.
echo ===================================================
goto :eof

:summary
call :web_up
if not errorlevel 1 (
    echo   FrankenPHP : %C_GREEN%[ONLINE]%C_RESET%   https://localhost
) else (
    echo   FrankenPHP : %C_RED%[OFFLINE]%C_RESET%
)
netstat -an | findstr /R "127.0.0.1:3306[^0-9].*LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo   MariaDB    : %C_GREEN%[ONLINE]%C_RESET%   127.0.0.1:3306
) else (
    echo   MariaDB    : %C_RED%[OFFLINE]%C_RESET%
)
echo ===================================================
goto :eof

:stop_cmds
curl -s -X POST http://127.0.0.1:2019/stop >nul 2>&1
rem wait for graceful shutdown (process gone), force-kill is only a fallback
set STOP_TRIES=0
:wait_web_down
tasklist /FI "IMAGENAME eq frankenphp.exe" | findstr /I frankenphp >nul 2>&1
if errorlevel 1 goto web_killed
set /a STOP_TRIES+=1
if %STOP_TRIES% GTR 5 goto web_killed
ping -n 2 127.0.0.1 >nul 2>&1
goto wait_web_down
:web_killed
taskkill /F /IM frankenphp.exe >nul 2>&1
"%LOCADEV_DIR%\bin\mariadb\bin\mariadb-admin.exe" -u root shutdown >nul 2>&1
rem port 3306 closes before InnoDB finishes flushing - wait for the PROCESS, not the port
set STOP_TRIES=0
:wait_db_down
tasklist /FI "IMAGENAME eq mariadbd.exe" | findstr /I mariadbd >nul 2>&1
if errorlevel 1 goto db_killed
set /a STOP_TRIES+=1
if %STOP_TRIES% GTR 10 goto db_killed
ping -n 2 127.0.0.1 >nul 2>&1
goto wait_db_down
:db_killed
taskkill /F /IM mariadbd.exe >nul 2>&1
goto :eof

:web_up
netstat -an | findstr /R ":8080[^0-9].*LISTENING :443[^0-9].*LISTENING" >nul 2>&1
goto :eof

:start_db
netstat -an | findstr /R "127.0.0.1:3306[^0-9].*LISTENING" >nul 2>&1
if not errorlevel 1 goto :eof
call :ensure_db_files
echo %C_YELLOW%[Locadev] Starting MariaDB...%C_RESET%
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%LOCADEV_DIR%\bin\mariadb\bin\mariadbd.exe' -ArgumentList '--defaults-file=\"\"%LOCADEV_DIR%\data\mariadb\my.ini\"\"' -WindowStyle Hidden"
set DB_TRIES=0
:wait_db
netstat -an | findstr /R "127.0.0.1:3306[^0-9].*LISTENING" >nul 2>&1
if not errorlevel 1 goto :eof
set /a DB_TRIES+=1
if %DB_TRIES% GTR 30 (
    echo %C_RED%[Locadev] ERROR: MariaDB did not start within 30 seconds. Check data\mariadb\*.err%C_RESET%
    goto :eof
)
ping -n 2 127.0.0.1 >nul 2>&1
goto wait_db

:start_web
call :web_up
if not errorlevel 1 goto :eof
echo %C_YELLOW%[Locadev] Starting FrankenPHP...%C_RESET%
powershell -NoProfile -ExecutionPolicy Bypass -Command "$env:PHPRC='%LOCADEV_DIR%\bin'; Start-Process -FilePath '%LOCADEV_DIR%\bin\frankenphp.exe' -ArgumentList 'run --config Caddyfile' -WorkingDirectory '%LOCADEV_DIR%' -WindowStyle Hidden"
set WEB_TRIES=0
:wait_web
call :web_up
if not errorlevel 1 goto :eof
set /a WEB_TRIES+=1
if %WEB_TRIES% GTR 30 (
    echo %C_RED%[Locadev] ERROR: FrankenPHP did not start within 30 seconds. Check the Caddyfile.%C_RESET%
    goto :eof
)
ping -n 2 127.0.0.1 >nul 2>&1
goto wait_web
