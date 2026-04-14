# Guide de contribution — Velixa for Gen AI

Merci de votre intérêt pour Velixa for Gen AI ! Ce guide explique comment contribuer au projet.

---

## Code de conduite

En contribuant à ce projet, vous acceptez de respecter notre [Code de conduite](CODE_OF_CONDUCT.md).

---

## Comment contribuer ?

### Signaler un bug

1. Vérifiez que le bug n'est pas déjà signalé dans les [Issues](https://github.com/CpXDcP/velixa-community/issues)
2. Ouvrez une nouvelle Issue avec le label `bug`
3. Décrivez précisément :
   - Le comportement observé
   - Le comportement attendu
   - Les étapes pour reproduire
   - Votre environnement (OS, PHP, version Velixa)
4. **Ne jamais inclure de données personnelles, clés API ou credentials dans une Issue**

### Proposer une amélioration

1. Ouvrez une Issue avec le label `enhancement`
2. Décrivez le besoin et la valeur ajoutée
3. Attendez un retour avant de coder — évite les travaux inutiles

### Soumettre du code

1. **Forkez** le dépôt
2. Créez une branche depuis `main` :
   ```
   git checkout -b feature/ma-feature
   ```
3. Codez votre contribution
4. Respectez les règles ci-dessous
5. Commitez avec un message clair :
   ```
   git commit -m "feat: description courte de la feature"
   ```
6. Poussez sur votre fork :
   ```
   git push origin feature/ma-feature
   ```
7. Ouvrez une **Pull Request** vers `main`

---

## Règles de contribution

### Sécurité — obligatoire

- **Ne jamais committer** : clés API, mots de passe, tokens, données personnelles
- **Ne jamais committer** : fichiers `users.json`, `*.key`, `secrets_*.json`
- Vérifier le `.gitignore` avant chaque commit
- Toute vulnérabilité de sécurité doit être signalée en privé via [SECURITY.md](SECURITY.md)

### Code PHP

- PHP 8.1+ minimum
- Déclarer `strict_types=1` en début de fichier
- Utiliser `vx_h()` pour l'échappement HTML
- Utiliser `vx_csrf_field()` pour les formulaires
- Pas de `eval()`, pas de `exec()` sans validation stricte
- Les appels LLM externes doivent toujours inclure `CURLOPT_PROXY => ''`

### Périmètre Community vs Enterprise

Les contributions au dépôt Community doivent rester dans le périmètre de la licence Community :
- ✅ Amélioration du pipeline de détection
- ✅ Nouveaux patterns de détection (wordlists, règles)
- ✅ Corrections de bugs
- ✅ Amélioration de l'interface utilisateur
- ✅ Documentation et traductions
- ❌ Features Enterprise (LDAP, tokenisation PII, Legal Hold, multi-tenant...)

### Règles sectorielles

Les contributions de règles de conformité sectorielles (RGPD, NIS2, EU AI Act, DORA...) sont particulièrement bienvenues. Elles vont dans le dossier `policies/` ou `wordlists/`.

---

## Processus de review

1. Toute PR est relue dans les 7 jours ouvrables
2. Au moins une approbation est requise pour merger
3. Les tests existants doivent passer
4. Le code doit respecter les règles ci-dessus

---

## Questions ?

Ouvrez une [Discussion GitHub](https://github.com/CpXDcP/velixa-community/discussions) pour toute question générale.

---

*Velixa for Gen AI Community — Merci pour votre contribution !*
