@echo off
cd /d "%~dp0"
setlocal

where php >nul 2>nul
if %errorlevel%==0 (
    if /i not "%1"=="nobrowser" start "" http://localhost:8080/
    echo Serving the site with PHP at http://localhost:8080/
    echo Press Ctrl+C to stop.
    php -S localhost:8080
    exit /b 0
)

where python >nul 2>nul
if %errorlevel%==0 (
    set "PY=python"
) else (
    where py >nul 2>nul
    if %errorlevel%==0 (
        set "PY=py -3"
    ) else (
        echo Neither PHP nor Python found.
        pause
        exit /b 1
    )
)
if /i not "%1"=="nobrowser" start "" http://localhost:8080/
echo Serving the site with Python at http://localhost:8080/
echo Note: Python http.server does not support PHP backend execution.
echo Press Ctrl+C to stop.
%PY% -m http.server 8080
