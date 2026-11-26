# boardtheboat — ce que j'ai fait, cours par cours

Ce document sert à expliquer le projet. Pour chaque cours et chaque activité du dépôt
`Symfony7-main`, tu trouves : ce que le cours demandait, où c'est dans le projet (avec le lien
vers le fichier), comment ça marche expliqué simplement, et ce qui change par rapport au cours
(et pourquoi). À la fin : les questions que le prof risque de poser, avec des réponses courtes.

---

## 0. L'appli en 30 secondes

**boardtheboat** aide à trouver un stage ou une alternance, surtout en candidature spontanée.

- **mode automatique** : chaque nuit, l'appli va chercher des offres et des entreprises sur des
  API publiques gratuites, enlève les doublons, et les classe selon ton profil (mots-clés,
  secteur, ville...). Tu ouvres l'appli et la liste est déjà prête.
- **mode manuel** : tu ajoutes toi-même les entreprises que tu as trouvées, tu suis tes
  candidatures (repérée → envoyée → entretien...), et l'appli te rappelle quand faire une relance.

C'est le même principe que le site de films du cours, mais avec des **entreprises** et des
**offres** à la place des films et des catégories.

### Lancer le projet

```
composer install
php bin/console doctrine:migrations:migrate -n
php bin/console doctrine:fixtures:load --group=users --append   # crée les comptes de test
symfony server:start -d
```

Puis http://127.0.0.1:8000. La base est sur **MariaDB port 3307** (WAMP fait tourner MySQL sur
3306 et MariaDB sur 3307 ; dans phpMyAdmin il faut choisir le serveur « MariaDB »).

### Comptes de test

| e-mail | mot de passe | rôle |
|---|---|---|
| `admin@test.fr` | `boardtheboat` | admin (accès à `/fr/admin`) |
| `user@test.fr` | `boardtheboat` | utilisateur, avec un profil déjà rempli |

Le mot de passe fait 12 caractères parce que l'inscription impose un minimum de 12.

### Les commandes à montrer

| commande | ce qu'elle fait |
|---|---|
| `php bin/console debug:router` | toutes les routes de l'appli (cours 1) |
| `php bin/console app:pipeline:run` | lance le mode automatique en entier |
| `php bin/console messenger:consume async -vv` | le « worker » qui traite les tâches en fond |
| `php bin/console doctrine:fixtures:load` | remplit la base de fausses données (cours 12) |
| `composer test` | lance les tests (cours 14) |
| `composer qa` | style du code + analyse statique + tests, tout d'un coup |

---

## Tableau récap : chaque cours, et où il est dans le projet

| cours / activité | ce qu'on y apprend | où le regarder |
|---|---|---|
| Cours 1 | créer le projet, routes, paramètres, `Response` / `render` / `JsonResponse` | [`HomeController`](src/Controller/HomeController.php), [`SecurityController`](src/Controller/SecurityController.php) |
| Cours 2 + Activité 1 | Twig : `extends`, `block`, `include`, `set`, `if`, `for`, dates, `path`/`url` | [`base.html.twig`](templates/base.html.twig), [`company/show.html.twig`](templates/company/show.html.twig) |
| Cours 3 + Activités 2-3 | Doctrine : entités, relations, migrations, repository, `find`/`findAll`, injection de dépendances | [`src/Entity/`](src/Entity), [`migrations/`](migrations), [`CompanyController`](src/Controller/CompanyController.php) |
| Cours 4 + Activité 4 | requêtes perso avec le QueryBuilder | [`JobOfferRepository`](src/Repository/JobOfferRepository.php), [`CompanyRepository`](src/Repository/CompanyRepository.php) |
| Cours 5 | formulaires, `handleRequest`, `persist` / `flush` / `remove` | [`CompanyType`](src/Form/CompanyType.php), [`CompanyManager`](src/Service/Company/CompanyManager.php) |
| Cours 6 | redirections, `addFlash`, UX Turbo (frames + streams) | [`offer/index.html.twig`](templates/offer/index.html.twig), [`_save_button.stream.html.twig`](templates/application/_save_button.stream.html.twig) |
| Cours 7 + Activité 5 | validation, `UniqueEntity`, `empty_data`, contrainte perso | [`CompanyInput`](src/Dto/CompanyInput.php), [`SafeUrl`](src/Validator/SafeUrl.php) |
| Cours 8 + Activité 6 | `make:crud`, suppression en `DELETE` | [`CompanyController`](src/Controller/CompanyController.php), [`_delete_form.html.twig`](templates/company/_delete_form.html.twig) |
| Cours 9 + Activité 7 | API JSON, groupes de sérialisation, `MapRequestPayload` | [`src/Controller/Api/`](src/Controller/Api) |
| Cours 10 + Activité 8 | utilisateurs, connexion, inscription, `IsGranted`, `is_granted` | [`security.yaml`](config/packages/security.yaml), [`User`](src/Entity/User.php), voters |
| Cours 11 | envoyer des mails | [`EmailVerifier`](src/Service/Security/EmailVerifier.php), [`templates/email/`](templates/email) |
| Cours 12 + Activité 9 | fixtures + Faker, références entre fixtures | [`src/DataFixtures/`](src/DataFixtures) |
| Cours 13 | mise en prod | [`docs/DEPLOY.md`](docs/DEPLOY.md), [`public/.htaccess`](public/.htaccess) |
| Cours 14 | tests unitaires, Kernel, Web | [`tests/`](tests) |
| Cours 15 | jeton JWT pour l'API | [`security.yaml`](config/packages/security.yaml), [`lexik_jwt_authentication.yaml`](config/packages/lexik_jwt_authentication.yaml) |

---

## Cours 1 — Nouveau projet, routes, réponses

**Ce que le cours demandait.** Créer le projet avec `symfony new --webapp`, faire un premier
contrôleur, une route, récupérer un paramètre dans l'URL, et connaître les 3 types de réponse.

**Dans le projet.**

- le projet a été créé avec `symfony new --webapp` (version 8.1 de Symfony, PHP 8.5) ;
- les routes sont écrites en **attributs** au-dessus des méthodes, comme dans la 2ᵉ partie du
  cours. Exemple dans [`HomeController`](src/Controller/HomeController.php) :

  ```php
  #[Route(path: '/{_locale}', name: 'app_home', requirements: ['_locale' => 'fr|en'], defaults: ['_locale' => 'fr'])]
  ```

  `{_locale}` est un **paramètre d'URL**, exactement comme `{prenom}` dans le cours. La différence :
  Symfony connaît ce nom-là et s'en sert pour choisir la langue (`/fr` ou `/en`). `requirements`
  dit « seulement fr ou en » (sinon 404), `defaults` dit « fr si on ne précise rien ».
- les **3 types de réponse** sont tous utilisés :
  - `render()` → une vue Twig (presque partout) ;
  - `new Response(...)` → une réponse brute, par exemple la suppression via l'API renvoie juste
    un code 204 sans contenu ([`CompanyApiController::delete`](src/Controller/Api/CompanyApiController.php)) ;
  - `json()` (qui fabrique une `JsonResponse`) → toute l'API (voir cours 9).
- le paramètre qu'on lit avec `$request->query->get(...)` (l'équivalent de `$_GET`) : dans
  [`CompanyController::index`](src/Controller/CompanyController.php), `$request->query->getString('q')` lit
  la recherche tapée dans l'URL `?q=...`.
- `php bin/console debug:router` liste toutes les routes.

**Différence avec le cours.** Les chemins sont en anglais (`/fr/companies`, `/en/companies`) et
seule la langue change devant. C'est plus simple que de traduire chaque URL.

---

## Cours 2 + Activité 1 — Twig

**Ce que le cours demandait.** `extends`, `block`, `include`, `set`, des conditions, une boucle
qui affiche clé/valeur dans un tableau, une date formatée en français, `path` et `url`, et une
condition sur la route courante.

**Dans le projet.**

| notion | où | ce que ça fait |
|---|---|---|
| `extends` + `block` | toutes les pages, ex. [`company/index.html.twig`](templates/company/index.html.twig) | la page remplit les blocs `title` et `body` de [`base.html.twig`](templates/base.html.twig) |
| `include` | [`company/new.html.twig`](templates/company/new.html.twig) inclut [`_form.html.twig`](templates/company/_form.html.twig) | le même formulaire sert pour « ajouter » et « modifier » |
| `set` | [`base.html.twig`](templates/base.html.twig) : `{% set links = [...] %}` | on prépare la liste des liens du menu |
| `for` | le menu, la liste des entreprises, les cartes d'offres | |
| tableau clé / valeur | [`company/show.html.twig`](templates/company/show.html.twig) | chaque ligne : le nom du champ, puis sa valeur |
| `if` | partout ; ex. afficher « modifier » seulement si on a le droit | |
| date en français | `format_date` dans [`offer/_detail.html.twig`](templates/offer/_detail.html.twig) | c'est le paquet `twig/intl-extra` (la commande composer de l'activité) |
| `path` | tous les liens internes | `path('app_company_show', {id: company.id})` |
| `url` | les mails ([`email/reset_password.html.twig`](templates/email/reset_password.html.twig)) | dans un mail il faut l'adresse complète avec le domaine, donc `url` |
| condition sur la route | le menu de [`base.html.twig`](templates/base.html.twig) | le lien de la page où on est reçoit `aria-current="page"` (et un style différent) |

**Le `|raw` de l'activité.** Le cours dit « il faut savoir le faire, mais attention si ça vient de
l'utilisateur ». Dans le projet, je ne l'utilise **jamais** : toutes les descriptions d'offres
viennent d'API extérieures, donc c'est du texte qu'on ne contrôle pas. Twig échappe tout
automatiquement (un `<script>` devient du texte affiché, pas du code exécuté). Les descriptions
sont même transformées en texte brut avant d'être enregistrées
([`PlainText`](src/Service/Ingestion/PlainText.php)).

**Différence avec le cours.** Pas de Bootstrap ni de Tailwind en CDN : le style est écrit à la
main dans [`assets/styles/app.css`](assets/styles/app.css) et chargé par AssetMapper (sans npm).
Un CDN externe serait d'ailleurs bloqué par la politique de sécurité du site (CSP, voir plus bas).

---

## Cours 3 + Activités 2 et 3 — Doctrine

**Ce que le cours demandait.** Créer des entités et leurs relations, faire les migrations,
récupérer les données avec le repository, faire une page liste et une page détail avec un lien
par élément.

**Les entités** sont dans [`src/Entity/`](src/Entity). Les principales :

- [`Company`](src/Entity/Company.php) : une entreprise (l'équivalent de `Film`) ;
- [`JobOffer`](src/Entity/JobOffer.php) : une offre, **liée à une entreprise** (`ManyToOne` : plusieurs
  offres pour une entreprise, comme plusieurs films pour une catégorie) ;
- [`User`](src/Entity/User.php) et [`Profile`](src/Entity/Profile.php) : un utilisateur a **un seul** profil (`OneToOne`) ;
- [`Application`](src/Entity/Application.php) : une candidature, liée à un utilisateur, une entreprise, et
  éventuellement une offre.

Le mapping est écrit en attributs, comme dans le cours :

```php
#[ORM\ManyToOne(targetEntity: Company::class)]
#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
private Company $company;
```

`onDelete: 'CASCADE'` : si on supprime l'entreprise, ses offres partent avec, c'est la base de
données qui s'en occupe.

**Les statuts** (ouverte / fermée, stage / alternance...) sont des **enums PHP** dans
[`src/Enum/`](src/Enum) au lieu de simples chaînes. Avantage : impossible d'enregistrer une faute de frappe
comme `"ouvret"`, PHP refuse.

**Les migrations** sont dans [`migrations/`](migrations), générées puis relues à la main. Deux pièges
rencontrés :

1. le MariaDB de WAMP crée les tables en **MyISAM** par défaut, et MyISAM ignore les clés
   étrangères sans rien dire. J'ai forcé **InnoDB** dans [`doctrine.yaml`](config/packages/doctrine.yaml) ;
2. j'ai imposé l'encodage `utf8mb4_unicode_ci` pour que les accents et les emojis passent partout.

**Le repository et l'injection de dépendances.** Comme dans le cours, on demande le repository
entre les parenthèses de la méthode et Symfony nous le donne, sans `new` :

```php
public function index(Request $request, CompanyRepository $companies): Response
```

**Récupérer un objet depuis l'URL** (le « trop fort » du cours) :

```php
#[Route('/{id}', name: 'app_company_show')]
public function show(#[MapEntity(mapping: ['id' => 'id'])] Company $company): Response
```

Symfony lit l'`id` dans l'URL, va chercher l'entreprise en base, et nous la donne directement.
`MapEntity` est la façon actuelle (Symfony 8) d'écrire ce que le cours fait sans attribut.

**Liste + détail + lien (activité 3)** : [`company/index.html.twig`](templates/company/index.html.twig) affiche
la liste, chaque nom est un lien `path('app_company_show', {id: company.id})` vers
[`company/show.html.twig`](templates/company/show.html.twig). Pareil pour les offres
([`offer/index.html.twig`](templates/offer/index.html.twig)).

**Différence avec le cours : les identifiants.** Le cours utilise des `id` 1, 2, 3... Ici ce sont
des **UUID** (une longue suite comme `01a0f1f2-b6ab-7f45-...`). Pourquoi : avec des numéros qui se
suivent, n'importe qui peut deviner l'adresse de l'entreprise suivante (`/companies/42`,
`/companies/43`...). Avec un UUID c'est impossible à deviner. Ça ne remplace pas la vérification des
droits (voir les voters), mais ça ajoute une couche.

---

## Cours 4 + Activité 4 — Requêtes personnalisées (QueryBuilder)

**Ce que le cours demandait.** Écrire une méthode de repository avec le QueryBuilder, par exemple
« les films plus récents que telle année ».

**Dans le projet, l'équivalent exact** : « les offres publiées depuis moins de N jours », dans
[`JobOfferRepository::findPublishedSince`](src/Repository/JobOfferRepository.php) :

```php
return $this->createQueryBuilder('o')
    ->addSelect('c')
    ->innerJoin('o.company', 'c')       // on récupère l'entreprise dans la même requête
    ->andWhere('o.status = :open')
    ->andWhere('o.publishedAt >= :since')
    ->setParameter('open', OfferStatus::Open->value)
    ->setParameter('since', new \DateTimeImmutable("-$days days"))
    ->orderBy('o.publishedAt', 'DESC')
    ->setMaxResults($limit)
    ->getQuery()
    ->getResult();
```

On la voit en marche sur l'API : `/api/offers?days=7`.

Il y a d'autres requêtes perso plus grosses :

- [`JobOfferRepository::search`](src/Repository/JobOfferRepository.php) : la recherche des offres avec tous les filtres
  (mot-clé, ville, type de contrat...). Elle ajoute un `andWhere` seulement pour les filtres
  remplis, c'est la « factorisation en une seule méthode » que propose l'activité ;
- [`CompanyRepository::searchVisibleTo`](src/Repository/CompanyRepository.php) : la liste des entreprises que
  l'utilisateur a le droit de voir (le catalogue + les siennes, jamais celles des autres).

**Toujours `setParameter`, jamais de concaténation** : c'est ce qui protège des injections SQL
(comme `bindParam` avec PDO, le cours le dit).

**Bonus.** La recherche par mot-clé utilise un index **FULLTEXT** de MariaDB. Doctrine ne connaît
pas `MATCH ... AGAINST`, alors j'ai écrit une petite fonction DQL
([`MatchAgainstFunction`](src/Doctrine/Query/MatchAgainstFunction.php)). C'est exactement la « limite des ORM »
dont parle le cours 3.

---

## Cours 5 — Formulaires

**Ce que le cours demandait.** Créer un formulaire avec `make:form`, l'afficher avec Twig, le
traiter avec `handleRequest` / `isSubmitted` / `isValid`, puis enregistrer avec l'entity manager.

**Dans le projet.** Le formulaire d'entreprise : [`CompanyType`](src/Form/CompanyType.php). Le traitement dans
[`CompanyController::new`](src/Controller/CompanyController.php), exactement le schéma du cours :

```php
$input = new CompanyInput();
$form = $this->createForm(CompanyType::class, $input);
$form->handleRequest($request);

if ($form->isSubmitted() && $form->isValid()) {
    $company = $this->manager->create($input, $this->currentUser());
    $this->addFlash('success', 'company.flash.created');

    return $this->redirectToRoute('app_company_show', ['id' => $company->getId()]);
}

return $this->render('company/new.html.twig', ['form' => $form]);
```

L'enregistrement est dans [`CompanyManager`](src/Service/Company/CompanyManager.php) : `persist()` + `flush()` pour créer,
juste `flush()` pour modifier (l'entity manager connaît déjà l'objet), `remove()` + `flush()`
pour supprimer. C'est mot pour mot les trois cas du cours.

Les autres formulaires : le profil ([`ProfileType`](src/Form/ProfileType.php)), l'inscription
([`RegistrationFormType`](src/Form/RegistrationFormType.php)), et la recherche d'offres ([`OfferSearchType`](src/Form/OfferSearchType.php)).

**Le jeton caché CSRF** dont parle le cours est là sur tous les formulaires (Symfony l'ajoute tout
seul).

**Différences avec le cours (et pourquoi).**

- **le formulaire ne remplit pas directement l'entité**, il remplit un petit objet à part
  ([`CompanyInput`](src/Dto/CompanyInput.php), on appelle ça un DTO). Pourquoi : si le formulaire écrivait dans
  l'entité, quelqu'un de malin pourrait ajouter un champ caché `owner` ou `visibility` dans la
  requête. Avec le DTO, le formulaire ne connaît que les champs autorisés, et c'est le service qui
  recopie dans l'entité ;
- **la logique est dans un service** ([`CompanyManager`](src/Service/Company/CompanyManager.php)) et pas dans le contrôleur.
  Le contrôleur ne fait que : recevoir, vérifier, appeler le service, afficher. C'est la
  séparation des responsabilités (SoC) dont parle le readme du cours ;
- **la recherche d'offres est un formulaire en GET** : les filtres sont dans l'URL, donc une
  recherche peut se copier-coller ou se mettre en favori ;
- pas de `form_themes` Bootstrap : chaque champ est affiché avec `form_row()` et stylé dans le CSS.

---

## Cours 6 — Messages retour et UX Turbo

**Redirection + message flash.** Après chaque action réussie : `addFlash(...)` puis
`redirectToRoute(...)` (voir le code du cours 5 juste au-dessus). Les messages sont affichés une
seule fois dans [`base.html.twig`](templates/base.html.twig) avec `app.flashes`.

Petite différence : le message n'est pas une phrase écrite dans le contrôleur, c'est une **clé**
(`company.flash.created`) traduite en français ou en anglais par le fichier
[`translations/messages.fr.yaml`](translations/messages.fr.yaml).

**UX Turbo — les frames.** La page des offres ([`offer/index.html.twig`](templates/offer/index.html.twig)) est en deux
colonnes : la liste à gauche (`<turbo-frame id="job-list">`), le détail à droite
(`<turbo-frame id="job-detail">`). Quand on clique sur une offre, **seul le cadre de droite**
est rechargé, et l'URL change quand même. Et si JavaScript est désactivé, le lien marche comme un
lien normal : c'est le « si ça ne marche pas avec Turbo, on continue comme avant » du cours.

**UX Turbo — les streams.** Deux exemples, faits comme dans le cours :

1. le bouton « enregistrer » d'une offre. Le contrôleur
   ([`ApplicationController::toggleSave`](src/Controller/ApplicationController.php)) regarde si Turbo attend un stream :

   ```php
   if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
       $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
       return $this->render('application/_save_button.stream.html.twig', [...]);
   }
   ```

   et le fichier [`_save_button.stream.html.twig`](templates/application/_save_button.stream.html.twig) contient un
   `<turbo-stream action="replace" target="save-offer-...">` qui remplace juste le bouton ;
2. la suppression d'une entreprise : [`delete.stream.html.twig`](templates/company/delete.stream.html.twig) fait un
   `action="remove"` qui enlève la ligne du tableau.

Sans Turbo, les deux font une redirection classique avec un message flash.

---

## Cours 7 + Activité 5 — Validation des données

**Les contraintes** (`Assert`) sont sur les DTO et les entités. Exemple dans
[`CompanyInput`](src/Dto/CompanyInput.php) :

```php
#[Assert\NotBlank(message: 'company.name.required')]
#[Assert\Length(max: 200)]
public string $name = '';
```

**`UniqueEntity`** (l'unicité vérifiée en base) :

- sur [`User`](src/Entity/User.php) : deux comptes ne peuvent pas avoir le même e-mail ;
- sur [`RegistrationInput`](src/Dto/RegistrationInput.php) (le DTO de l'inscription), avec `entityClass: User::class`.
  Sans ça, s'inscrire avec un e-mail déjà pris faisait planter la base au lieu d'afficher un
  message. C'est exactement le « joli message plutôt qu'une erreur Symfony » du cours.

**`empty_data`** : dans [`CompanyType`](src/Form/CompanyType.php), le champ `name` a `'empty_data' => ''`. Comme
l'explique le cours, sans ça un champ vidé envoie `null` à une propriété qui attend une chaîne, et
ça casse avant même la validation. Pareil dans [`OfferSearchType`](src/Form/OfferSearchType.php) pour le tri, la zone et
le statut (si l'URL ne les contient pas, on prend la valeur par défaut).

**Les messages d'erreur, et le bug qu'il y avait.** Au début, l'inscription affichait
`security.register.password_too_short` au lieu d'une vraie phrase. La raison : Symfony va
chercher les messages de validation dans le fichier **`validators`**, pas dans `messages`. Les
messages sont maintenant dans [`validators.fr.yaml`](translations/validators.fr.yaml), écrits court et simple
(« un peu court : au moins 12 caractères »). Un test vérifie que la clé brute n'apparaît plus.

**Le bonus : ma propre contrainte.** [`SafeUrl`](src/Validator/SafeUrl.php) + [`SafeUrlValidator`](src/Validator/SafeUrlValidator.php),
faits comme le `BlocSpam` du cours (deux fichiers : la contrainte et son validateur). Elle refuse
un lien si l'appli ne pourrait pas aller le lire sans danger : autre chose que http/https
(`file://`, `ftp://`), un mot de passe dans l'URL, ou une adresse privée / locale (`127.0.0.1`,
`localhost`, `192.168...`). On l'utilise comme les autres :

```php
#[Assert\Url(message: 'company.url.invalid')]
#[SafeUrl]
public ?string $website = null;
```

Pourquoi c'est important ici : l'appli va **elle-même** visiter les sites des entreprises
(pour vérifier la page carrières). Sans ce contrôle, quelqu'un pourrait lui faire visiter une
adresse interne du serveur. Ça s'appelle une attaque SSRF.

---

## Cours 8 + Activité 6 — make:crud

**Ce que le cours demandait.** Générer un CRUD complet avec `make:crud`, puis le reprendre :
afficher les relations par leur nom, rendre des champs optionnels, passer la suppression en
`DELETE`, ajouter les contraintes.

**Dans le projet** : le CRUD des entreprises ajoutées à la main.

| action | route | fichier |
|---|---|---|
| liste + recherche | `GET /fr/companies` | [`CompanyController::index`](src/Controller/CompanyController.php), [`index.html.twig`](templates/company/index.html.twig) |
| détail | `GET /fr/companies/{id}` | `show`, [`show.html.twig`](templates/company/show.html.twig) |
| ajout | `GET/POST /fr/companies/new` | `new`, [`new.html.twig`](templates/company/new.html.twig) |
| modification | `GET/POST /fr/companies/{id}/edit` | `edit`, [`edit.html.twig`](templates/company/edit.html.twig) |
| suppression | **`DELETE`** `/fr/companies/{id}` | `delete`, [`_delete_form.html.twig`](templates/company/_delete_form.html.twig) |

**Comment marche le `DELETE` (activité 6).** Un navigateur ne sait envoyer que GET et POST. On
envoie donc un POST avec un champ caché :

```twig
<input type="hidden" name="_method" value="DELETE">
<input type="hidden" name="_token" value="{{ csrf_token('delete' ~ company.id) }}">
```

et `http_method_override: true` dans [`framework.yaml`](config/packages/framework.yaml) dit à Symfony de lire ce
champ. La route n'accepte que `methods: ['DELETE']`. Avant d'envoyer, un petit contrôleur
Stimulus ([`confirm_controller.js`](assets/controllers/confirm_controller.js)) demande « supprimer ? ».

**Ce que j'ai repris après la génération** : le formulaire passe par le DTO, la logique est dans
le service, chaque action vérifie les droits avec un **voter** (voir cours 10), et les textes sont
traduits.

Une entreprise ajoutée à la main est **privée** : seul son auteur la voit. Il peut la « proposer
au catalogue », un admin la validera.

---

## Cours 9 + Activité 7 — API et retours JSON

**Ce que le cours demandait.** Faire des routes qui renvoient du JSON dans un dossier `API`, gérer
les groupes de sérialisation, recevoir du JSON, le valider, et faire toutes les routes : `find`,
`update`, une recherche, `delete`.

**Dans le projet** : [`src/Controller/Api/`](src/Controller/Api).

| route | méthode | réponse |
|---|---|---|
| `/api/companies` | GET | la liste (mêmes règles de visibilité que le site) |
| `/api/companies/{id}` | GET | une entreprise (`find`) |
| `/api/companies` | POST | crée → **201** + en-tête `Location` |
| `/api/companies/{id}` | PUT | modifie (`update`) |
| `/api/companies/{id}` | DELETE | supprime → **204** |
| `/api/offers?days=7` | GET | les offres récentes (la recherche, comme `findByDurationLessThan`) |
| `/api/offers/{id}` | GET | une offre |

**Les groupes.** Dans [`Company`](src/Entity/Company.php), les champs qu'on a le droit d'envoyer ont
`#[Groups(['company:read'])]`. Le contrôleur fait :

```php
return $this->json($company, context: ['groups' => ['company:read']]);
```

Résultat : les **notes privées et le propriétaire ne sortent jamais**, comme le mot de passe d'un
user dans l'exemple du cours. Le problème de boucle infinie du cours (film → classification →
films → ...) n'arrive pas ici, parce que l'entreprise ne contient pas la liste de ses offres.

**Recevoir du JSON** : la version « factorisée » du cours, avec `MapRequestPayload` :

```php
public function create(#[MapRequestPayload] CompanyInput $input, ...): JsonResponse
```

Symfony lit le JSON, le transforme en `CompanyInput`, le **valide** avec les mêmes contraintes que
le formulaire (`SafeUrl` comprise), et répond **422** avec les erreurs si ça ne va pas.

**Tester avec Postman** : `POST http://127.0.0.1:8000/api/login` avec le corps
`{"username": "user@test.fr", "password": "boardtheboat"}`, récupérer le `token`, puis l'envoyer
dans l'en-tête `Authorization: Bearer <token>` sur les autres routes (voir cours 15).

---

## Cours 10 + Activité 8 — Authentification et autorisations

**L'utilisateur.** [`User`](src/Entity/User.php) implémente `UserInterface` et
`PasswordAuthenticatedUserInterface`, ce que fait `make:user`. Les mots de passe sont hachés
(`password_hashers: auto`), jamais stockés en clair.

**La connexion.** Le cours utilise `make:auth`, qui génère une classe d'authentification. Ici
j'utilise directement le `form_login` fourni par Symfony (dans [`security.yaml`](config/packages/security.yaml)) : c'est
la même chose, sans classe à maintenir. En plus du cours :

- **trop d'essais ratés = blocage** pendant 15 minutes (`login_throttling`) ;
- « se souvenir de moi » (`remember_me`) ;
- un compte **non vérifié** ou **désactivé** ne peut pas se connecter
  ([`AccountStatusChecker`](src/Security/AccountStatusChecker.php)).

**L'inscription** (`make:registration-form` dans le cours) : [`RegistrationController`](src/Controller/RegistrationController.php),
avec **confirmation par e-mail** et **mot de passe oublié**
([`ResetPasswordController`](src/Controller/ResetPasswordController.php)). L'inscription peut être fermée avec la variable
`APP_REGISTRATION_OPEN=0`.

**`IsGranted`** au-dessus des contrôleurs, comme le préfère le prof :

```php
#[IsGranted('ROLE_USER')]
final class CompanyController extends AbstractController
```

et `#[IsGranted('ROLE_ADMIN')]` sur [`AdminDashboardController`](src/Controller/Admin/AdminDashboardController.php). Il y a aussi des règles
`access_control` dans `security.yaml` : c'est fait exprès en double (« ceinture et bretelles »).

**Dans Twig** : `is_granted('ROLE_ADMIN')` pour afficher le lien admin dans le menu
([`base.html.twig`](templates/base.html.twig)), et `is_granted('edit', company)` pour afficher le bouton modifier.

**Activité 8, point 4** (une page publique qui change selon qu'on est connecté) : la page
d'accueil [`home/index.html.twig`](templates/home/index.html.twig). Connecté, le bouton mène au tableau de bord ;
pas connecté, il mène à la connexion, et le lien « créer un compte » apparaît (`app.user`).

**Les voters — les droits VIEW / EDIT / DELETE du tableau du cours.** Le cours liste `OWNER`,
`VIEW`, `EDIT`, `DELETE`. C'est exactement ce que font les voters dans [`src/Security/Voter/`](src/Security/Voter) :

- [`OwnedEntityVoter`](src/Security/Voter/OwnedEntityVoter.php) contient la règle commune : « tu peux voir / modifier /
  supprimer seulement ce qui t'appartient » ;
- un voter par entité ([`CompanyVoter`](src/Security/Voter/CompanyVoter.php), [`ApplicationVoter`](src/Security/Voter/ApplicationVoter.php)...).
  Les entreprises du catalogue sont lisibles par tous mais modifiables seulement par un admin.

Dans le contrôleur : `$this->denyAccessUnlessGranted('edit', $company);`. Si quelqu'un essaie
d'ouvrir l'entreprise privée d'un autre en changeant l'URL, il prend une **403**. C'est ce qu'on
appelle une faille IDOR, et des tests vérifient qu'elle est bloquée.

**Sécurité en plus** (pas dans le cours) : [`SecurityHeadersSubscriber`](src/EventSubscriber/SecurityHeadersSubscriber.php) ajoute à
chaque page des en-têtes qui disent au navigateur quoi refuser (scripts étrangers, affichage du
site dans une iframe...). C'est pour ça qu'un CDN Bootstrap serait bloqué.

---

## Cours 11 — Envoyer des mails

**La config** : `MAILER_DSN` dans `.env` (une valeur bidon, `null://null`) et la vraie valeur
dans `.env.local`, qui n'est jamais commité. Le cours explique les DSN maildev, Gmail, etc. :
c'est la même ligne.

**Les mails envoyés** :

- confirmation d'adresse à l'inscription ([`EmailVerifier`](src/Service/Security/EmailVerifier.php), gabarit
  [`email/confirmation.html.twig`](templates/email/confirmation.html.twig)) ;
- lien de nouveau mot de passe ([`email/reset_password.html.twig`](templates/email/reset_password.html.twig)) ;
- alerte à l'admin si le pipeline de nuit plante ([`PipelineRunner`](src/Service/Pipeline/PipelineRunner.php), via le
  composant **Notifier**).

Chaque mail a une version HTML **et** une version texte (`.txt.twig`) : certains clients mail
n'affichent pas le HTML, et ça évite d'être classé spam.

**La ligne `messenger.yaml` du cours.** Le cours propose de **commenter**
`SendEmailMessage: async` pour que les mails partent tout de suite. Ici je l'ai **gardée** : les
mails sont mis en file d'attente et envoyés par le worker (`messenger:consume async`). Avantage :
la page ne met pas 3 secondes à répondre pendant que le serveur parle au serveur mail. Dans les
tests, les mails partent tout de suite (`sync`) pour qu'on puisse vérifier leur contenu.

**Et en prod, c'est l'inverse.** Chez Infomaniak il n'y a pas de worker permanent : il tourne
seulement quand le planificateur appelle `/_cron/tick`, toutes les 15 minutes
([`CronController`](src/Controller/CronController.php)). Un mail « mot de passe oublié » ne peut pas attendre
15 minutes, donc dans [`messenger.yaml`](config/packages/messenger.yaml) un bloc `when@prod` envoie les mails
en `sync`. C'est exactement la ligne du cours, mais seulement pour la prod.

---

## Cours 12 + Activité 9 — Fixtures

**Ce que l'activité demandait** : installer les fixtures et Faker, des fixtures pour chaque
entité avec une boucle, les relier avec `addReference` / `getReference` et
`DependentFixtureInterface`, et un fichier user avec **un compte en dur** + **10 faux comptes**.

**Dans le projet** : [`src/DataFixtures/`](src/DataFixtures).

| fichier | ce qu'il crée |
|---|---|
| [`UserFixtures`](src/DataFixtures/UserFixtures.php) | `admin@test.fr` et `user@test.fr` en dur, puis 10 comptes Faker (`fr_FR`) |
| [`CompanyFixtures`](src/DataFixtures/CompanyFixtures.php) | 15 entreprises, chacune enregistrée avec `addReference('company-0')`, `company-1`... |
| [`JobOfferFixtures`](src/DataFixtures/JobOfferFixtures.php) | 40 offres ; `getReference('company-X', Company::class)` pour les rattacher à une entreprise |
| [`ApplicationFixtures`](src/DataFixtures/ApplicationFixtures.php) | quelques candidatures du compte démo, dont une déjà « à relancer » |

`JobOfferFixtures` implémente `DependentFixtureInterface` et sa méthode `getDependencies()`
renvoie `[CompanyFixtures::class]` : les entreprises sont créées **avant** les offres.

Deux petites différences avec le cours :

- dans Symfony 8, `getReference()` demande aussi la **classe** de l'objet :
  `getReference('company-3', Company::class)` ;
- les fixtures ont des **groupes** : `--group=users` crée seulement les comptes, sans effacer les
  vraies offres déjà récupérées par les API (`doctrine:fixtures:load --group=users --append`).

Un test charge vraiment les fixtures et compte ce qui arrive en base
([`FixturesTest`](tests/Integration/FixturesTest.php)), exactement comme le `assertCount(10, $films)` du cours 14.

---

## Cours 13 — Mise en production

Tout est écrit étape par étape dans [`docs/DEPLOY.md`](docs/DEPLOY.md). En résumé, ce sont les commandes du
cours :

- `composer require symfony/apache-pack` → crée [`public/.htaccess`](public/.htaccess) (sans lui, seule la
  page d'accueil marche sous Apache) ;
- `composer install --no-dev --optimize-autoloader` (pas de Faker ni PHPUnit en prod) ;
- `composer dump-env prod` → `.env.local.php` ;
- `php bin/console asset-map:compile` ;
- `cache:clear` puis `cache:warmup`.

**Ce que je n'ai pas eu besoin de faire, et pourquoi :**

- **NelmioCors** : le cours l'installe parce que le front est sur un autre domaine que l'API.
  Ici le site et l'API sont sur **la même adresse**, donc pas de problème de CORS ;
- **le `ROUTE_PREFIX` / `base_path`** : le sous-domaine pointe directement sur `public/`, donc les
  liens et les assets marchent sans préfixe.

---

## Cours 14 — Tests

`composer test` lance tout (`php bin/phpunit` marche aussi). Il y a les trois niveaux du cours :

| type | exemple | ce qu'il vérifie |
|---|---|---|
| **unitaire** (`TestCase`) | [`RelevanceScorerTest`](tests/Unit/Service/RelevanceScorerTest.php) | le calcul du score, sans base de données |
| **unitaire** | [`CompanyTest`](tests/Unit/Entity/CompanyTest.php) | « SARL Dupont & Fils » et « dupont et fils » donnent bien la même clé (proche de l'exercice du slug) |
| **fonctionnel** (`KernelTestCase`) | [`FixturesTest`](tests/Integration/FixturesTest.php), [`PipelineRunnerTest`](tests/Integration/PipelineRunnerTest.php) | les services avec la vraie base de test |
| **E2E sans JS** (`WebTestCase`) | [`CompanyCrudTest`](tests/Functional/CompanyCrudTest.php), [`ApiTest`](tests/Functional/Api/ApiTest.php) | on clique, on remplit les formulaires, on lit les pages |

Comme dans le cours : une **base de test séparée** (`boardtheboat_test`), configurée dans
`.env.test.local`. Elle est remise à zéro automatiquement pendant les tests (Foundry `ResetDatabase`).

Les tests importants (ceux qu'un prof regarde) :

- un utilisateur **ne peut pas** voir, modifier ou supprimer les données d'un autre (IDOR) ;
- un formulaire sans jeton **CSRF** valide est refusé ;
- un lien vers une adresse interne est refusé (`SafeHttpFetcherTest`) ;
- 6 mauvais mots de passe d'affilée = blocage.

**Les tests ne touchent jamais internet** : en environnement de test, toute requête HTTP vers une
API reçoit une fausse réponse vide ([`OfflineHttpResponses`](tests/Support/OfflineHttpResponses.php)).

---

## Cours 15 — JWT

**Installation** : `composer require lexik/jwt-authentication-bundle`, comme dans le cours.

**Les clés** : `php bin/console lexik:jwt:generate-keypair`. Sur Windows, la commande plante avec
`error:80000003:system library::No such process` (le cours prévient). Plutôt que de passer par
openssl à la main, il suffit de dire à PHP où est son fichier de configuration openssl :

```
$env:OPENSSL_CONF="C:\wamp64\bin\php\php8.5.0\extras\ssl\openssl.cnf"
php bin/console lexik:jwt:generate-keypair
```

Les clés vont dans `config/jwt/` (ignoré par git, ce sont des secrets) et la phrase de passe est
dans `.env.local`.

**La config** dans [`security.yaml`](config/packages/security.yaml) : deux pare-feux, comme dans le cours.

- `api_login` : `POST /api/login` avec `{"username": ..., "password": ...}` → renvoie un `token` ;
- `api` : toutes les routes `/api` sont `stateless` et lisent `Authorization: Bearer <token>`.

Et `access_control` : `/api/login` en `PUBLIC_ACCESS`, le reste de `/api` en
`IS_AUTHENTICATED_FULLY`. La route est déclarée dans [`config/routes.yaml`](config/routes.yaml).

Durée de vie du jeton : 1 heure (`token_ttl: 3600`).

**Pourquoi pas de jeton CSRF sur l'API ?** Le CSRF existe parce que le navigateur envoie les
cookies tout seul. Avec un JWT, le jeton doit être mis **à la main** dans l'en-tête : un site pirate
ne peut pas le faire à notre place.

---

## Ce qui va au-delà du cours (en bref)

Pas besoin de tout expliquer, mais bon à savoir si le prof demande :

- **Messenger + Scheduler** : les tâches longues (récupérer les offres, vérifier les sites) se
  font en arrière-plan, et une tâche planifiée lance le pipeline chaque nuit à 3 h
  ([`Schedule.php`](src/Schedule.php), [`PipelineRunner`](src/Service/Pipeline/PipelineRunner.php)) ;
- **HttpClient** : les API gratuites (The Muse, Himalayas, Arbeitnow, France Travail, Recherche
  d'entreprises) dans [`src/Service/Ingestion/Source/`](src/Service/Ingestion/Source) ;
- **Traductions FR / EN** : tous les textes passent par `|trans` ([`translations/`](translations)) ;
- **le score de pertinence** : [`RelevanceScorer`](src/Service/Matching/RelevanceScorer.php) donne une note sur 100 avec le
  détail (mots-clés, secteur, ville, page carrières), et la raison est affichée ;
- **Stimulus** pour les petits comportements JS (navigation clavier, confirmation, son), sans
  framework JS et sans npm ;
- **`composer qa`** : vérifie le style du code, l'analyse statique (PHPStan niveau 8), les failles
  connues des dépendances (`composer audit`) et lance les tests. Il bloque un commit si quelque
  chose ne va pas ;
- **les ADR** dans [`docs/adr/`](docs/adr) : une page par choix technique important, avec le pourquoi.

---

## Questions probables du prof (et réponses courtes)

**« Pourquoi un DTO au lieu de l'entité dans le formulaire ? »**
Pour que le formulaire ne puisse remplir que les champs autorisés. Avec l'entité, on pourrait
envoyer un champ `owner` en plus et devenir propriétaire d'une entreprise.

**« À quoi sert le voter si la route a déjà `IsGranted('ROLE_USER')` ? »**
`IsGranted` vérifie qu'on est connecté. Le voter vérifie que **cet objet-là** nous appartient.
Deux utilisateurs connectés ne doivent pas voir les données l'un de l'autre.

**« Pourquoi des UUID ? »**
Un id 1, 2, 3 se devine. Un UUID non. (Et le voter reste la vraie protection.)

**« Pourquoi les messages d'erreur étaient des clés au début ? »**
Les messages de validation sont cherchés dans `validators.*.yaml`, pas dans `messages.*.yaml`. Je
les avais mis au mauvais endroit ; corrigé, avec un test.

**« Pourquoi tu n'as pas commenté la ligne des mails dans `messenger.yaml` ? »**
Pour que les mails partent en arrière-plan avec le worker, et que la page réponde vite.

**« Comment tu testes ton API ? »**
Postman : login sur `/api/login`, puis le token en `Authorization: Bearer`. Et des tests
automatiques dans [`ApiTest`](tests/Functional/Api/ApiTest.php) qui font la même chose.

**« Comment marche la suppression en DELETE depuis un formulaire HTML ? »**
Champ caché `_method=DELETE` + `http_method_override: true`. Le navigateur envoie un POST, Symfony
le traite comme un DELETE.

**« Comment tu évites l'injection SQL ? »**
Toujours `setParameter()` dans le QueryBuilder, jamais de variable collée dans la requête.

**« C'est quoi `empty_data` ? »**
La valeur que le formulaire donne quand le champ est vide. Sans ça, il donne `null` à une
propriété qui attend du texte, et ça plante avant la validation.

**« La Bonne Boîte est dans le cahier des charges, où est-elle ? »**
Son API v2 demande un compte francetravail.io pour lire sa documentation. Plutôt que de deviner les
noms des champs, j'ai utilisé la solution de repli prévue : le registre officiel des entreprises
(SIRENE, via l'API Recherche d'entreprises), qui marche sans clé. C'est noté dans
[`docs/adr/0007`](docs/adr/0007-pipeline-safe-fetching-and-la-bonne-boite.md).
