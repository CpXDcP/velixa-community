@echo off
chcp 65001 >nul
title Velixa for Gen AI v1.1.0 — Installation Windows

echo.
echo  ██╗   ██╗███████╗██╗     ██╗██╗  ██╗ █████╗
echo  ██║   ██║██╔════╝██║     ██║╚██╗██╔╝██╔══██╗
echo  ██║   ██║█████╗  ██║     ██║ ╚███╔╝ ███████║
echo  ╚██╗ ██╔╝██╔══╝  ██║     ██║ ██╔██╗ ██╔══██║
echo   ╚████╔╝ ███████╗███████╗██║██╔╝ ██╗██║  ██║
echo    ╚═══╝  ╚══════╝╚══════╝╚═╝╚═╝  ╚═╝╚═╝  ╚═╝
echo.
echo  Velixa for Gen AI v1.1.0 — Community Edition
echo  Governing AI with Confidence
echo  Licence AGPL v3
echo.
echo ══════════════════════════════════════════════════════
echo  Script d'installation Windows / XAMPP
echo ══════════════════════════════════════════════════════
echo.

:: ── Variables ──────────────────────────────────────────
set XAMPP_PATH=C:\xampp
set VELIXA_PATH=%XAMPP_PATH%\htdocs\velixa
set PHP_PATH=%XAMPP_PATH%\php\php.exe
set PHP_INI=%XAMPP_PATH%\php\php.ini
set ERRORS=0

echo  [1/7] Verification de XAMPP...
echo.

:: ── Vérification XAMPP ─────────────────────────────────
if not exist "%XAMPP_PATH%" (
    echo  ❌ XAMPP non detecte dans %XAMPP_PATH%
    echo.
    echo  Veuillez installer XAMPP avant de continuer :
    echo  https://www.apachefriends.org
    echo.
    echo  Choisir la version avec PHP 8.1 ou superieur.
    echo  Installer dans C:\xampp (chemin par defaut).
    echo.
    set ERRORS=1
    goto :check_php
)
echo  ✅ XAMPP trouve dans %XAMPP_PATH%
echo.

:check_php
:: ── Vérification PHP ───────────────────────────────────
echo  [2/7] Verification de PHP...
echo.

if not exist "%PHP_PATH%" (
    echo  ❌ PHP non trouve dans %PHP_PATH%
    echo.
    echo  PHP est inclus dans XAMPP. Verifiez votre installation XAMPP.
    echo.
    set ERRORS=1
    goto :check_version
)

:: Vérifier version PHP
for /f "tokens=2 delims= " %%v in ('"%PHP_PATH%" -r "echo PHP_VERSION;" 2^>nul') do set PHP_VERSION=%%v
for /f "tokens=1 delims=." %%m in ("%PHP_VERSION%") do set PHP_MAJOR=%%m

if "%PHP_MAJOR%" LSS "8" (
    echo  ❌ PHP %PHP_VERSION% detecte — version 8.1 minimum requise
    echo.
    echo  Mettez a jour XAMPP pour obtenir PHP 8.1+
    echo  https://www.apachefriends.org
    echo.
    set ERRORS=1
) else (
    echo  ✅ PHP %PHP_VERSION% detecte
)
echo.

:check_version
:: ── Vérification extensions PHP ────────────────────────
echo  [3/7] Verification des extensions PHP...
echo.

if not exist "%PHP_PATH%" goto :skip_ext_check

"%PHP_PATH%" -r "echo extension_loaded('curl') ? 'OK' : 'MISSING';" 2>nul | findstr "MISSING" >nul
if %errorlevel%==0 (
    echo  ❌ Extension PHP 'curl' manquante
    echo     Ouvrir %PHP_INI%
    echo     Rechercher ';extension=curl' et supprimer le point-virgule
    set ERRORS=1
) else (
    echo  ✅ curl
)

"%PHP_PATH%" -r "echo extension_loaded('openssl') ? 'OK' : 'MISSING';" 2>nul | findstr "MISSING" >nul
if %errorlevel%==0 (
    echo  ❌ Extension PHP 'openssl' manquante
    echo     Ouvrir %PHP_INI% et decommenter : extension=openssl
    set ERRORS=1
) else (
    echo  ✅ openssl
)

"%PHP_PATH%" -r "echo extension_loaded('mbstring') ? 'OK' : 'MISSING';" 2>nul | findstr "MISSING" >nul
if %errorlevel%==0 (
    echo  ❌ Extension PHP 'mbstring' manquante
    echo     Ouvrir %PHP_INI% et decommenter : extension=mbstring
    set ERRORS=1
) else (
    echo  ✅ mbstring
)

"%PHP_PATH%" -r "echo extension_loaded('fileinfo') ? 'OK' : 'MISSING';" 2>nul | findstr "MISSING" >nul
if %errorlevel%==0 (
    echo  ❌ Extension PHP 'fileinfo' manquante
    echo     Ouvrir %PHP_INI% et decommenter : extension=fileinfo
    set ERRORS=1
) else (
    echo  ✅ fileinfo
)

"%PHP_PATH%" -r "echo extension_loaded('zip') ? 'OK' : 'MISSING';" 2>nul | findstr "MISSING" >nul
if %errorlevel%==0 (
    echo  ❌ Extension PHP 'zip' manquante
    echo     Ouvrir %PHP_INI% et decommenter : extension=zip
    set ERRORS=1
) else (
    echo  ✅ zip
)

echo.
echo  ℹ  Si des extensions manquent :
echo     1. Ouvrir XAMPP Control Panel ^> Config ^> PHP (php.ini^)
echo     2. Rechercher l'extension et supprimer le ';' en debut de ligne
echo     3. Redemarrer Apache dans XAMPP Control Panel
echo.

:skip_ext_check
:: ── Création des dossiers ──────────────────────────────
echo  [4/7] Creation des dossiers necessaires...
echo.

if not exist "%VELIXA_PATH%" (
    echo  ❌ Dossier Velixa non trouve dans %VELIXA_PATH%
    echo.
    echo  Veuillez extraire le ZIP Velixa dans :
    echo  %VELIXA_PATH%
    echo.
    echo  Verifiez que le fichier dashboard.php est present dans ce dossier.
    set ERRORS=1
    goto :check_ollama
)

echo  ✅ Dossier Velixa trouve

if not exist "%VELIXA_PATH%\logs" (
    mkdir "%VELIXA_PATH%\logs"
    echo  ✅ Dossier logs cree
) else (
    echo  ✅ Dossier logs existant
)

if not exist "%VELIXA_PATH%\uploads" (
    mkdir "%VELIXA_PATH%\uploads"
    echo  ✅ Dossier uploads cree
) else (
    echo  ✅ Dossier uploads existant
)

if not exist "%VELIXA_PATH%\data" (
    mkdir "%VELIXA_PATH%\data"
    echo  ✅ Dossier data cree
) else (
    echo  ✅ Dossier data existant
)

if not exist "%VELIXA_PATH%\data\secure" (
    mkdir "%VELIXA_PATH%\data\secure"
    echo  ✅ Dossier data\secure cree
)

if not exist "%VELIXA_PATH%\data\llm_cache" (
    mkdir "%VELIXA_PATH%\data\llm_cache"
    echo  ✅ Dossier data\llm_cache cree
)

if not exist "%VELIXA_PATH%\data\legal_holds" (
    mkdir "%VELIXA_PATH%\data\legal_holds"
    echo  ✅ Dossier data\legal_holds cree
)

:: Copier users.json.example si users.json absent
if not exist "%VELIXA_PATH%\users.json" (
    if exist "%VELIXA_PATH%\users.json.example" (
        copy "%VELIXA_PATH%\users.json.example" "%VELIXA_PATH%\users.json" >nul
        echo  ✅ users.json cree depuis users.json.example
    )
)

:: Copier providers.json.example si providers.json absent
if not exist "%VELIXA_PATH%\config\providers.json" (
    if exist "%VELIXA_PATH%\config\providers.json.example" (
        copy "%VELIXA_PATH%\config\providers.json.example" "%VELIXA_PATH%\config\providers.json" >nul
        echo  ✅ config\providers.json cree depuis providers.json.example
    )
)

echo.

:check_ollama
:: ── Vérification Ollama ────────────────────────────────
echo  [5/7] Verification d'Ollama...
echo.

where ollama >nul 2>&1
if %errorlevel% neq 0 (
    echo  ❌ Ollama non detecte
    echo.
    echo  Ollama est necessaire pour les outils locaux (traduction confidentielle,
    echo  réécriture des prompts bloques).
    echo.
    echo  Installer Ollama depuis : https://ollama.com/download
    echo  Choisir la version Windows.
    echo.
    set ERRORS=1
    goto :check_python
)

echo  ✅ Ollama detecte

:: Vérifier phi3:mini
ollama list 2>nul | findstr "phi3" >nul
if %errorlevel% neq 0 (
    echo.
    echo  ⚠  phi3:mini non installe
    echo.
    echo  Voulez-vous telecharger phi3:mini maintenant ? (2.2 GB)
    echo  (Necessaire pour la traduction confidentielle et la reecriture de prompts)
    echo.
    set /p INSTALL_PHI3="Telecharger phi3:mini ? (O/N) : "
    if /i "%INSTALL_PHI3%"=="O" (
        echo.
        echo  Telechargement de phi3:mini en cours...
        echo  (Cela peut prendre plusieurs minutes selon votre connexion)
        echo.
        ollama pull phi3:mini
        echo.
        echo  ✅ phi3:mini installe
    ) else (
        echo.
        echo  ⚠  phi3:mini non installe — les outils locaux ne fonctionneront pas
        echo     Executer manuellement : ollama pull phi3:mini
    )
) else (
    echo  ✅ phi3:mini disponible
)
echo.

:check_python
:: ── Vérification Python (optionnel) ───────────────────
echo  [6/7] Verification de Python (optionnel)...
echo.

where python >nul 2>&1
if %errorlevel% neq 0 (
    echo  ℹ  Python non detecte
    echo.
    echo  Python est optionnel — il permet l'analyse des fichiers uploades (PDF, DOCX).
    echo  Sans Python, l'upload de fichiers est desactive mais tout le reste fonctionne.
    echo.
    echo  Si vous souhaitez l'installer : https://www.python.org
    echo  Cocher 'Add Python to PATH' lors de l'installation.
    echo.
) else (
    for /f "tokens=2 delims= " %%v in ('python --version 2^>^&1') do set PY_VERSION=%%v
    echo  ✅ Python %PY_VERSION% detecte
    echo.
    echo  Pour installer les packages necessaires a l'analyse de fichiers :
    echo  pip install PyMuPDF python-docx pdfminer.six
    echo.
)

:: ── Résumé final ───────────────────────────────────────
echo  [7/7] Resume de l'installation...
echo.
echo ══════════════════════════════════════════════════════

if "%ERRORS%"=="1" (
    echo.
    echo  ⚠  Des etapes sont necessaires avant d'utiliser Velixa.
    echo     Consultez les messages ci-dessus et corrigez les problemes.
    echo     Puis relancez ce script pour verifier.
    echo.
) else (
    echo.
    echo  ✅ Toutes les verifications sont passees !
    echo.
    echo  Velixa for Gen AI est pret.
    echo.
    echo  ══════════════════════════════════════════════════════
    echo   ETAPES MANUELLES RESTANTES (obligatoires) :
    echo  ══════════════════════════════════════════════════════
    echo.
    echo  1. Demarrer Apache dans XAMPP Control Panel
    echo.
    echo  2. Ouvrir http://localhost/velixa dans votre navigateur
    echo.
    echo  3. Se connecter avec :
    echo     Utilisateur : admin
    echo     Mot de passe : password
    echo     (changement force a la premiere connexion)
    echo.
    echo  4. Configurer un provider LLM externe :
    echo     Dashboard ^> Providers ^> Ajouter
    echo     Groq recommande (gratuit) : https://console.groq.com
    echo.
    echo  5. Changer le mot de passe admin immediatement
    echo.
    echo  ══════════════════════════════════════════════════════
    echo   POUR ALLER PLUS LOIN :
    echo  ══════════════════════════════════════════════════════
    echo.
    echo  Documentation complete : INSTALL.md
    echo  White paper : docs/velixa_whitepaper.docx
    echo  Support communaute : https://github.com/CpXDcP/velixa-community/issues
    echo.
)

echo ══════════════════════════════════════════════════════
echo  Velixa for Gen AI v1.1.0 — Licence AGPL v3
echo  Governing AI with Confidence
echo ══════════════════════════════════════════════════════
echo.
pause
