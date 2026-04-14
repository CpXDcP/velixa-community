#!/bin/bash

# ══════════════════════════════════════════════════════
#  Velixa for Gen AI v1.1.0 — Installation macOS
#  macOS 13+ — MAMP ou Homebrew
#  Licence AGPL v3
# ══════════════════════════════════════════════════════

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'
BOLD='\033[1m'

ERRORS=0
INSTALL_TYPE=""
VELIXA_PATH=""

echo ""
echo -e "${BLUE}${BOLD}"
echo "  ██╗   ██╗███████╗██╗     ██╗██╗  ██╗ █████╗"
echo "  ██║   ██║██╔════╝██║     ██║╚██╗██╔╝██╔══██╗"
echo "  ██║   ██║█████╗  ██║     ██║ ╚███╔╝ ███████║"
echo "  ╚██╗ ██╔╝██╔══╝  ██║     ██║ ██╔██╗ ██╔══██║"
echo "   ╚████╔╝ ███████╗███████╗██║██╔╝ ██╗██║  ██║"
echo "    ╚═══╝  ╚══════╝╚══════╝╚═╝╚═╝  ╚═╝╚═╝  ╚═╝"
echo -e "${NC}"
echo -e "${BOLD}  Velixa for Gen AI v1.1.0 — Community Edition${NC}"
echo "  Governing AI with Confidence"
echo "  Licence AGPL v3"
echo ""
echo "══════════════════════════════════════════════════════"
echo "  Script d'installation macOS"
echo "══════════════════════════════════════════════════════"
echo ""

# ── [1/7] Détection MAMP ou Homebrew ───────────────────
echo -e "${BOLD}[1/7] Détection de l'environnement PHP...${NC}"
echo ""

# Vérifier MAMP
if [ -d "/Applications/MAMP" ]; then
    INSTALL_TYPE="mamp"
    VELIXA_PATH="/Applications/MAMP/htdocs/velixa"
    PHP_BIN="/Applications/MAMP/bin/php/php8.2.0/bin/php"
    # Trouver la version PHP MAMP disponible
    for v in 8.3 8.2 8.1; do
        if [ -d "/Applications/MAMP/bin/php/php${v}.0" ]; then
            PHP_BIN="/Applications/MAMP/bin/php/php${v}.0/bin/php"
            PHP_VERSION=$v
            break
        fi
    done
    echo -e "${GREEN}  ✅ MAMP détecté${NC}"
    echo "     PHP via MAMP : $PHP_BIN"
    echo "     Dossier web : $VELIXA_PATH"

# Vérifier Homebrew PHP
elif command -v brew &>/dev/null && brew list php &>/dev/null 2>&1; then
    INSTALL_TYPE="homebrew"
    VELIXA_PATH="/opt/homebrew/var/www/velixa"
    PHP_BIN=$(which php)
    echo -e "${GREEN}  ✅ PHP via Homebrew détecté${NC}"
    echo "     PHP : $PHP_BIN"
    echo "     Dossier web : $VELIXA_PATH"

# PHP système macOS
elif command -v php &>/dev/null; then
    INSTALL_TYPE="system"
    VELIXA_PATH="/Library/WebServer/Documents/velixa"
    PHP_BIN=$(which php)
    echo -e "${YELLOW}  ⚠  PHP système détecté${NC}"
    echo "     Pour un meilleur support, utiliser MAMP ou Homebrew."
    echo "     MAMP : https://www.mamp.info"
    echo "     Homebrew : brew install php"
    echo ""

else
    echo -e "${RED}  ❌ Aucun environnement PHP détecté${NC}"
    echo ""
    echo "  Options disponibles :"
    echo ""
    echo "  Option A — MAMP (recommandé, interface graphique) :"
    echo "  https://www.mamp.info/en/downloads/"
    echo ""
    echo "  Option B — Homebrew (ligne de commande) :"
    echo "  /bin/bash -c \"\$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)\""
    echo "  brew install php"
    echo ""
    ERRORS=1
fi
echo ""

# ── [2/7] Version PHP ───────────────────────────────────
echo -e "${BOLD}[2/7] Vérification de la version PHP...${NC}"
echo ""

if [ -n "$PHP_BIN" ] && [ -f "$PHP_BIN" ]; then
    PHP_VERSION=$("$PHP_BIN" -r "echo PHP_VERSION;" 2>/dev/null)
    PHP_MAJOR=$("$PHP_BIN" -r "echo PHP_MAJOR_VERSION;" 2>/dev/null)

    if [ "$PHP_MAJOR" -lt 8 ] 2>/dev/null; then
        echo -e "${RED}  ❌ PHP $PHP_VERSION détecté — version 8.1 minimum requise${NC}"
        echo ""
        if [ "$INSTALL_TYPE" = "mamp" ]; then
            echo "  Dans MAMP : Préférences > PHP > sélectionner PHP 8.1+"
        elif [ "$INSTALL_TYPE" = "homebrew" ]; then
            echo "  brew install php@8.2"
        fi
        ERRORS=1
    else
        echo -e "${GREEN}  ✅ PHP $PHP_VERSION${NC}"
    fi
elif command -v php &>/dev/null; then
    PHP_VERSION=$(php -r "echo PHP_VERSION;" 2>/dev/null)
    echo -e "${GREEN}  ✅ PHP $PHP_VERSION${NC}"
else
    echo -e "${RED}  ❌ PHP non accessible${NC}"
    ERRORS=1
fi
echo ""

# ── [3/7] Extensions PHP ────────────────────────────────
echo -e "${BOLD}[3/7] Vérification des extensions PHP...${NC}"
echo ""

PHP_CMD=${PHP_BIN:-php}

if command -v "$PHP_CMD" &>/dev/null || [ -f "$PHP_CMD" ]; then
    for ext in curl openssl mbstring fileinfo zip; do
        RESULT=$("$PHP_CMD" -r "echo extension_loaded('$ext') ? 'OK' : 'MISSING';" 2>/dev/null)
        if [ "$RESULT" = "MISSING" ]; then
            echo -e "${RED}  ❌ Extension '$ext' manquante${NC}"
            if [ "$INSTALL_TYPE" = "mamp" ]; then
                echo "     Activer dans MAMP > Préférences > PHP > extensions"
            elif [ "$INSTALL_TYPE" = "homebrew" ]; then
                echo "     brew install php-$ext ou vérifier php.ini"
            fi
            ERRORS=1
        else
            echo -e "${GREEN}  ✅ $ext${NC}"
        fi
    done
fi
echo ""

# ── [4/7] Dossiers Velixa ───────────────────────────────
echo -e "${BOLD}[4/7] Vérification du dossier Velixa...${NC}"
echo ""

if [ ! -d "$VELIXA_PATH" ]; then
    echo -e "${RED}  ❌ Dossier Velixa non trouvé dans $VELIXA_PATH${NC}"
    echo ""
    echo "  Extraire le ZIP Velixa dans $VELIXA_PATH"
    echo ""
    ERRORS=1
else
    echo -e "${GREEN}  ✅ Dossier Velixa trouvé${NC}"

    for dir in logs uploads data data/secure data/llm_cache data/legal_holds; do
        if [ ! -d "$VELIXA_PATH/$dir" ]; then
            mkdir -p "$VELIXA_PATH/$dir"
            echo -e "${GREEN}  ✅ Dossier $dir créé${NC}"
        fi
    done

    if [ ! -f "$VELIXA_PATH/users.json" ] && [ -f "$VELIXA_PATH/users.json.example" ]; then
        cp "$VELIXA_PATH/users.json.example" "$VELIXA_PATH/users.json"
        echo -e "${GREEN}  ✅ users.json créé depuis users.json.example${NC}"
    fi

    if [ ! -f "$VELIXA_PATH/config/providers.json" ] && [ -f "$VELIXA_PATH/config/providers.json.example" ]; then
        cp "$VELIXA_PATH/config/providers.json.example" "$VELIXA_PATH/config/providers.json"
        echo -e "${GREEN}  ✅ config/providers.json créé${NC}"
    fi

    chmod -R 755 "$VELIXA_PATH/logs" 2>/dev/null
    chmod -R 755 "$VELIXA_PATH/uploads" 2>/dev/null
    chmod -R 755 "$VELIXA_PATH/data" 2>/dev/null
fi
echo ""

# ── [5/7] Ollama ────────────────────────────────────────
echo -e "${BOLD}[5/7] Vérification d'Ollama...${NC}"
echo ""

if ! command -v ollama &>/dev/null; then
    echo -e "${YELLOW}  ❌ Ollama non détecté${NC}"
    echo ""
    echo "  Installer Ollama pour macOS :"
    echo "  https://ollama.com/download (version macOS)"
    echo "  ou : brew install ollama"
    echo ""
    ERRORS=1
else
    echo -e "${GREEN}  ✅ Ollama détecté${NC}"

    if ! ollama list 2>/dev/null | grep -q "phi3"; then
        echo ""
        echo -e "${YELLOW}  ⚠  phi3:mini non installé${NC}"
        echo ""
        read -p "  Télécharger phi3:mini maintenant ? (2.2 GB) (o/n) : " INSTALL_PHI3
        if [[ "$INSTALL_PHI3" =~ ^[Oo]$ ]]; then
            ollama pull phi3:mini
            echo -e "${GREEN}  ✅ phi3:mini installé${NC}"
        else
            echo "     Exécuter manuellement : ollama pull phi3:mini"
        fi
    else
        echo -e "${GREEN}  ✅ phi3:mini disponible${NC}"
    fi
fi
echo ""

# ── [6/7] Python (optionnel) ────────────────────────────
echo -e "${BOLD}[6/7] Vérification de Python (optionnel)...${NC}"
echo ""

if ! command -v python3 &>/dev/null; then
    echo "  ℹ  Python non détecté"
    echo "  Sans Python, l'upload de fichiers est désactivé mais tout le reste fonctionne."
    echo ""
    echo "  Installer Python : https://www.python.org"
    echo "  ou : brew install python3"
else
    PY_VERSION=$(python3 --version 2>&1 | awk '{print $2}')
    echo -e "${GREEN}  ✅ Python $PY_VERSION${NC}"
    echo "  pip3 install PyMuPDF python-docx pdfminer.six"
fi
echo ""

# ── [7/7] Résumé ────────────────────────────────────────
echo -e "${BOLD}[7/7] Résumé de l'installation...${NC}"
echo ""
echo "══════════════════════════════════════════════════════"

if [ "$ERRORS" -eq 1 ]; then
    echo ""
    echo -e "${YELLOW}  ⚠  Des étapes sont nécessaires avant d'utiliser Velixa.${NC}"
    echo "     Consultez les messages ci-dessus et corrigez les problèmes."
    echo "     Puis relancez ce script pour vérifier."
    echo ""
else
    echo ""
    echo -e "${GREEN}${BOLD}  ✅ Toutes les vérifications sont passées !${NC}"
    echo ""
    echo "══════════════════════════════════════════════════════"
    echo -e "${BOLD}   ÉTAPES MANUELLES RESTANTES :${NC}"
    echo "══════════════════════════════════════════════════════"
    echo ""
    if [ "$INSTALL_TYPE" = "mamp" ]; then
        echo "  1. Démarrer MAMP et activer les serveurs Apache et PHP"
    else
        echo "  1. Démarrer Apache : sudo apachectl start"
    fi
    echo ""
    echo "  2. Ouvrir http://localhost/velixa dans votre navigateur"
    echo ""
    echo "  3. Se connecter avec :"
    echo "     Utilisateur : admin"
    echo "     Mot de passe : password"
    echo ""
    echo "  4. Configurer un provider LLM :"
    echo "     Dashboard > Providers > Ajouter"
    echo "     Groq recommandé (gratuit) : https://console.groq.com"
    echo ""
    echo "  5. Changer le mot de passe admin immédiatement"
    echo ""
fi

echo "══════════════════════════════════════════════════════"
echo "  Velixa for Gen AI v1.1.0 — Licence AGPL v3"
echo "  Governing AI with Confidence"
echo "══════════════════════════════════════════════════════"
echo ""
