@echo off
chcp 65001 >nul
cd /d "%~dp0"
title PI Project Management - Local Test Server

set "DB_HOST=127.0.0.1"
set "DB_PORT=3306"
set "DB_NAME=project_tracker_test"
set "DB_USER=pt_test"
set "DB_PASS=pt_test_local_only"
set "PATH=C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64;%PATH%"

echo ============================================================
echo   PI Project Management - Local Test Environment
echo ============================================================
echo Database: %DB_NAME% (Aman, data dummy terisolasi)
echo URL     : http://127.0.0.1:8080/
echo.
echo Akun Login Uji Coba:
echo   1. Admin       : admin / Admin#2026!
echo   2. User (PIC)  : budi  / UserBudi#2026!
echo   3. Contributor : dina  / Contrib#2026!
echo   4. Viewer      : vino  / Viewer#2026!
echo ============================================================
echo Membuka peramban...
start http://127.0.0.1:8080/
echo Menjalankan web server PHP (tekan Ctrl+C untuk berhenti)...
php -S 127.0.0.1:8080 -t public public/index.php
pause

