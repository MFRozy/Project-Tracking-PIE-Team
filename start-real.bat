@echo off
chcp 65001 >nul
cd /d "%~dp0"
title PI Project Management - Real Database Local

echo ============================================================
echo   PI Project Management - Local Server (Database Asli)
echo ============================================================
echo Database: project_tracker (Database Asli)
echo URL     : http://127.0.0.1:8080/
echo.
echo Membuka peramban...
start http://127.0.0.1:8080/
echo Menjalankan web server PHP (tekan Ctrl+C untuk berhenti)...
C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe -S 127.0.0.1:8080 -t public public/index.php
pause

