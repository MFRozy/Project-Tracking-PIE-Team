@echo off
title Update Repository GitHub - Project Tracking PIE Team
cd /d "%~dp0"

set "GIT_EXE=C:\laragon\bin\git\bin\git.exe"
if not exist "%GIT_EXE%" (
    where git >nul 2>nul
    if %errorlevel% equ 0 (
        set "GIT_EXE=git"
    ) else (
        echo [ERROR] Git tidak ditemukan di C:\laragon\bin\git\bin\git.exe
        pause
        exit /b 1
    )
)

echo ========================================================
echo   Sinkronisasi ProjectTracker ke GitHub Repository
echo   Target: https://github.com/ayuyozy-ai/Project-Tracking-PIE-Team.git
echo ========================================================
echo.

"%GIT_EXE%" config credential.helper manager

REM Cek apakah ada perubahan file baru yang belum di-commit
"%GIT_EXE%" status -s
echo.

set /p MSG="Masukkan catatan update (tekan Enter untuk langsung kirim commit yang sudah ada): "
if not "%MSG%"=="" (
    "%GIT_EXE%" add -A
    "%GIT_EXE%" commit -m "%MSG%"
)

echo.
echo Mengambil sinkronisasi terbaru dari GitHub...
"%GIT_EXE%" pull --rebase origin main

echo.
echo Mengirim data ke GitHub (jika muncul jendela login GitHub, klik 'Sign in with your browser')...
"%GIT_EXE%" push -u origin main

if %errorlevel% equ 0 (
    echo.
    echo [BERHASIL] Repository GitHub Anda sudah ter-update!
) else (
    echo.
    echo [GAGAL] Pastikan Anda sudah login ke akun GitHub yang memiliki akses ke repository ini.
)
echo.
pause
