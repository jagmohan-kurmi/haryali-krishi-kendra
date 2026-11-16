@echo off
title Kisan Seva Krishi Kendra - Local Server
cd /d "%~dp0"
chcp 65001 >nul
echo ======================================================
echo   🌾 किसान सेवा कृषि केंद्र (Agri Solutions)
echo ======================================================
echo Starting local web server on port 5050...
echo.
where node >nul 2>nul
if %ERRORLEVEL% equ 0 (
    node server.js
) else (
    echo Node.js not found in PATH, opening directly in your browser...
    start "" "%~dp0index.html"
)
pause
