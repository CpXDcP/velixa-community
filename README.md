# velixa-community
OPEN SOURCE Version of Velixa - AI Governance
# Velixa Community

**Velixa** est une plateforme **open source** de gouvernance de l’IA générative en entreprise.  
Cette version *Community* est publiée sous licence **GNU Affero General Public License v3 (AGPLv3)**.

---

## ✨ Fonctionnalités principales

- **Filtrage et gouvernance des prompts**
  - Application de règles de conformité (RGPD, NIS2, ISO 27001, HIPAA, confidentialité industrielle).
  - Filtrage initial par **regex**, suivi d’une analyse contextuelle par **IA locale (Phi-3 Mini)**.
  - Filtres personnalisés selon le métier et l’utilisateur.

- **Gestion des bots et agents**
  - Prise en charge des **bots/agents locaux** et **externes (API)**.
  - Contrôle des flux sortants et entrants selon les règles métier.
  - Gestion des coûts liés aux utilisateurs et aux agents IA.

- **Sécurité et auditabilité**
  - Journalisation complète des prompts, réponses et flux (y compris bots/agents locaux).
  - Hashage SHA-256 des logs et prompts.
  - Export CSV et PDF pour audit externe.
  - Conformité légale et traçabilité.

- **Administration**
  - Interface web (inspirée d’Odoo) simple à utiliser.
  - Gestion des métiers et application automatique des règles associées.
  - Choix dynamique des IA et des API par métier ou utilisateur.

---

## 📦 Architecture

- **Frontend** : HTML, CSS, JavaScript  
- **Backend** : PHP (XAMPP-friendly, pas besoin de base de données lourde)  
- **IA locale** : Phi-3 Mini (MIT, via Ollama)  
- **Dépendances** :
  - Dompdf (LGPL)
  - Bootstrap / jQuery / Chart.js (MIT)
  - Font Awesome (MIT/CC BY 4.0)
  - Regex personnalisées (AGPLv3)

---

## 🔐 Licences

- Ce projet est publié sous **AGPLv3**.  
- Les dépendances tierces sont listées dans [`/licenses/NOTICE.md`](licenses/NOTICE.md).  
- Les empreintes cryptographiques sont disponibles dans [`/licenses/DEPENDENCIES.json`](licenses/DEPENDENCIES.json).

---

## 🚀 Roadmap

- **Community Edition** (cette version) :  
  - Base open source (AGPLv3).  
  - Gouvernance IA et conformité minimale.  
  - Contribution ouverte via *pull requests*.  

- **Enterprise Edition** (future, commerciale) :  
  - Installation clé en main (on-premise).  
  - Connecteurs avancés (SIEM, SOC, AD/LDAP).  
  - Modules premium (reporting avancé, formation, support, certification).  

---

## 🤝 Contribution

1. Forkez le dépôt.  
2. Créez une branche (`feature/ma-fonction`).  
3. Committez vos changements.  
4. Ouvrez une **pull request** → soumise à review.  

Toutes les contributions doivent respecter :  
- La licence **AGPLv3**.  
- Les standards de conformité de Velixa (RGPD/NIS2/ISO).  

---

## 📧 Contact

Créateur : **Didier Crupaux**  
Co-builder : **Jonathan Culot**  
Organisation : [velixa-org](https://github.com/velixa-org)

