# 🔐 Velixa – Security & Compliance Policy

---

## 🇫🇷 Partie Française

### 1) Objectif
Velixa est conçu comme un **proxy de gouvernance IA** destiné à sécuriser et encadrer l’usage de l’intelligence artificielle en entreprise.  
Ce document décrit les **mesures de sécurité techniques et organisationnelles** intégrées dans Velixa, ainsi que les points à renforcer pour atteindre les standards de sécurité enterprise (ISO 27001, NIS 2, HIPAA).

---

### 2) Sécurité technique (implémentée)

#### 2.1 HTTPS & communications chiffrées
- HTTPS obligatoire (redirection automatique depuis HTTP)
- Certificats SSL (mkcert ou Let’s Encrypt)
- VirtualHost configuré sur le port 443
- **Strict-Transport-Security (HSTS)** activé

#### 2.2 Cookies & sessions
- Cookies sécurisés (`Secure`, `HttpOnly`, `SameSite=Lax`)
- Sessions PHP renforcées (`use_only_cookies=1`)

#### 2.3 Chiffrement des données sensibles
- Prompts jamais stockés en clair
- Hash SHA-256 des prompts dans les logs
- Vérification d’intégrité via `INTEGRITY_MANIFEST.json`

#### 2.4 Headers de sécurité
- `Strict-Transport-Security`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`

---

### 3) Journalisation & audit
- **Logs CSV** stockés en local (`logs.csv`)
- Colonnes : timestamp, utilisateur/bot, règle appliquée, action (allow/deny), hash SHA-256
- Export disponible en **PDF** et **CSV**
- Conforme aux exigences d’audit **RGPD/NIS2**

---

### 4) Gestion des identités & accès
- **Identifiant admin par défaut : dom/dom** → à modifier immédiatement
- Gestion des utilisateurs via `manage_users.php`
- Rôles : Admin / User / Métier
- AD/LDAP : **codé mais non finalisé**

---

### 5) Gouvernance IA
- Analyse contextuelle via **Phi-3-mini** *(pré-filtrage uniquement)*
- Application des **règles métiers** (`rules_by_metier.json`)
- Routage conditionnel vers IA externes
- Bots/Agents : HMAC fonctionnel, API Key & OAuth2 prototypes

---

### 6) Conformité
Velixa intègre les principes de :
- **RGPD** (minimisation, anonymisation, auditabilité)
- **NIS 2** (traçabilité, sécurité réseau, gouvernance SI)
- **ISO 27001** (contrôles techniques, gestion des accès, audit)
- **HIPAA** (santé, confidentialité patient)

---

### 7) Fonctionnalités non finalisées
- Connecteur AD/LDAP → instable, nécessite tests
- Bots OAuth2 & API Key → prototypes non validés
- Connecteurs SOC/SIEM → pipeline présent, pas activé

---

### 8) Points à améliorer pour atteindre le niveau enterprise
1. **Gestion des mots de passe**
   - Utiliser du chiffrement fort (BCrypt, Argon2)
   - Forcer le changement de mot de passe au premier login

2. **Logs**
   - Ajouter une **signature numérique (HMAC ou clé privée)** pour garantir l’intégrité des journaux
   - Mise en place de logs immuables

3. **AD/LDAP**
   - Stabiliser et tester le connecteur (timeouts, multi-OU)
   - Ajouter support LDAPS obligatoire

4. **Bots & Agents**
   - Finaliser OAuth2 / API Key
   - Ajouter support complet Slack/Teams/Email sécurisé

5. **Monitoring & Alerting**
   - Connecteur SIEM/SOC fonctionnel
   - Alertes automatiques en cas de violation

6. **Durcissement serveur**
   - Désactiver fonctions PHP dangereuses (`exec`, `shell_exec`)
   - Forcer TLS 1.2+ uniquement
   - Supprimer les pages par défaut Apache/XAMPP

7. **Audit externe**
   - Faire auditer la solution par un tiers pour viser ISO 27001/NIS 2

---

### 9) Checklist sécurité
✅ HTTPS activé  
✅ Cookies sécurisés  
✅ Prompts hashés en SHA-256  
✅ Logs exportables et auditables  
✅ Admin par défaut changé  
✅ Règles métiers en place  
✅ Intégrité fichiers vérifiable  
⚠️ Logs non signés → à renforcer  
⚠️ AD/LDAP non stable  
⚠️ OAuth2/API Key non validés  
⚠️ Pas encore d’alertes SIEM/SOC  

---

## 🇬🇧 English Section

### 1) Purpose
Velixa is an **AI governance proxy** designed to secure and regulate AI usage within enterprises.  
This document describes the **security and compliance measures** integrated into Velixa, and the points to improve to reach enterprise standards (ISO 27001, NIS 2, HIPAA).

---

### 2) Technical Security (implemented)

#### 2.1 HTTPS & encrypted communications
- HTTPS enforced (auto redirect)
- SSL certificates (mkcert or Let’s Encrypt)
- VirtualHost on port 443
- **Strict-Transport-Security (HSTS)** enabled

#### 2.2 Cookies & sessions
- Secure cookies (`Secure`, `HttpOnly`, `SameSite=Lax`)
- Hardened PHP sessions (`use_only_cookies=1`)

#### 2.3 Data protection
- Prompts never stored in plain text
- SHA-256 hashing for logs
- File integrity check via `INTEGRITY_MANIFEST.json`

#### 2.4 Security headers
- `Strict-Transport-Security`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`

---

### 3) Logging & audit
- **CSV logs** (`logs.csv`)
- Fields: timestamp, user/bot, rule, action, SHA-256 hash
- Export to **PDF** and **CSV**
- Compliant with **GDPR/NIS2** audits

---

### 4) Identity & access management
- **Default admin: dom/dom** → must be changed
- User management in `manage_users.php`
- Roles: Admin / User / Business role
- AD/LDAP: **coded but not finalized**

---

### 5) AI governance
- Contextual analysis via **Phi-3-mini** *(pre-filter only)*
- Enforcement of **business rules**
- Conditional routing to external AIs
- Bots/Agents: HMAC working, API Key/OAuth2 prototypes

---

### 6) Compliance
Aligned with:
- **GDPR**
- **NIS 2**
- **ISO 27001**
- **HIPAA**

---

### 7) Non-finalized features
- AD/LDAP connector unstable
- OAuth2/API Key bots unvalidated
- SOC/SIEM connectors not active

---

### 8) Improvements needed for enterprise readiness
1. **Password management**
   - Strong hashing (BCrypt, Argon2)
   - Enforce password change on first login

2. **Logs**
   - Add **digital signature (HMAC/private key)** for tamper-proof logs

3. **AD/LDAP**
   - Stabilize connector (timeouts, multi-OU)
   - Enforce LDAPS only

4. **Bots & Agents**
   - Finalize OAuth2 / API Key
   - Full secure integration for Slack/Teams/Email

5. **Monitoring & alerting**
   - SIEM/SOC connectors functional
   - Auto alerts on violations

6. **Server hardening**
   - Disable dangerous PHP functions
   - Enforce TLS 1.2+ only
   - Remove Apache/XAMPP default pages

7. **External audit**
   - Required for ISO 27001 / NIS 2 certification

---

### 9) Security checklist
✅ HTTPS enabled  
✅ Secure cookies  
✅ SHA-256 logs  
✅ Exportable/auditable logs  
✅ Default admin changed  
✅ Business rules enforced  
✅ File integrity check  
⚠️ Unsigned logs → needs improvement  
⚠️ AD/LDAP unstable  
⚠️ OAuth2/API Key untested  
⚠️ No SIEM/SOC alerts yet  
