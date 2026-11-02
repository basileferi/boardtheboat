# Mise en production — boardtheboat.basileferi.fr (Infomaniak, SSH)

Les commandes ci-dessous se lancent **sur le serveur**, en SSH (bash). Aucune ne se lance sur le
poste Windows. Les secrets (mots de passe, clés) ne vont **jamais** dans git : ils vont dans
`.env.local` sur le serveur.

## 1. Dans le Manager Infomaniak (une fois)

1. **Site** `boardtheboat.basileferi.fr` → *Paramètres* :
   - **version PHP : 8.4 ou plus** (l'appli refuse de s'installer en dessous) ;
   - **dossier racine (document root) : `public`**. C'est le point le plus important : si le
     domaine pointe sur la racine du projet, le `.env` et le code sont téléchargeables.
2. **SSL** : certificat Let's Encrypt pour le sous-domaine, et *forcer HTTPS*.
3. **Base de données** `jh6rkj_boardtheboat` sur `jh6rkj.myd.infomaniak.com` : vide, c'est normal
   (voir l'étape 4). Dans phpMyAdmin Infomaniak, lancer `SELECT VERSION();` et noter le résultat,
   par exemple `10.11.9-MariaDB` : il sert dans `DATABASE_URL`.

## 2. Récupérer le code (SSH)

```bash
cd ~/sites/boardtheboat.basileferi.fr      # le dossier du site (vérifier le chemin exact dans le Manager)
php -v                                     # doit afficher 8.4 ou plus
git clone https://github.com/basileferi/boardtheboat.git .
composer install --no-dev --optimize-autoloader
```

`--no-dev` : pas de Faker, PHPUnit, Maker en prod. Le `composer install` lance aussi
`importmap:install` (il télécharge Stimulus et Turbo, le serveur a besoin d'internet pour ça).

## 3. Le fichier `.env.local` (sur le serveur seulement)

```bash
nano .env.local
```

```
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<32 caractères au hasard : php -r "echo bin2hex(random_bytes(16));">
DEFAULT_URI=https://boardtheboat.basileferi.fr
DATABASE_URL="mysql://<utilisateur>:<mot_de_passe>@jh6rkj.myd.infomaniak.com:3306/jh6rkj_boardtheboat?serverVersion=<VERSION exacte, ex. 10.11.9-MariaDB>&charset=utf8mb4"
TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR
APP_REGISTRATION_OPEN=0
MAILER_DSN=null://null
MAILER_FROM_ADDRESS=noreply@basileferi.fr
ADMIN_ALERT_ADDRESS=<ton e-mail>
HTTP_FETCHER_CONTACT_URL=https://boardtheboat.basileferi.fr
CRON_TOKEN=<64 caractères au hasard : php -r "echo bin2hex(random_bytes(32));">
JWT_PASSPHRASE=<une longue phrase au hasard>
```

À savoir :

- si le mot de passe de la base contient `@ : / ? # %`, il faut l'encoder dans l'URL
  (`@` → `%40`, `#` → `%23`...) : `php -r "echo rawurlencode('le_mot_de_passe');"` ;
- `serverVersion` doit être **la vraie version** du serveur Infomaniak, pas celle de WAMP ;
- `TRUSTED_PROXIES` : chez Infomaniak le HTTPS est géré en amont. Sans cette ligne, Symfony croit
  être en HTTP : cookies non sécurisés et liens `http://` dans les mails ;
- `MAILER_DSN=null://null` tant qu'il n'y a pas d'adresse mail sur le domaine. Pour envoyer de
  vrais mails : créer `noreply@basileferi.fr` chez Infomaniak, puis
  `MAILER_DSN=smtp://noreply%40basileferi.fr:<mdp>@mail.infomaniak.com:587` ;
- France Travail (optionnel) : `FRANCE_TRAVAIL_CLIENT_ID` / `_SECRET` après inscription sur
  francetravail.io. Les autres sources n'ont pas besoin de clé.

Ensuite :

```bash
composer dump-env prod      # fige tous les .env dans .env.local.php, lu en priorité et plus rapide
chmod 600 .env.local .env.local.php
```

## 4. La base de données : les migrations

La base est vide, et c'est voulu : **on ne copie pas la base de dev**. Les fichiers de
`migrations/` contiennent le SQL qui construit toutes les tables. On les lance une fois :

```bash
php bin/console doctrine:migrations:migrate -n
```

Doctrine crée les tables et note dans `doctrine_migration_versions` quelles migrations sont
passées. À chaque mise à jour du code, la même commande n'applique que les **nouvelles**
migrations, sans toucher aux données existantes. C'est ce qui rend le déploiement simple.

Vérification : `php bin/console doctrine:schema:validate` doit répondre que tout est synchronisé.

## 5. Clés JWT, assets, cache, premier compte

```bash
php bin/console lexik:jwt:generate-keypair        # clés de l'api, dans config/jwt (jamais dans git)
php bin/console asset-map:compile                 # css / js / polices en fichiers statiques
php bin/console cache:clear
php bin/console cache:warmup
php bin/console app:user:create-admin <ton e-mail>   # demande le mot de passe, sans l'afficher
php bin/console app:pipeline:run                  # premier remplissage : offres + entreprises
```

Ne pas lancer `doctrine:fixtures:load` en prod : les fixtures (Faker) sont des données de test et
elles ne sont d'ailleurs pas installées avec `--no-dev`.

## 6. Tâches planifiées (le "worker")

Les fiches entreprises et le pipeline de nuit passent par Messenger. Sur hébergement mutualisé il
n'y a pas de worker permanent : c'est une URL protégée qui le fait tourner ~40 s.

Manager → **Tâches planifiées (Cron)** → nouvelle tâche :

- type : appel d'URL ;
- URL : `https://boardtheboat.basileferi.fr/_cron/tick?token=<CRON_TOKEN>` ;
- fréquence : toutes les 15 minutes.

Sans le bon jeton, l'URL répond 404. Les mails, eux, partent tout de suite en prod (ils
n'attendent pas le cron). Si SSH donne accès à `crontab -e`, on peut à la place lancer directement
`php bin/console messenger:consume scheduler_default async --time-limit=840 --memory-limit=128M`.

## 7. Vérifier

- `https://boardtheboat.basileferi.fr/fr` s'affiche, en HTTPS ;
- `https://boardtheboat.basileferi.fr/.env` → **404** (sinon le dossier racine n'est pas `public`) ;
- connexion avec le compte admin, puis `/fr/admin` ;
- `https://boardtheboat.basileferi.fr/_cron/tick?token=<CRON_TOKEN>` → page vide (204).

## Chaque mise à jour

```bash
git pull
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate -n
php bin/console asset-map:compile
php bin/console cache:clear
```

Si tu as modifié `.env.local`, relance `composer dump-env prod`.

## En cas de souci

| symptôme | cause probable | solution |
|---|---|---|
| erreur 500 | voir `var/log/prod.log` | |
| seule la page d'accueil marche | `.htaccess` absent ou mod_rewrite | `public/.htaccess` doit exister (symfony/apache-pack) |
| page sans style | assets non compilés | `php bin/console asset-map:compile` |
| `Access denied for user` | identifiants ou mot de passe mal encodé dans `DATABASE_URL` | encoder le mot de passe, puis `composer dump-env prod` |
| `Unknown collation` ou erreur de syntaxe SQL | `serverVersion` ne correspond pas au serveur | mettre la vraie version, `dump-env prod`, `cache:clear` |
| redirection en boucle ou cookies perdus | proxy HTTPS non reconnu | `TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR` |
| JWT : `error:80000003` | config openssl introuvable | arrive surtout sous Windows ; sur le serveur, `openssl` en ligne de commande (voir cours 15) |
