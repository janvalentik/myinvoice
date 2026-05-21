@echo off
REM ============================================================================
REM  cron-scan-purchase-inbox.cmd — auto-import prijatych faktur (PDF / ISDOC)
REM  Frekvence: kazdych 5-15 minut (dodavatele posilaji PDF prubezne)
REM  Skenuje cfg.purchase_invoice.inbox_dir, podporuje PDF, ISDOC, XML.
REM
REM  Workflow per soubor:
REM    1. SHA-256 dedup vuci purchase_invoices.pdf_hash
REM    2. Embedded ISDOC v PDF → ISDOC parser (priorita, zdarma)
REM    3. PDF bez ISDOC + tenant ma AI nakonfigurovanou → AI extract
REM    4. Jinak skip
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyInvoice ScanPurchaseInbox" ^
REM      /tr "%~f0" /sc minute /mo 10 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
set "LOG_DIR=%PROJECT_ROOT%\log\cron"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-scan-purchase-inbox.php" %* >> "%LOG_DIR%\scan-purchase-inbox-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
