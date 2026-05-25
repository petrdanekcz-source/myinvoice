@echo off
REM Stáhne XSD schémata do api/xsd/ (Windows verze): EPO MFČR výkazy (DPH/KH/SH/
REM DPFO/DPPO) + ISDOC 6.0.2 (formát faktur). Default jsou commitnutá v repo —
REM skript použij jen pro upgrade na nové ročníky MFČR, příp. novou verzi ISDOC.
REM
REM Pouziti:
REM   cmd\download-xsd.cmd           — stáhne všechna schémata (EPO + ISDOC)
REM   cmd\download-xsd.cmd dphkh1    — stáhne jen jedno EPO schema
REM   cmd\download-xsd.cmd isdoc     — stáhne jen ISDOC schema

setlocal EnableDelayedExpansion

set "DIR=%~dp0..\api\xsd"
set "BASE=https://adisspr.mfcr.cz/adis/jepo/schema"
set "ISDOC_URL=https://isdoc.cz/6.0.2/xsd/isdoc-invoice-6.0.2.xsd"

if not exist "%DIR%" mkdir "%DIR%"

if "%~1"=="" (
    set "FORMS=dphdp3 dphkh1 dphshv dpfdp5 dppdp9 isdoc"
) else (
    set "FORMS=%*"
)

for %%F in (%FORMS%) do (
    if /I "%%F"=="isdoc" (
        echo -^> isdoc: %ISDOC_URL%
        powershell -NoProfile -Command "try { Invoke-WebRequest -Uri '%ISDOC_URL%' -OutFile '%DIR%\isdoc-invoice-6.0.2.xsd' -UseBasicParsing; Write-Host '  OK' } catch { Write-Host '  FAIL:' $_.Exception.Message }"
    ) else (
        echo -^> %%F: %BASE%/%%F_epo2.xsd
        powershell -NoProfile -Command "try { Invoke-WebRequest -Uri '%BASE%/%%F_epo2.xsd' -OutFile '%DIR%\%%F.xsd' -UseBasicParsing; Write-Host '  OK' } catch { Write-Host '  FAIL:' $_.Exception.Message }"
    )
)

echo.
echo Hotovo. Schemata v: %DIR%
endlocal
