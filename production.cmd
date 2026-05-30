@echo off
REM ============================================================================
REM production.cmd  ?  produkcni deploy
REM
REM Workflow:
REM   1. pnpm build (Vue produkcni build do web/dist/)
REM   2. backup api/vendor (rename na vendor.dev.bak) + z?skat produkcni vendor:
REM        - cache hit (api/vendor.prod existuje, hash composer.lock sedi) = rename (instant)
REM        - cache miss = composer install --no-dev (30-60s) + ulozit hash
REM   3. php tools/generateManualHtml.php (HTML manu?l do manual/generated/)
REM      php tools/exportManualToPdf.php   (PDF manu?l do manual/manual.pdf)
REM   4. push na production remote vc. web/dist + manual/generated + manual/manual.pdf + api/vendor
REM   5. cachovat: rename api/vendor -^> api/vendor.prod (pro pristi deploy)
REM      stash web/dist + manual/generated + manual/manual.pdf do *.bak (preserve pres `git checkout master`)
REM   6. restore lokalniho stavu (rename vendor.dev.bak + dist.bak + generated.bak zpet)
REM
REM Optimalizace:
REM   - vendor.prod cache: dokud se composer.lock nezmeni, composer install se preskoci
REM   - web/dist + manual/generated stashnute pres checkout = zadny rebuild po deployi
REM   - restore dev vendoru = jen rename (~ms), zadny composer install
REM   - dev vendor zustane netknuty po celou dobu deployu
REM ============================================================================

setlocal EnableDelayedExpansion
cd /d "%~dp0"

echo.
echo === MyInvoice.cz production deploy ===
echo.

REM Safety check: pokud vendor.dev.bak existuje, predchozi beh selhal pred restore.
if exist api\vendor.dev.bak (
    echo [ABORT] api\vendor.dev.bak existuje z predchoziho behu.
    echo Recovery ? rucne zvol jednu z variant:
    echo   A^) zachovat aktualni vendor:    rmdir /s /q api\vendor.dev.bak
    echo   B^) restore puvodni dev vendor:  rmdir /s /q api\vendor ^&^& move api\vendor.dev.bak api\vendor
    exit /b 1
)

REM Auto-generated commit message (production rebuild nepotrebuje commit text,
REM produkcni server typicky ma post-receive hook a nezajima ho historie deploy commitu).
set MSG=Production rebuild %DATE% %TIME%

REM ====== 1. Frontend build ======
echo === Smazani stareho web/dist (fresh build, zadne starsi hashed assety) ===
if exist web\dist rmdir /s /q web\dist

echo.
echo === pnpm install + build ^(Vue dist^) ===
pushd web
call pnpm install
if errorlevel 1 (
    popd
    echo [ABORT] pnpm install selhal.
    exit /b 1
)
call pnpm build
if errorlevel 1 (
    popd
    echo [ABORT] pnpm build selhal.
    exit /b 1
)
popd

REM ====== 2. Production vendor (cache nebo composer install) ======
REM Backup dev vendor pres rename (instant) ? restore na konci je take rename.
echo.
echo === Backup dev vendor: api\vendor -^> api\vendor.dev.bak ===
if exist api\vendor (
    move api\vendor api\vendor.dev.bak >nul
    if errorlevel 1 (
        echo [ABORT] backup api\vendor selhal.
        exit /b 1
    )
)

REM Hash composer.lock ? cache klic. Kdyz se lock nemeni, vendor je identicky.
for /f "delims=" %%H in ('certutil -hashfile api\composer.lock MD5 ^| findstr /v ":" ^| findstr /v "CertUtil"') do set LOCK_HASH=%%H
set LOCK_HASH=!LOCK_HASH: =!

set CACHE_HIT=0
if exist api\vendor.prod\.lock-hash (
    set /p CACHED_HASH=<api\vendor.prod\.lock-hash
    if "!CACHED_HASH!"=="!LOCK_HASH!" set CACHE_HIT=1
)

if !CACHE_HIT!==1 (
    echo === Cache hit ? pouzivam api\vendor.prod ^(composer.lock beze zmeny^) ===
    move api\vendor.prod api\vendor >nul
    if errorlevel 1 (
        echo [ABORT] rename api\vendor.prod -^> api\vendor selhal.
        echo Recovery: move api\vendor.dev.bak api\vendor
        exit /b 1
    )
) else (
    echo === Cache miss ? composer install --no-dev ^(stale nebo prvni beh^) ===
    if exist api\vendor.prod rmdir /s /q api\vendor.prod
    pushd api
    call composer install --no-dev --optimize-autoloader --no-interaction
    if errorlevel 1 (
        popd
        echo [ABORT] composer install selhal ? api\vendor.dev.bak zachovan.
        echo Recovery: move api\vendor.dev.bak api\vendor
        exit /b 1
    )
    popd
    >api\vendor\.lock-hash echo !LOCK_HASH!
)

REM ====== 3. Manual HTML + PDF build ======
echo.
REM manual/generated se NEMAZE rucne — generateManualHtml.php sam unlinkne stara
REM *.html a prepise _toc.php + search-index.json (fresh rebuild). exportManualToPdf.php
REM prepise manual.pdf. Drivejsi `rmdir`/`del` jen zbytecne padaly na file-locku, kdyz
REM mel AV (Defender) / editor otevreny handle na souboru ve slozce.

echo === Generate manual HTML ===
php tools\generateManualHtml.php
if errorlevel 1 (
    echo [ABORT] manual generator selhal.
    exit /b 1
)

echo === Generate manual PDF ===
php tools\exportManualToPdf.php
if errorlevel 1 (
    echo [ABORT] manual PDF export selhal.
    exit /b 1
)

REM ====== 4. Push na production (s built artefakty) ======
REM `-c core.autocrlf=false` = vypne CRLF konverzi pro tyto prikazy:
REM   - zadne "LF will be replaced by CRLF" warningy
REM   - rychlejsi `git add` (zadny hash-rewrite) na ~4000 souborech vendor + dist
echo.
echo === Push na production ^(s web/dist + manual/generated + api/vendor^) ===
set TMP_BRANCH=deploy-temp
set GITQ=-c core.autocrlf=false -c core.safecrlf=false
git !GITQ! branch -D !TMP_BRANCH! 2>nul
git !GITQ! checkout -q -b !TMP_BRANCH!
if errorlevel 1 (
    echo [ABORT] checkout !TMP_BRANCH! selhal.
    exit /b 1
)

REM Force-add gitignored artefakty (na origin nepujdou, jen na production).
REM -q na commitu = bez "create mode" listu pro vsechny vendor soubory.
git !GITQ! add -f web/dist manual/generated manual/manual.pdf api/vendor

REM Jednorazovy externi migracni nastroj (Money S3) nepatri na produkci.
REM export-ignore plati jen pro `git archive`, ne pro `git push` ? odstranime z indexu
REM throwaway vetve (--cached = working tree zustava, master netknuty).
git !GITQ! rm -r --cached -q tools/money-s3-import 2>nul

git !GITQ! commit -q -m "Build artifacts: !MSG!" --allow-empty

git !GITQ! push --quiet production !TMP_BRANCH!:master --force
set PUSH_RC=!errorlevel!

REM Cache produkcni vendor pred `git checkout master` (jinak by ho checkout smazal ?
REM v master je vendor untracked, v deploy-temp tracked, takze checkout ho odstrani).
echo.
echo === Cache produkcniho vendoru: api\vendor -^> api\vendor.prod ===
if exist api\vendor (
    if exist api\vendor.prod rmdir /s /q api\vendor.prod
    move api\vendor api\vendor.prod >nul
    if errorlevel 1 (
        echo [WARN] cache vendor selhala ? pristi deploy spusti composer install znovu.
    )
)

REM Stash web/dist a manual/generated mimo working tree, jinak je `git checkout master`
REM smaze (tracked v deploy-temp, untracked v master) a museli bychom je rebuildovat.
echo.
echo === Stash web/dist + manual/generated + manual.pdf pred checkout master ===
REM robocopy /MOVE /R:15 /W:1 misto `move` — pokud ma AV (Defender) / editor kratce
REM otevreny handle na cerstve vygenerovanem souboru, `move` hned spadne a nasledny
REM `git checkout master` pak visi na "Deletion of directory failed. Try again? (y/n)".
REM robocopy zamek prepocka (az 15x 1s). Zdrojova slozka se po MOVE smaze.
if exist web\dist.bak rmdir /s /q web\dist.bak
if exist web\dist robocopy web\dist web\dist.bak /MOVE /E /R:15 /W:1 /NFL /NDL /NJH /NJS /NP >nul
if exist manual\generated.bak rmdir /s /q manual\generated.bak
if exist manual\generated robocopy manual\generated manual\generated.bak /MOVE /E /R:15 /W:1 /NFL /NDL /NJH /NJS /NP >nul
if exist manual\manual.pdf.bak del /q manual\manual.pdf.bak
if exist manual\manual.pdf move manual\manual.pdf manual\manual.pdf.bak >nul

REM tools/money-s3-import: na deploy-temp jsme ho odebrali z indexu (nema jit na
REM produkci), cimz se ve working tree stal untracked. Na master je ale TRACKED,
REM takze `git checkout master` by jinak selhal ("untracked working tree files
REM would be overwritten") a nechal by nas trcet na deploy-temp. Smazat working
REM copy; checkout master ji obnovi z master HEAD (committed, nic se neztrati).
if exist tools\money-s3-import rmdir /s /q tools\money-s3-import

REM Vzdy se vratit zpet na master + cleanup.
REM POZN.: vse co bylo committed v deploy-temp ale netracked v master uz neni v
REM working tree (vyse stashnuto/cachnuto), takze checkout master nic nesmaze.
git !GITQ! checkout -q master
git !GITQ! branch -D !TMP_BRANCH! >nul

REM ====== 6. Restore lokalniho stavu ======
echo.
echo === Restore dev vendor: api\vendor.dev.bak -^> api\vendor ===
if exist api\vendor.dev.bak (
    if exist api\vendor rmdir /s /q api\vendor
    move api\vendor.dev.bak api\vendor >nul
    if errorlevel 1 (
        echo [WARN] restore vendor.dev.bak selhal ? spust rucne:
        echo   move api\vendor.dev.bak api\vendor
    )
) else (
    echo [WARN] api\vendor.dev.bak neexistuje ? backup pred composerem chybel?
)

echo.
echo === Restore web/dist + manual/generated + manual.pdf z stashe ===
if exist web\dist.bak (
    if exist web\dist rmdir /s /q web\dist
    move web\dist.bak web\dist >nul
)
if exist manual\generated.bak (
    if exist manual\generated rmdir /s /q manual\generated
    move manual\generated.bak manual\generated >nul
)
if exist manual\manual.pdf.bak (
    if exist manual\manual.pdf del /q manual\manual.pdf
    move manual\manual.pdf.bak manual\manual.pdf >nul
)

if not !PUSH_RC!==0 (
    echo [ABORT] push na production selhal.
    exit /b 1
)

echo.
echo ============================================================
echo  HOTOVO
echo  - build pushnut na production ^(vc. web/dist + manual/generated + manual.pdf + api/vendor^)
echo  - lokalni stav restored ^(dev composer + fresh build^)
echo.
echo  Na produkci jeste musis: cp cfg.sample.php cfg.php + vyplnit + php api/bin/migrate.php
echo ============================================================
exit /b 0
