# Mise en production — boardtheboat.basileferi.fr (Infomaniak, GitHub Actions)

Le déploiement est automatique : chaque push sur `master` passe la CI, construit une archive de
release, puis attend **ton approbation** dans GitHub avant d'être installée sur Infomaniak par SSH.
Les secrets de l'application (base, `APP_SECRET`, `CRON_TOKEN`, JWT) ne vont **jamais** dans git ni
dans GitHub : ils restent dans `shared/.env.local`, sur le serveur.

## Vue d'ensemble

```
push / pull request ──► secrets-scan (gitleaks, tout l'historique)
                    └─► qa : composer qa sur PHP 8.4 + MariaDB 10.6 (même version que la prod)
                             + migrations jouées sur une base vide puis schema:validate
push sur master ──────► build : composer --no-dev, importmap, asset-map:compile → release.tar.gz + sha256
                    └─► deploy (environnement "production", approbation manuelle)
                             upload SSH → vérif checksum → extraction → liens shared → cache:warmup
                             → test connexion base → mysqldump si migrations en attente → migrate
                             → bascule atomique du lien current → messenger:stop-workers → purge
```

Sur le serveur, dans `~/sites/boardtheboat.basileferi.fr` :

```
current -> releases/20261126101500-0123456789ab   ← document root : current/public
releases/
  20261126101500-0123456789ab/                    une release = un dossier figé (5 gardées)
shared/                                           survit aux déploiements
  .env.local                                      secrets de prod (chmod 600)
  config/jwt/{private,public}.pem                 clés de l'api
  var/log/                                        logs
  var/share/                                      état du scheduler, rate limiters
  backups/*.sql.gz                                dump avant chaque migration (10 gardés)
.incoming/                                        archives en transit (vidé après install)
```

Si une étape échoue avant la bascule, le site continue de servir l'ancienne release, intacte.
La bascule est un `rename` atomique : aucune requête ne voit un mélange d'ancien et de nouveau code.

## 1. Une seule fois : Manager Infomaniak

1. **Site** `boardtheboat.basileferi.fr` → version PHP **8.4**.
2. **SSL** : certificat Let's Encrypt actif, *forcer HTTPS*.
3. **FTP / SSH** : utilisateur SSH dédié au déploiement (`jh6rkj_deploy`).
   - Pas de restriction d'adresse IP sur SSH : les machines GitHub Actions changent d'IP à
     chaque exécution.
   - L'utilisateur `jh6rkj_temporary_*` ne doit pas servir à la CI (il expire).
4. **Base** : noter hôte, nom, utilisateur ; la version est `10.6.28-MariaDB`
   (`SELECT VERSION();` dans phpMyAdmin). Elle doit être identique dans `DATABASE_URL`.

## 2. Une seule fois : clé SSH de déploiement

Sur ton poste (Git Bash ou PowerShell 7) :

```bash
ssh-keygen -t ed25519 -N "" -C "boardtheboat-deploy" -f ~/.ssh/boardtheboat_deploy
```

- `~/.ssh/boardtheboat_deploy` (privée) → ira dans le secret GitHub `SSH_PRIVATE_KEY`, puis
  supprime-la de ton poste si tu ne t'en sers pas ailleurs ;
- `~/.ssh/boardtheboat_deploy.pub` (publique) → sur le serveur.

Autoriser la clé sur le serveur (demande le mot de passe SSH une dernière fois ; ne rien
afficher veut dire que c'est fait). En PowerShell :

```powershell
Get-Content $HOME\.ssh\boardtheboat_deploy.pub | ssh jh6rkj_deploy@jh6rkj.ftp.infomaniak.com "umask 077 && mkdir -p ~/.ssh && sed 's/^/restrict /' >> ~/.ssh/authorized_keys"
```

Vérifier : `ssh -i ~/.ssh/boardtheboat_deploy -o BatchMode=yes jh6rkj_deploy@jh6rkj.ftp.infomaniak.com 'echo ok'`
doit afficher `ok` sans demander de mot de passe. Sur cet hébergement, tous les utilisateurs SSH
partagent le même dossier personnel, donc le même `authorized_keys`.

`restrict` coupe le transfert de ports, d'agent, X11 et le pseudo-terminal pour cette clé : elle
ne sert qu'à lancer des commandes et copier des fichiers.

**Empreinte du serveur** (pour que la CI refuse un faux serveur) : depuis ton poste, après t'être
déjà connecté une fois en SSH au serveur,

```bash
ssh-keygen -F jh6rkj.ftp.infomaniak.com -f ~/.ssh/known_hosts | grep -v '^#'
```

La ou les lignes affichées vont dans le secret `SSH_KNOWN_HOSTS`. Si le port n'est pas 22,
cherche `[jh6rkj.ftp.infomaniak.com]:<port>`.

Si rien ne s'affiche (jamais connecté depuis ce poste), connecte-toi une première fois :
`ssh -i ~/.ssh/boardtheboat_deploy jh6rkj_deploy@jh6rkj.ftp.infomaniak.com`. Avant de répondre `yes`, compare
l'empreinte proposée avec celle que donne la console web du Manager :
`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`. N'accepte que si elles sont identiques.

## 3. Une seule fois : préparer le dossier du site

Depuis ton poste, à la racine du projet :

```bash
scp deploy/server-init.sh jh6rkj_deploy@jh6rkj.ftp.infomaniak.com:~/
ssh jh6rkj_deploy@jh6rkj.ftp.infomaniak.com
bash ~/server-init.sh "$HOME/sites/boardtheboat.basileferi.fr" && rm ~/server-init.sh
```

Le script (relançable sans risque) crée `releases/`, `shared/`, `.incoming/`, une page d'attente
servie par `current/public`, et `shared/.env.local` avec `APP_SECRET`, `CRON_TOKEN` et
`JWT_PASSPHRASE` générés sur le serveur. Il ne remplace jamais un `.env.local` existant.

Compléter les `CHANGE_ME` (le déploiement refuse de tourner tant qu'il en reste un) :

```bash
nano ~/sites/boardtheboat.basileferi.fr/shared/.env.local
```

- `DATABASE_URL` : mot de passe **encodé pour une URL** (`#` → `%23`, `@` → `%40`, `?` → `%3F`...) :
  `/opt/php8.4/bin/php -r "echo rawurlencode('le_mot_de_passe');"` ;
- `DEFAULT_URI` et `HTTP_FETCHER_CONTACT_URL` : `https://boardtheboat.basileferi.fr` ;
- `MAILER_DSN=null://null` tant qu'il n'y a pas de boîte mail. Pour envoyer : créer
  `noreply@basileferi.fr`, puis `MAILER_DSN=smtp://noreply%40basileferi.fr:<mdp encodé>@mail.infomaniak.com:587` ;
- `FRANCE_TRAVAIL_CLIENT_ID` / `_SECRET` : optionnels (francetravail.io).

Puis **Manager → site → dossier racine** : `sites/boardtheboat.basileferi.fr/current/public`
(chemin tel que le Manager l'attend). Ouvrir `https://boardtheboat.basileferi.fr` : la page
« Installation en cours. » doit s'afficher. Elle prouve que le document root suit bien le lien
`current`.

## 4. Une seule fois : GitHub

Dépôt → *Settings* → *Environments* → **New environment** `production` :

- **Required reviewers** : toi. Rien ne part en prod sans ton clic. Laisser *Prevent
  self-review* décoché, sinon tu ne peux pas approuver tes propres déploiements.
- **Deployment branches and tags** : *Selected branches* → `master` uniquement.

Dans cet environnement :

| type | nom | valeur |
|---|---|---|
| secret | `SSH_PRIVATE_KEY` | contenu complet de `boardtheboat_deploy` (clé privée) |
| secret | `SSH_KNOWN_HOSTS` | ligne(s) obtenues à l'étape 2 |
| variable | `SSH_HOST` | hôte SSH Infomaniak |
| variable | `SSH_USER` | `jh6rkj_deploy` |
| variable | `SSH_PORT` | `22` (facultatif, 22 par défaut) |
| variable | `DEPLOY_PATH` | `/home/clients/<identifiant>/sites/boardtheboat.basileferi.fr` (sortie de `echo "$HOME"` en SSH) |
| variable | `APP_URL` | `https://boardtheboat.basileferi.fr` |
| variable | `PHP_BIN` | facultatif, `/opt/php8.4/bin/php` par défaut |
| variable | `KEEP_RELEASES` / `KEEP_BACKUPS` | facultatifs, `5` / `10` par défaut |

Recommandé aussi : *Settings* → *Branches* → règle sur `master` exigeant les checks
`Secret scan` et `Quality gate (PHP 8.4, MariaDB 10.6)` avant fusion d'une pull request.

## 5. Premier déploiement

Push sur `master` (ou *Actions* → *CI/CD* → *Run workflow* sur `master`), attendre la CI, puis
**Review deployments** → *Approve*. Le premier déploiement génère aussi les clés JWT dans
`shared/config/jwt` et applique toutes les migrations sur la base vide.

On ne copie jamais la base de dev : les migrations construisent le schéma, et chaque mise à jour
n'applique que les nouvelles. Ne jamais lancer `doctrine:fixtures:load` en prod (Faker n'y est
d'ailleurs pas installé).

Ensuite, une fois, en SSH :

```bash
cd ~/sites/boardtheboat.basileferi.fr/current
/opt/php8.4/bin/php bin/console app:user:create-admin <ton e-mail>   # demande le mot de passe sans l'afficher
/opt/php8.4/bin/php bin/console app:pipeline:run                     # premier remplissage
```

## 6. Tâches planifiées (le « worker »)

Pas de worker permanent sur l'hébergement mutualisé : une URL protégée consomme la file Messenger
pendant ~40 s. Manager → **Tâches planifiées** → nouvelle tâche :

- type : appel d'URL ;
- URL : `https://boardtheboat.basileferi.fr/_cron/tick?token=<CRON_TOKEN de shared/.env.local>` ;
- fréquence : toutes les 15 minutes.

Sans le bon jeton, l'URL répond 404. Les mails partent tout de suite (transport `sync` en prod).

## 7. Vérifier

- `https://boardtheboat.basileferi.fr/fr` s'affiche en HTTPS ;
- `https://boardtheboat.basileferi.fr/.env.local` et `/composer.json` → **404** ;
- connexion avec le compte admin, puis `/fr/admin` ;
- `https://boardtheboat.basileferi.fr/_cron/tick?token=<CRON_TOKEN>` → réponse vide (204).

## Chaque mise à jour

Rien à faire en SSH : push sur `master`, CI verte, approbation. Les nouvelles migrations sont
appliquées automatiquement après un `mysqldump` de la base dans `shared/backups/`.

Les migrations sont jouées **avant** la bascule : pendant quelques secondes l'ancien code tourne
sur le nouveau schéma. Une migration doit donc rester compatible avec le code précédent (ajouter
une colonne nullable, puis la rendre obligatoire dans une release suivante, etc.).

Une modification de `shared/.env.local` s'applique dès la requête suivante : le fichier est relu
à chaque requête, aucun redéploiement n'est nécessaire. Pour forcer une nouvelle release sans
nouveau commit : *Actions* → *CI/CD* → *Run workflow* sur `master` (pas *Re-run* du seul job
`deploy`, qui réutiliserait l'identifiant de la release déjà en ligne et serait refusé).

OPcache peut servir l'ancienne release pendant au plus ~2 minutes après la bascule (cache des
chemins de PHP). L'ancienne release reste sur le disque, donc rien ne casse pendant ce délai.

## Revenir en arrière

*Actions* → **Rollback production** → *Run workflow* (laisser vide pour la release précédente, ou
donner un identifiant comme `20261126101500-0123456789ab`) → approbation. Le lien `current`
repointe instantanément sur la release choisie.

**La base n'est pas restaurée.** Si la release abandonnée avait apporté des migrations, restaurer
le dump pris juste avant (nom : `<base>-<date>-before-<release>.sql.gz`) :

```bash
cd ~/sites/boardtheboat.basileferi.fr/shared/backups && ls -lt
gunzip -c <fichier>.sql.gz | mysql -h <hôte base> -u <utilisateur> -p <base>
```

Sans passer par GitHub, la même bascule se fait à la main en SSH :

```bash
cd ~/sites/boardtheboat.basileferi.fr && ls releases/
ln -sfn releases/<id> .current.next && mv -Tf .current.next current
```

## En cas de souci

| symptôme | cause probable | solution |
|---|---|---|
| deploy : `.env.local still contains CHANGE_ME` | fichier pas complété | compléter `shared/.env.local` |
| deploy : `cannot reach the database` | `DATABASE_URL` faux, mot de passe non encodé | encoder le mot de passe (étape 3) |
| deploy : `Host key verification failed` | `SSH_KNOWN_HOSTS` absent ou faux | refaire l'étape 2 |
| deploy : `Permission denied (publickey)` | clé publique pas autorisée pour `jh6rkj_deploy` | refaire l'étape 2 |
| CI rouge sur `composer audit` | dépendance avec une faille connue | `composer update <paquet>` puis push : le déploiement est bloqué tant que la faille est présente |
| erreur 500 | voir `~/ik-logs/` (logs Apache/PHP d'Infomaniak) et `shared/var/log/` | |
| la page d'attente reste affichée | document root pas sur `current/public` | étape 3 |
| `Unknown collation` / erreur SQL | `serverVersion` ≠ `10.6.28-MariaDB` | corriger `DATABASE_URL` dans `shared/.env.local` (pris en compte immédiatement) |
| liens `http://`, cookies non sécurisés | HTTPS non détecté | vérifié le 2026-11-26 : Infomaniak passe `HTTPS=on` et la vraie IP du client à PHP, donc `TRUSTED_PROXIES=127.0.0.1`. Ne jamais y ajouter `REMOTE_ADDR` : tout client pourrait alors falsifier `X-Forwarded-For` et contourner les limites de requêtes par IP |
