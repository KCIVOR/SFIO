@echo off
cd /d "%~dp0"
setlocal
where python >nul 2>nul
if %errorlevel%==0 (
    set "PY=python"
) else (
    where py >nul 2>nul
    if %errorlevel%==0 (
        set "PY=py -3"
    ) else (
        echo Python not found. Install it from https://www.python.org/downloads/
        pause
        exit /b 1
    )
)
if /i not "%1"=="nobrowser" start "" http://localhost:8080/
echo Serving the site at http://localhost:8080/
echo Press Ctrl+C to stop.
%PY% -m http.server 8080
