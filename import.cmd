@echo off
REM ============================================================
REM  Equilive - ugentlig CSV-import (lokal koersel via WAMP)
REM  Koer manuelt ved dobbeltklik, eller planlaeg via
REM  Windows Task Scheduler (se README / bunden af filen).
REM ============================================================
setlocal enableextensions

REM --- 1) Stier: tilpas APP til hvor appen ligger ---
set "APP=C:\Users\niels\dev\equilive"

REM --- 2) PHP: find nyeste WAMP-PHP automatisk ---
REM     (eller saet stien haardt, fx:  set "PHP=C:\wamp64\bin\php\php8.3.14\php.exe")
set "PHP="
for /d %%D in ("C:\wamp64\bin\php\php*") do set "PHP=%%D\php.exe"
if not defined PHP (
    echo Fandt ingen PHP under C:\wamp64\bin\php\ - saet PHP manuelt i denne fil.
    exit /b 1
)

REM --- 3) CSV-fil og logfil ---
set "CSV_URL=https://api.equilive.dk/DRF/officials_2026.csv"
set "CSV=%APP%\data\officials_2026.csv"
set "LOG=%APP%\data\import.log"

echo.>> "%LOG%"
echo ==== %date% %time% ==== >> "%LOG%"

REM --- 4) (Valgfrit) hent nyeste CSV foer import ---
REM     Fjern "REM " foran naeste linje for at slaa download til.
REM curl -fsS -o "%CSV%" "%CSV_URL%" >> "%LOG%" 2>&1

REM --- 5) Koer importen ---
"%PHP%" "%APP%\cli\import.php" "%CSV%" >> "%LOG%" 2>&1
set "RC=%ERRORLEVEL%"

if "%RC%"=="0" (
    echo Import OK >> "%LOG%"
) else (
    echo Import FEJLEDE ^(kode %RC%^) >> "%LOG%"
)

endlocal & exit /b %RC%

REM ============================================================
REM  Planlaeg ugentligt (kort vej) - koer een gang i en
REM  Administrator-cmd for at oprette opgaven:
REM
REM    schtasks /Create /TN "Equilive ugentlig import" ^
REM      /TR "C:\Users\niels\dev\equilive\import.cmd" ^
REM      /SC WEEKLY /D MON /ST 06:00 /RL LIMITED /F
REM
REM  Fjern igen med:  schtasks /Delete /TN "Equilive ugentlig import" /F
REM ============================================================
