VELIXA-org

Repository VELIXA par Didier Crupaux et Jonathan Culot

🌐 Velixa Community

Velixa est une plateforme open source de gouvernance de l’IA générative en entreprise. Cette version Community est publiée sous licence GNU Affero General Public License v3 (AGPLv3).

✨ Fonctionnalités principales

Filtrage et gouvernance des prompts

Application de règles de conformité : RGPD, NIS 2, ISO 27001, HIPAA (US), confidentialité industrielle, réglementations sectorielles (santé, éducation, finance).

Filtrage initial par expressions régulières (regex).

Analyse contextuelle par une IA locale auditable sur son poids (Phi-3 Mini).

Règles dynamiques activables par métier et par utilisateur.

Gestion des bots et agents

Support des bots/agents locaux et des IA externes (API, SaaS, Cloud, on-premise).

Contrôle bidirectionnel des flux sortants et entrants.

Gestion des coûts liés aux utilisateurs, prompts et agents IA.

Sécurité et auditabilité

Journalisation complète des prompts, réponses, et flux utilisateurs/bots.

Hashage SHA-256 des journaux pour intégrité.

Export CSV et PDF pour audit externe.

Alignement conformité légale et traçabilité (audit interne/externe).

Administration

Interface web inspirée d’Odoo : simple, moderne, responsive.

Gestion centralisée des métiers avec application automatique des règles.

Sélecteur dynamique des IA / API selon le contexte (métier, utilisateur).

📦 Architecture

Frontend : HTML, CSS, JavaScript

Backend : PHP (XAMPP-ready, pas de base SQL lourde → stockage JSON)

IA locale : Phi-3 Mini (via Ollama)

Sécurité intégrée : proxy de gouvernance, filtrage contextuel, traçabilité

🛠️ Dépendances principales

Phi-3 Mini (MIT License, via Ollama)

Bootstrap (MIT)

jQuery (MIT)

Chart.js (MIT)

Font Awesome (MIT / CC BY 4.0)

Dompdf (LGPL-2.1)

Regex personnalisées (AGPLv3)

Export PDF/CSV intégré

🔐 Licences

Le projet principal est sous AGPLv3.

Les dépendances tierces sont utilisées sous leurs licences respectives :

MIT (Bootstrap, jQuery, Chart.js, Font Awesome, Phi-3 Mini via Ollama)

LGPL (Dompdf)

CC BY 4.0 (Font Awesome – icônes spécifiques)

Les fichiers de référence :

/licenses/NOTICE.md : résumé des licences.

/licenses/DEPENDENCIES.json : empreintes cryptographiques et détails des dépendances.

🚀 Roadmap Community Edition (cette version)

Base open source (AGPLv3).

Gouvernance IA et conformité minimale.

Logs + auditabilité intégrée.

Contributions ouvertes (pull requests).

Enterprise Edition (future, commerciale)

Déploiement clé en main (cloud ou on-premise).

Connecteurs avancés : SIEM, SOC, AD/LDAP, Microsoft 365, SAP, ServiceNow.

Modules premium : reporting avancé, support SLA, certification, formation.

SaaS Velixa : hébergement EU, mises à jour automatiques, monitoring intégré.

🤝 Contribution

Forkez le dépôt.

Créez une branche (feature/ma-fonction).

Committez vos changements.

Ouvrez une pull request → review par les mainteneurs.

Toutes les contributions doivent respecter :

La licence AGPLv3.

Les standards de conformité Velixa (RGPD, NIS 2, ISO 27001, HIPAA).

📧 Contact

Créateur : Didier Crupaux Co-builder : Jonathan Culot

🌍 Site : https://velixa.eu

✉️ Email : velixa@proton.me
