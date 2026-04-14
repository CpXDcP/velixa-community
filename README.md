# ![Velixa](assets/velixa-logo.png)

# Velixa for Gen AI — Community Edition

**Governing AI with Confidence**

[![Licence AGPL v3](https://img.shields.io/badge/Licence-AGPL%20v3-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.1.0-blue.svg)](https://github.com/CpXDcP/velixa-community/releases)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://php.net)
[![Ollama](https://img.shields.io/badge/Ollama-phi3%3Amini-orange.svg)](https://ollama.com)

---

## Qu'est-ce que Velixa for Gen AI ?

Velixa for Gen AI est une solution de **gouvernance IA on-premise**, déployable en quelques heures, sans cloud, sans abonnement, compatible air-gap total.

Elle s'intercale entre vos utilisateurs et les LLM externes (Groq, OpenAI, Anthropic, Gemini) pour analyser, bloquer et éduquer — sans jamais envoyer vos données vers des tiers non autorisés.

> **Modèle Open Core** — Ce dépôt contient le socle Community, open-source sous licence AGPL v3, gratuit pour toujours. Des modules Enterprise sont disponibles séparément pour les organisations ayant des besoins avancés.

---

## Fonctionnalités Community v1.1.0

| Fonctionnalité | Description |
|---|---|
| 🛡️ **Pipeline de blocage** | Détection et blocage des prompts non-conformes |
| 📊 **Score de criticité** | Score 0-100 pour chaque prompt analysé |
| 🎓 **Explication pédagogique** | Éduquer plutôt que sanctionner — explication réglementaire claire |
| ✍️ **Réécriture conforme** | phi3:mini réécrit le prompt bloqué sans les données sensibles |
| 🔌 **Multi-providers** | Groq, OpenAI, Anthropic, Gemini — votre choix |
| 🌐 **Traduction confidentielle** | Traduction locale via phi3:mini — données jamais envoyées |
| 📈 **Graphiques board** | Tableaux de bord pour la direction |
| 📄 **Export PDF logs** | Export des journaux de conformité |
| 👁️ **HITL** | Human In The Loop — approbation humaine (pay-per-use) |
| ✅ **Consentement IA tracé** | Charte d'usage IA avec consentement enregistré |
| 👥 **Gestion utilisateurs** | Utilisateurs locaux + MFA TOTP |
| 🗑️ **Droit à l'oubli** | RGPD Art.17 — purge ciblée via CLI |

---

## Prérequis

- **PHP 8.1+** avec extensions : `curl`, `openssl`, `mbstring`, `fileinfo`, `zip`
- **Apache / XAMPP** 2.4+
- **Ollama** + modèle **phi3:mini** (2.2 GB)
- **Python 3.10+** *(optionnel — analyse fichiers PDF/DOCX)*
- Une clé API LLM externe *(Groq gratuit recommandé pour démarrer)*

---

## Installation rapide

```bash
# 1. Cloner le dépôt
git clone https://github.com/CpXDcP/velixa-community.git

# 2. Copier dans votre répertoire web
# Windows : C:\xampp\htdocs\velixa\
# Linux   : /var/www/html/velixa/

# 3. Copier les fichiers de config exemple
cp config/providers.json.example config/providers.json
cp users.json.example users.json

# 4. Installer Ollama et phi3:mini
ollama pull phi3:mini

# 5. Ouvrir dans le navigateur
# http://localhost/velixa
# Login : admin / password (changement forcé à la première connexion)
```

👉 **Voir [INSTALL.md](INSTALL.md) pour le guide complet étape par étape.**

---

## Architecture

```
Utilisateur
    │
    ▼
interface_user.php
    │
    ▼
rules_runner.php ──── security_pipeline.php
    │                        │
    │                   phi3:mini (local)
    │                   Ollama 127.0.0.1:11434
    │
    ▼
LLM externe au choix
(Groq / OpenAI / Anthropic / Gemini)
```

**Aucune donnée ne transite sans analyse préalable.**  
**phi3:mini fonctionne 100% en local — vos documents ne quittent jamais votre infrastructure.**

---

## Modèle Open Core

| Fonctionnalité | Community (gratuit) | Enterprise |
|---|---|---|
| Pipeline de blocage complet | ✅ | ✅ |
| Score criticité + explication | ✅ | ✅ |
| Réécriture conforme phi3 | ✅ | ✅ |
| Traduction confidentielle | ✅ | ✅ |
| HITL pay-per-use | ✅ | ✅ illimité |
| Tokenisation PII réversible | ❌ | ✅ |
| Outils métier phi3 avancés | ❌ | ✅ |
| LDAP / Active Directory | ❌ | ✅ |
| Legal Hold SHA256 | ❌ | ✅ |
| Rapport EU AI Act PDF | ❌ | ✅ |
| Multi-tenant | ❌ | ✅ |
| Support dédié + SLA | ❌ | ✅ |

---

## Conformité réglementaire

Velixa for Gen AI Community adresse :

- **RGPD** — Art.5 (minimisation), Art.17 (droit à l'oubli), Art.32 (sécurité)
- **NIS2** — Art.21 (gestion des risques), détection prompt injection
- **EU AI Act** — Art.5 (pratiques interdites / jailbreak), Art.14 (supervision humaine / HITL), Art.52 (transparence / consentement)

---

## Licence

Velixa for Gen AI Community est distribué sous licence **GNU Affero General Public License v3 (AGPL v3)**.

Le code source est auditable, modifiable et redistribuable librement selon les termes de cette licence.  
Voir [LICENSE](LICENSE) pour les détails complets.

---

## Contribuer

Les contributions sont les bienvenues — bugs, améliorations, règles sectorielles, traductions.

1. Fork le dépôt
2. Créer une branche (`git checkout -b feature/ma-feature`)
3. Commit (`git commit -m 'Ajout ma feature'`)
4. Push (`git push origin feature/ma-feature`)
5. Ouvrir une Pull Request

---

## Support

- **Community** : [Issues GitHub](https://github.com/CpXDcP/velixa-community/issues)
- **Enterprise** : Contactez-nous pour le support dédié et le SLA contractuel

---

*Velixa for Gen AI — Governing AI with Confidence*
