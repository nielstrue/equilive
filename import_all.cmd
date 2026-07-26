@echo off
REM ============================================================
REM  Equilive - ENGANGS backfill af alle aargange
REM  Indlaeser alle data\officials_*.csv (2019..2026 osv.).
REM  Aarstal udledes automatisk af hvert filnavn.
REM ============================================================
setlocal enableextensions

set "APP=C:\Users\niels\dev\equilive"

set "PHP="
for /d %%D in ("C:\wamp64\bin\php\php*") do set "PHP=%%D\php.exe"
if not defined PHP (
    echo Fandt ingen PHP under C:\wamp64\bin\php\ - saet PHP manuelt i denne fil.
    exit /b 1
)

set "LOG=%APP%\data\import.log"
echo.>> "%LOG%"
echo ==== BACKFILL %date% %time% ==== >> "%LOG%"

REM Indlaes hele data-mappen (alle officials_*.csv). En koersel; dedup sikrer
REM at gentagne koersler ikke laver dubletter.
"%PHP%" "%APP%\cli\import.php" "%APP%\data"

echo.
echo Faerdig. Se ogsaa loggen: %LOG%
pause
endlocal
