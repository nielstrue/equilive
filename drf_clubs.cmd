@echo off
REM ============================================================
REM  Equilive - opdater DRF klubliste (find-klubber)
REM  Koeres ved lejlighed for at udfylde clubs.distrikt.
REM  Henter live som standard; falder ikke tilbage til fil her.
REM ============================================================
setlocal enableextensions
set "APP=C:\Users\niels\dev\equilive"
set "PHP="
for /d %%D in ("C:\wamp64\bin\php\php*") do set "PHP=%%D\php.exe"
if not defined PHP ( echo Fandt ingen PHP under C:\wamp64\bin\php\ & exit /b 1 )

set "LOG=%APP%\data\drf_clubs_import.log"
echo.>> "%LOG%"
echo ==== %date% %time% ==== >> "%LOG%"

REM Live-hentning. Vil du bruge en gemt fil i stedet: skift til  --file
"%PHP%" "%APP%\cli\import_drf_clubs.php"

pause
endlocal
