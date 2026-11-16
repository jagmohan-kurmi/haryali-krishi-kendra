@echo off
title Hariyali Krishi Kendra Patera - Local Server
cd /d "%~dp0"
chcp 65001 >nul
echo ======================================================
echo   🌾 हरियाली कृषि केंद्र पटेरा (Agri Solutions)
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
