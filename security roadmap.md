# 🗺️ Velixa – Security Roadmap

---

## 🇫🇷 Partie Française

### Objectif
Cette feuille de route détaille les améliorations à apporter à Velixa pour passer du statut actuel (MVP sécurisé) à un niveau **certifiable enterprise** (ISO 27001, NIS 2, HIPAA).

---

### Court terme (1-3 mois)
- 🔐 **Gestion des mots de passe**
  - Implémenter un hash fort (BCrypt, Argon2)
  - Forcer le changement du mot de passe admin par défaut au premier login

- 📜 **Logs**
  - Ajouter signature numérique (HMAC ou clé privée) pour garantir l’intégrité
  - Activer rotation automatique des logs

- 🌐 **Durcissement serveur**
  - Désactiver fonctions PHP dangereuses (`exec`, `shell_exec`)
  - Supprimer pages par défaut Apache/XAMPP
  - Forcer TLS 1.2+ uniquement

---

### Moyen terme (3-6 mois)
- 🧩 **AD/LDAP**
  - Stabiliser connecteur (gestion des timeouts, multi-OU)
  - Enforcer LDAPS obligatoire

- 🤖 **Bots & Agents**
  - Finaliser support OAuth2 et API Key
  - Ajouter intégration sécurisée Slack/Teams/Email

- 📊 **Monitoring & alerting**
  - Développer connecteurs SIEM/SOC
  - Mettre en place alertes automatiques (logs suspects, échecs répétés)

---

### Long terme (6-12 mois)
- ✅ **Audit externe**
  - Faire auditer la solution par un tiers indépendant
  - Viser certification ISO 27001 et conformité NIS 2

- 🏗️ **Renforcement gouvernance**
  - Documentation de sécurité améliorée (politiques internes, procédures)
  - Mise en place d’un plan de réponse aux incidents (IRP)

- 🛡️ **Durcissement avancé**
  - Séparation applicative (reverse proxy dédié, containers)
  - Support multi-tenants sécurisé

---

## 🇬🇧 English Section

### Purpose
This roadmap outlines the improvements required to move Velixa from its current state (secure MVP) to an **enterprise certifiable** solution (ISO 27001, NIS 2, HIPAA).

---

### Short term (1-3 months)
- 🔐 **Password management**
  - Implement strong hashing (BCrypt, Argon2)
  - Force admin password change on first login

- 📜 **Logs**
  - Add digital signatures (HMAC/private key) for tamper-proof logging
  - Enable automatic log rotation

- 🌐 **Server hardening**
  - Disable dangerous PHP functions
  - Remove Apache/XAMPP default pages
  - Enforce TLS 1.2+ only

---

### Mid term (3-6 months)
- 🧩 **AD/LDAP**
  - Stabilize connector (timeouts, multi-OU)
  - Enforce LDAPS

- 🤖 **Bots & Agents**
  - Finalize OAuth2 and API Key support
  - Add secure integration with Slack/Teams/Email

- 📊 **Monitoring & alerting**
  - Develop SIEM/SOC connectors
  - Enable automatic alerts (suspicious logs, repeated failures)

---

### Long term (6-12 months)
- ✅ **External audit**
  - Commission third-party audit
  - Target ISO 27001 certification and NIS 2 compliance

- 🏗️ **Governance reinforcement**
  - Enhanced security documentation (internal policies, procedures)
  - Implement Incident Response Plan (IRP)

- 🛡️ **Advanced hardening**
  - Application separation (dedicated reverse proxy, containers)
  - Secure multi-tenant support

---
