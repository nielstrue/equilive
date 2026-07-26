@echo off
REM ============================================================
REM  Equilive - opdater DRF officials-liste (find-dommer)
REM  Koeres ved lejlighed for at ajourfoere + tjekke datakvalitet.
REM  Henter live som standard; falder ikke tilbage til fil her.
REM ============================================================
setlocal enableextensions
set "APP=C:\Users\niels\dev\equilive"
set "PHP="
for /d %%D in ("C:\wamp64\bin\php\php*") do set "PHP=%%D\php.exe"
if not defined PHP ( echo Fandt ingen PHP under C:\wamp64\bin\php\ & exit /b 1 )

set "LOG=%APP%\data\drf_import.log"
echo.>> "%LOG%"
echo ==== %date% %time% ==== >> "%LOG%"

REM Live-hentning. Vil du bruge en gemt fil i stedet: skift til  --file
"%PHP%" "%APP%\cli\import_drf.php"

pause
endlocal
