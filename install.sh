#!/bin/bash

# ══════════════════════════════════════════════════════
#  Velixa for Gen AI v1.1.0 — Installation Linux
#  Ubuntu 22.04 / Debian 12
#  Licence AGPL v3
# ══════════════════════════════════════════════════════

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'
BOLD='\033[1m'

VELIXA_PATH="/var/www/html/velixa"
PHP_INI="/etc/php/8.2/apache2/php.ini"
ERRORS=0

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
echo "  Script d'installation Linux (Ubuntu/Debian)"
echo "══════════════════════════════════════════════════════"
echo ""

# ── [1/7] Apache ────────────────────────────────────────
echo -e "${BOLD}[1/7] Vérification d'Apache...${NC}"
echo ""

if ! command -v apache2 &>/dev/null; then
    echo -e "${RED}  ❌ Apache2 non détecté${NC}"
    echo ""
    echo "  Installer Apache2 :"
    echo "  sudo apt update && sudo apt install apache2 -y"
    echo ""
    ERRORS=1
else
    APACHE_VERSION=$(apache2 -v 2>/dev/null | head -1 | awk '{print $3}')
    echo -e "${GREEN}  ✅ Apache $APACHE_VERSION détecté${NC}"
fi
echo ""

# ── [2/7] PHP ───────────────────────────────────────────
echo -e "${BOLD}[2/7] Vérification de PHP...${NC}"
echo ""

if ! command -v php &>/dev/null; then
    echo -e "${RED}  ❌ PHP non détecté${NC}"
    echo ""
    echo "  Installer PHP 8.2 :"
    echo "  sudo apt install php8.2 php8.2-curl php8.2-mbstring php8.2-openssl php8.2-fileinfo php8.2-zip -y"
    echo ""
    ERRORS=1
else
    PHP_VERSION=$(php -r "echo PHP_VERSION;")
    PHP_MAJOR=$(php -r "echo PHP_MAJOR_VERSION;")
    if [ "$PHP_MAJOR" -lt 8 ]; then
        echo -e "${RED}  ❌ PHP $PHP_VERSION détecté — version 8.1 minimum requise${NC}"
        echo ""
        echo "  Mettre à jour PHP :"
        echo "  sudo apt install php8.2 -y"
        echo ""
        ERRORS=1
    else
        echo -e "${GREEN}  ✅ PHP $PHP_VERSION détecté${NC}"
    fi
fi
echo ""

# ── [3/7] Extensions PHP ────────────────────────────────
echo -e "${BOLD}[3/7] Vérification des extensions PHP...${NC}"
echo ""

if command -v php &>/dev/null; then
    for ext in curl openssl mbstring fileinfo zip; do
        if php -r "echo extension_loaded('$ext') ? 'OK' : 'MISSING';" | grep -q "MISSING"; then
            echo -e "${RED}  ❌ Extension PHP '$ext' manquante${NC}"
            echo "     sudo apt install php8.2-$ext -y"
            ERRORS=1
        else
            echo -e "${GREEN}  ✅ $ext${NC}"
        fi
    done

    echo ""
    echo "  ℹ  Si des extensions manquent :"
    echo "     sudo systemctl restart apache2"
fi
echo ""

# ── [4/7] Dossiers Velixa ───────────────────────────────
echo -e "${BOLD}[4/7] Vérification du dossier Velixa...${NC}"
echo ""

if [ ! -d "$VELIXA_PATH" ]; then
    echo -e "${RED}  ❌ Dossier Velixa non trouvé dans $VELIXA_PATH${NC}"
    echo ""
    echo "  Extraire le ZIP Velixa dans $VELIXA_PATH :"
    echo "  sudo mkdir -p $VELIXA_PATH"
    echo "  sudo unzip velixa-community-v1.1.0.zip -d /var/www/html/"
    echo "  sudo chown -R www-data:www-data $VELIXA_PATH"
    echo "  sudo chmod -R 755 $VELIXA_PATH"
    echo ""
    ERRORS=1
else
    echo -e "${GREEN}  ✅ Dossier Velixa trouvé${NC}"

    # Créer les dossiers manquants
    for dir in logs uploads data data/secure data/llm_cache data/legal_holds; do
        if [ ! -d "$VELIXA_PATH/$dir" ]; then
            sudo mkdir -p "$VELIXA_PATH/$dir"
            sudo chown www-data:www-data "$VELIXA_PATH/$dir"
            echo -e "${GREEN}  ✅ Dossier $dir créé${NC}"
        fi
    done

    # Copier users.json.example si users.json absent
    if [ ! -f "$VELIXA_PATH/users.json" ] && [ -f "$VELIXA_PATH/users.json.example" ]; then
        sudo cp "$VELIXA_PATH/users.json.example" "$VELIXA_PATH/users.json"
        sudo chown www-data:www-data "$VELIXA_PATH/users.json"
        echo -e "${GREEN}  ✅ users.json créé depuis users.json.example${NC}"
    fi

    # Copier providers.json.example si providers.json absent
    if [ ! -f "$VELIXA_PATH/config/providers.json" ] && [ -f "$VELIXA_PATH/config/providers.json.example" ]; then
        sudo cp "$VELIXA_PATH/config/providers.json.example" "$VELIXA_PATH/config/providers.json"
        sudo chown www-data:www-data "$VELIXA_PATH/config/providers.json"
        echo -e "${GREEN}  ✅ config/providers.json créé${NC}"
    fi

    # Droits
    sudo chown -R www-data:www-data "$VELIXA_PATH/logs" 2>/dev/null
    sudo chown -R www-data:www-data "$VELIXA_PATH/uploads" 2>/dev/null
    sudo chown -R www-data:www-data "$VELIXA_PATH/data" 2>/dev/null
    sudo chmod -R 775 "$VELIXA_PATH/logs" 2>/dev/null
    sudo chmod -R 775 "$VELIXA_PATH/uploads" 2>/dev/null
    sudo chmod -R 775 "$VELIXA_PATH/data" 2>/dev/null

    echo -e "${GREEN}  ✅ Droits www-data appliqués${NC}"
fi
echo ""

# ── [5/7] Ollama ────────────────────────────────────────
echo -e "${BOLD}[5/7] Vérification d'Ollama...${NC}"
echo ""

if ! command -v ollama &>/dev/null; then
    echo -e "${YELLOW}  ❌ Ollama non détecté${NC}"
    echo ""
    echo "  Ollama est nécessaire pour les outils locaux (traduction confidentielle,"
    echo "  réécriture des prompts bloqués)."
    echo ""
    echo "  Installer Ollama :"
    echo "  curl -fsSL https://ollama.com/install.sh | sh"
    echo ""
    ERRORS=1
else
    echo -e "${GREEN}  ✅ Ollama détecté${NC}"

    # Vérifier phi3:mini
    if ! ollama list 2>/dev/null | grep -q "phi3"; then
        echo ""
        echo -e "${YELLOW}  ⚠  phi3:mini non installé${NC}"
        echo ""
        read -p "  Télécharger phi3:mini maintenant ? (2.2 GB) (o/n) : " INSTALL_PHI3
        if [[ "$INSTALL_PHI3" =~ ^[Oo]$ ]]; then
            echo ""
            echo "  Téléchargement de phi3:mini en cours..."
            ollama pull phi3:mini
            echo ""
            echo -e "${GREEN}  ✅ phi3:mini installé${NC}"
        else
            echo -e "${YELLOW}  ⚠  phi3:mini non installé — outils locaux non disponibles${NC}"
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
    echo ""
    echo "  Python est optionnel — il permet l'analyse des fichiers uploadés (PDF, DOCX)."
    echo "  Sans Python, l'upload de fichiers est désactivé mais tout le reste fonctionne."
    echo ""
    echo "  Installer Python :"
    echo "  sudo apt install python3 python3-pip -y"
    echo "  pip3 install PyMuPDF python-docx pdfminer.six --break-system-packages"
    echo ""
else
    PY_VERSION=$(python3 --version 2>&1 | awk '{print $2}')
    echo -e "${GREEN}  ✅ Python $PY_VERSION détecté${NC}"
    echo ""
    echo "  Pour installer les packages d'analyse de fichiers :"
    echo "  pip3 install PyMuPDF python-docx pdfminer.six --break-system-packages"
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
    echo -e "${BOLD}  Velixa for Gen AI est prêt.${NC}"
    echo ""
    echo "══════════════════════════════════════════════════════"
    echo -e "${BOLD}   ÉTAPES MANUELLES RESTANTES (obligatoires) :${NC}"
    echo "══════════════════════════════════════════════════════"
    echo ""
    echo "  1. Activer le site Apache :"
    echo "     sudo a2enmod rewrite"
    echo "     sudo systemctl restart apache2"
    echo ""
    echo "  2. Ouvrir http://localhost/velixa dans votre navigateur"
    echo ""
    echo "  3. Se connecter avec :"
    echo "     Utilisateur : admin"
    echo "     Mot de passe : password"
    echo "     (changement forcé à la première connexion)"
    echo ""
    echo "  4. Configurer un provider LLM externe :"
    echo "     Dashboard > Providers > Ajouter"
    echo "     Groq recommandé (gratuit) : https://console.groq.com"
    echo ""
    echo "  5. Changer le mot de passe admin immédiatement"
    echo ""
    echo "══════════════════════════════════════════════════════"
    echo -e "${BOLD}   POUR ALLER PLUS LOIN :${NC}"
    echo "══════════════════════════════════════════════════════"
    echo ""
    echo "  Documentation complète : INSTALL.md"
    echo "  Support communauté : https://github.com/CpXDcP/velixa-community/issues"
    echo ""
fi

echo "══════════════════════════════════════════════════════"
echo "  Velixa for Gen AI v1.1.0 — Licence AGPL v3"
echo "  Governing AI with Confidence"
echo "══════════════════════════════════════════════════════"
echo ""
