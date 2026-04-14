# Changelog — Velixa for Gen AI

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/).
Ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

---

## [1.1.0] — 2026-04-14

### Première release publique Community — AGPL v3

#### Ajouté
- Pipeline de détection et blocage des prompts non-conformes
- Score de criticité 0-100 par prompt analysé
- Explications réglementaires pédagogiques (RGPD, NIS2, EU AI Act) — éduquer plutôt que sanctionner
- Réécriture conforme du prompt bloqué par phi3:mini local
- Sortie vers API externe au choix (Groq, OpenAI, Anthropic, Google Gemini)
- Traduction confidentielle locale via phi3:mini — données jamais envoyées à l'extérieur
- Graphiques de conformité pour présentation au board
- Export PDF des journaux de logs
- Human In The Loop (HITL) — approbation humaine EU AI Act Art.14 (pay-per-use)
- Consentement IA tracé — EU AI Act Art.52
- Gestion des utilisateurs locaux (users.json)
- Authentification MFA TOTP
- Droit à l'oubli RGPD Art.17 — purge ciblée via CLI (`php jobs/purge_logs.php --forget=username`)
- Interface utilisateur améliorée
- Durcissement sécurité général
- Stabilisation export PDF logs
- Support multi-providers LLM : Groq, OpenAI, Anthropic, Gemini
- Bypass proxy Windows automatique (CURLOPT_PROXY vide)
- Scripts de debug Ollama et Groq inclus

#### Technique
- PHP 8.1+ avec strict_types
- Chiffrement AES-256-GCM pour les clés API et prompts sensibles
- Pipeline de sécurité 18 points de contrôle
- Détection : données personnelles, credentials, jailbreak, prompt injection
- Ollama + phi3:mini (MIT) — IA locale déterministe (temperature=0, seed=42)
- dompdf (LGPL v2.1) — génération PDF
- qrcode.js (MIT) — QR codes MFA

---

## [1.0.0] — 2025-10-01

### Release initiale (privée)

- Version initiale du pipeline de gouvernance IA
- Interface d'administration basique
- Support Groq uniquement
- Gestion utilisateurs locale

---

## À venir — [1.2.0]

- Scripts d'installation automatisés (Windows, Linux, macOS)
- Tamper-proof logging — hash chaîné sur chaque écriture
- Séparation des pouvoirs — rôle auditeur en lecture seule
- Cartographie souveraineté LLM
- Calculateur ROI amendes évitées

---

*Velixa for Gen AI Community — AGPL v3*
