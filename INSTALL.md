# VELIXA — Guide d'installation XAMPP

## Déploiement rapide

1. Copiez le dossier `velixa/` dans `C:\xampp\htdocs\`
2. Accédez à `http://localhost/velixa/`

## Prérequis XAMPP

### Activer l'extension LDAP (pour AD/LDAP)
Dans `C:\xampp\php\php.ini`, décommenter :
```
extension=ldap
```
Puis redémarrer Apache.

### Installer Ollama (analyse contextuelle IA)
1. Télécharger Ollama : https://ollama.ai
2. Lancer : `ollama serve`
3. Installer Phi-3 Mini : `ollama pull phi3:mini`
4. Vérifier : `curl http://127.0.0.1:11434/api/tags`

### Installer les dépendances PHP (PDF export)
Dans le dossier velixa/ :
```
composer install
```
(Nécessite Composer : https://getcomposer.org)

### Installer les dépendances Python (analyse documents)
```
pip install pymupdf python-docx
```

## Premier démarrage

1. Ouvrez `http://localhost/velixa/`
2. Login : `admin` / `Admin@Velixa2024!`
3. Vous serez forcé de changer le mot de passe
4. Configurez le MFA (TOTP)
5. Configurez vos providers IA dans Admin → Providers

## Configuration AD/LDAP

1. Admin → AD/LDAP Connectors
2. Créer un connecteur (ldap:// ou ldaps://)
3. **Important** : si StartTLS = No → désactiver dans le formulaire
4. Tester la connexion avant de sauvegarder
5. Créer un pipeline OU → Métiers

## Contrôle des bots

- Admin → **Bots & Agents IA** (nouveau panneau unifié)
- Ajouter des bots Ollama locaux ou distants
- Configurer les allowlists par bot
- Monitorer le trafic en temps réel

## Sécurité post-installation

- [ ] Changer le mot de passe admin
- [ ] Activer le MFA pour tous les admins
- [ ] Configurer HTTPS (certificat SSL)
- [ ] Vérifier que `AllowOverride All` est actif dans httpd.conf
- [ ] Ne jamais committer `users.json`, `config/secrets_providers.json`, `data/secure/`

## Structure des dossiers

```
velixa/
├── inc/           Librairies PHP (bootstrap, auth, pipeline...)
├── config/        Configurations JSON (protégé .htaccess)
├── policies/      Règles de conformité par domaine
├── providers/     Classes providers IA
├── jobs/          Tâches LDAP sync (cron)
├── logs/          Journaux (protégé .htaccess)
├── data/secure/   Clés maîtres (protégé .htaccess)
├── uploads/       Fichiers uploadés (pas d'exécution PHP)
├── wordlists/     Listes de mots (protégé .htaccess)
└── vendor/        Dépendances Composer
```
