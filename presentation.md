# Présentation boardtheboat — fil conducteur (10 min)

Le principe : je raconte **le parcours d'un étudiant qui cherche un stage**, et à chaque étape de
la démo je montre quelle partie du cours fait marcher ce qu'on voit. Le prof voit l'appli tourner
*et* le cours derrière, sans que ça ressemble à une liste de chapitres.

Le détail de chaque cours, avec les liens vers les fichiers, est dans `explication.md`. Ce
document-ci sert pendant la présentation : un minutage, ce que je dis, ce que je montre, ce que je
réponds.

---

## Avant de commencer (5 min avant)

- [ ] WAMP lancé (MariaDB sur le port **3307**)
- [ ] `symfony server:start -d` → http://127.0.0.1:8000/fr répond
- [ ] onglet 1 : http://127.0.0.1:8000/fr (page d'accueil, **déconnecté**)
- [ ] onglet 2 : PhpStorm / IntelliJ ouvert sur `src/Controller/CompanyController.php`
- [ ] onglet 3 : un terminal PowerShell dans le dossier du projet, avec `$env:XDEBUG_MODE="off"`
- [ ] tests lancés **avant** (`composer test`, ~2-3 min) : je garde le résultat vert à l'écran
- [ ] compte de démo : `user@test.fr` / `boardtheboat` (admin : `admin@test.fr` / `boardtheboat`)
- [ ] le son de l'appli coupé (bouton en haut à droite) si la salle est calme

---

## Minutage

| temps | partie | cours montrés |
|---|---|---|
| 0:00 – 1:00 | le problème, l'idée | — |
| 1:00 – 2:00 | comment Symfony traite une page | 1, 2, 3 |
| 2:00 – 7:30 | **démo** (parcours d'un étudiant) | 1 à 12, 15 |
| 7:30 – 9:00 | deux morceaux de code | 3, 4, 5, 8, 10 |
| 9:00 – 9:40 | sécurité, tests, mise en prod | 7, 10, 13, 14 |
| 9:40 – 10:00 | conclusion et suite | — |

Si je prends du retard, je coupe d'abord l'étape **d** de la démo (suivre une candidature), puis
l'étape **f** (coulisses).

---

## 0:00 – 1:00 · Le problème

> « Chercher un stage, c'est surtout des **candidatures spontanées** : la plupart des stages ne
> sont jamais publiés. Le vrai travail, c'est de trouver *à qui* écrire, puis de ne pas oublier de
> relancer.
>
> boardtheboat fait ça en deux modes. Le **mode automatique** : chaque nuit, l'appli va chercher
> des offres et des entreprises sur des API publiques gratuites, enlève les doublons et les classe
> selon mon profil. Le **mode manuel** : j'ajoute moi-même les entreprises que je trouve, je suis
> mes candidatures, et l'appli me dit quand relancer.
>
> Techniquement, c'est le même schéma que le site de films du cours : des entités, des CRUD, une
> API, une authentification. Mais avec des entreprises et des offres à la place des films. »

---

## 1:00 – 2:00 · Ce qui se passe quand j'ouvre une page

Je montre ce schéma (au tableau, ou dans `explication.md`) :

```
navigateur ──► public/index.php ──► Kernel Symfony
                                        │
                     routing : quelle méthode répond à cette URL ? (#[Route])
                                        │
                     sécurité : connecté ? le bon rôle ? (security.yaml, IsGranted, voters)
                                        │
                     Controller ──► Service (logique) ──► Repository ──► Doctrine ──► MariaDB
                                        │
                     render() ──► Twig (base.html.twig + blocs) ──► HTML
```

> « Le contrôleur reste fin : il reçoit, vérifie, appelle un service, et affiche. La logique est
> dans `src/Service`, les requêtes dans `src/Repository`, l'affichage dans `templates`. C'est la
> séparation des responsabilités du début du cours, sauf qu'ici c'est Symfony qui range tout. »

---

## 2:00 – 7:30 · La démo

### a. Accueil public et connexion — 45 s · cours 1, 2, 10

**Je montre** : la page d'accueil déconnecté, puis je me connecte avec `user@test.fr`.

**Je dis** :
- « Les URL commencent par `/fr` ou `/en` : c'est un **paramètre de route**, `{_locale}`, comme le
  `{prenom}` du cours 1. Symfony s'en sert pour choisir la langue. »
- « Le bouton de l'accueil change selon que je suis connecté ou pas : en Twig, une condition sur
  `app.user` (activité 8). »
- « La connexion, c'est le `form_login` de Symfony : mot de passe haché, et 5 essais ratés =
  bloqué 15 minutes. »

### b. Tableau de bord et offres — 1 min 15 · cours 3, 4, 6

**Je montre** : le tableau de bord, puis **Offres**. Je tape `symfony` dans les mots-clés, je
choisis « Alternance », je clique sur deux offres, puis j'appuie sur `j` / `k` pour passer d'une
offre à l'autre.

**Je dis** :
- « Ces offres viennent de vraies API (The Muse, Himalayas, Arbeitnow) et les entreprises du
  registre officiel SIRENE. C'est le **composant HttpClient**. »
- « La recherche, c'est un **formulaire Symfony en GET** : les filtres sont dans l'URL, je peux la
  copier et la partager. »
- « Derrière, c'est une requête perso avec le **QueryBuilder** (cours 4) : un `andWhere` est
  ajouté seulement pour les filtres remplis. »
- « Quand je clique, seule la partie droite se recharge : c'est un **Turbo Frame** (cours 6).
  L'URL change quand même, et sans JavaScript le lien marche comme un lien normal. »

### c. Ajouter une entreprise — 1 min 30 · cours 5, 7, 8

**Je montre** : **Entreprises** → **Ajouter une entreprise**.

1. Je valide le formulaire **vide** → message « il faut au moins un nom ».
2. Je mets le nom `Test Démo` et le site `http://127.0.0.1/admin` → « lien refusé ».
3. Je corrige le site en `https://exemple.fr` → enregistré, message vert, je suis sur sa fiche.
4. **Modifier** → je change la ville → **Supprimer** → une confirmation s'affiche → la ligne disparaît.

**Je dis** :
- « Ce CRUD part de `make:crud` (cours 8), puis je l'ai repris : le contrôleur est fin et la
  logique est dans un service. »
- « Les messages viennent des **contraintes de validation** (cours 7). Le deuxième, c'est **ma
  propre contrainte**, `#[SafeUrl]`, faite comme le `BlocSpam` du cours : l'appli visite plus tard
  le site de l'entreprise, donc j'interdis les adresses internes. Ça bloque une attaque qui
  s'appelle SSRF. »
- « Après l'enregistrement : `addFlash` puis `redirectToRoute` (cours 6). »
- « La suppression est un vrai **DELETE** (activité 6) : le formulaire envoie un champ caché
  `_method=DELETE`, avec un jeton CSRF. »
- « Cette entreprise est **privée** : un autre utilisateur ne peut ni la voir, ni la modifier, même
  en tapant son adresse. C'est un **voter** (cours 10). »

### d. Suivre une candidature — 30 s · cours 6 (à couper si je suis en retard)

**Je montre** : une offre → **Enregistrer** (le bouton change sans recharger la page) →
**Candidatures** → je passe une carte en « Candidature envoyée ».

**Je dis** :
- « Le bouton, c'est un **Turbo Stream** : le serveur renvoie juste le nouveau bouton. »
- « Passer en "envoyée" programme la relance dans 7 jours. Elle remonte dans "À relancer". »

### e. L'API et le JWT — 1 min · cours 9, 15

**Je montre**, dans le terminal :

```powershell
$t = (Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8000/api/login `
      -ContentType 'application/json' `
      -Body '{"username":"user@test.fr","password":"boardtheboat"}').token
$t                                   # le jeton
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/offers?days=7" -Headers @{Authorization="Bearer $t"} | Select-Object -First 3
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/companies"                  # sans jeton → erreur 401
```

**Je dis** :
- « `/api/login` renvoie un **jeton JWT** (cours 15). Ensuite je l'envoie dans l'en-tête
  `Authorization: Bearer`. Sans jeton : 401. »
- « Les réponses passent par des **groupes de sérialisation** (cours 9) : les notes privées et le
  propriétaire ne sortent jamais. »
- « Pour créer, `MapRequestPayload` transforme le JSON en objet et le **valide** avec les mêmes
  règles que le formulaire. Données invalides : 422. »

### f. Les coulisses — 1 min · cours 12, 14, console

**Je montre** :

```powershell
php bin/console app:pipeline:run --skip-discover     # classe tous les profils, met des fiches en file d'attente
php bin/console debug:router | Select-String company  # toutes les routes du CRUD et de l'API
```

puis le résultat vert des tests lancés avant.

**Je dis** :
- « Le mode automatique, c'est **une commande** : elle classe les entreprises pour chaque profil,
  puis met les tâches longues en file d'attente avec **Messenger**. Chaque nuit à 3 h, le
  **Scheduler** la lance tout seul. »
- « Les données de test : des **fixtures avec Faker** (cours 12), reliées par `addReference` /
  `getReference`, avec des comptes en dur et 10 faux utilisateurs. »
- « Plus de 150 tests, aux trois niveaux du cours 14 : unitaires, Kernel et Web. Ils vérifient
  notamment qu'un utilisateur ne peut pas toucher aux données d'un autre. »

---

## 7:30 – 9:00 · Deux morceaux de code

### Le contrôleur du CRUD (`src/Controller/CompanyController.php`, méthode `new`)

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

> « C'est exactement le schéma du cours 5 : créer le formulaire, `handleRequest`, `isSubmitted`
> et `isValid`, enregistrer, `addFlash`, rediriger. Une différence : le formulaire remplit un
> **DTO** (`CompanyInput`) et pas l'entité, pour qu'on ne puisse pas envoyer en douce un champ
> `owner`. Le `persist` + `flush` est dans le service `CompanyManager`. »

### La requête perso (`src/Repository/JobOfferRepository.php`, méthode `findPublishedSince`)

```php
return $this->createQueryBuilder('o')
    ->addSelect('c')->innerJoin('o.company', 'c')
    ->andWhere('o.status = :open')
    ->andWhere('o.publishedAt >= :since')
    ->setParameter('open', OfferStatus::Open->value)
    ->setParameter('since', new \DateTimeImmutable(\sprintf('-%d days', max(0, $days))))
    ->orderBy('o.publishedAt', 'DESC')
    ->setMaxResults(min(max(1, $limit), 200))
    ->getQuery()->getResult();
```

> « C'est l'activité 4 : "les films plus récents que" devient "les offres de moins de N jours".
> Toujours `setParameter`, jamais de variable collée dans la requête : c'est la protection contre
> l'injection SQL. Le `addSelect` + `innerJoin` récupère l'entreprise dans la même requête, sinon
> Doctrine ferait une requête de plus par offre. »

*(Si le temps le permet, je montre `src/Security/Voter/OwnedEntityVoter.php` : la règle « tu ne
touches qu'à ce qui t'appartient ».)*

---

## 9:00 – 9:40 · Sécurité, tests, mise en prod

> « Côté sécurité :
> - **CSRF** sur tous les formulaires ;
> - **voters** sur chaque donnée qui appartient à quelqu'un ;
> - identifiants **UUID** dans les URL, impossibles à deviner ;
> - `SafeUrl` contre les adresses internes ;
> - limites de requêtes sur la connexion et l'inscription ;
> - en-têtes de sécurité sur chaque page ;
> - et **aucun secret dans git** : seul un `.env.dist` d'exemple est versionné, les vraies valeurs
>   restent dans `.env.local` sur chaque machine.
>
> Pour la prod (cours 13), tout est écrit dans `docs/DEPLOY.md` : `composer install --no-dev`,
> `dump-env prod`, `asset-map:compile`, les migrations sur la base vide, et le `.htaccess`
> d'apache-pack. Et sur un hébergement mutualisé, sans worker permanent, le planificateur
> appelle une URL protégée toutes les 15 minutes. »

---

## 9:40 – 10:00 · Conclusion

> « En résumé : tout ce qu'on a vu en cours est dans le projet, et sert à quelque chose de concret.
> Routes, Twig, Doctrine, QueryBuilder, formulaires, validation, CRUD, Turbo, API, authentification,
> mails, fixtures, tests, mise en prod et JWT.
>
> La suite : la fiche entreprise générée automatiquement, avec des accroches pour la lettre de
> motivation, et le mail de relance quotidien. »

---

## Plan B si la démo plante

| problème | réaction |
|---|---|
| la page ne charge pas | `symfony server:start -d`, puis rafraîchir |
| erreur de base de données | vérifier que WAMP tourne, MariaDB sur le port 3307 |
| pas d'offres | `php bin/console app:pipeline:run` (prend environ 30 s, nécessite internet) |
| le login de l'API échoue | montrer le test `tests/Functional/Api/ApiTest.php` qui fait la même chose |
| je suis en retard | couper l'étape **d** puis **f**, garder les deux extraits de code |

---

## Questions probables, et réponses courtes

### Symfony en général

**C'est quoi Symfony, et pourquoi un framework ?**
Un ensemble de composants PHP plus une structure de projet standard. On ne réécrit pas ce que
tout le monde réécrit (routing, formulaires, sécurité, accès base) et on range le code d'une façon
que tous les développeurs Symfony comprennent. Pour un petit site, c'est trop lourd : « pas
d'artillerie lourde pour une fléchette ».

**C'est quoi l'injection de dépendances ?**
Je demande ce dont j'ai besoin dans les paramètres d'une méthode ou d'un constructeur
(`CompanyRepository $companies`), et Symfony me le donne tout prêt, sans `new`. C'est le
**conteneur de services** qui construit les objets. L'**autowiring** devine quoi injecter grâce au
type.

**C'est quoi un service ?**
N'importe quelle classe que le conteneur sait construire, par exemple `CompanyManager` ou
`RelevanceScorer`. Une instance est partagée par requête.

**C'est quoi un bundle ?**
Un paquet qui s'intègre à Symfony avec sa configuration : par exemple `LexikJWTAuthenticationBundle`
pour le JWT, ou `DoctrineFixturesBundle` pour les fixtures.

**Où sont déclarées les routes ?**
En attributs `#[Route]` au-dessus des méthodes des contrôleurs. Une seule exception dans
`config/routes.yaml` : `/api/login`, que gère le pare-feu et non un contrôleur.
`debug:router` les liste toutes.

**Que fait `render()` ?**
Il passe les variables à Twig, Twig construit le HTML, et `render()` le renvoie dans une
`Response`. `json()` fait pareil en JSON, avec le Serializer.

**Les fichiers `.env` ?**
`.env.dist` est versionné, avec des valeurs d'exemple. `.env.local` contient les vraies valeurs de
chaque machine et n'est jamais commité. En prod, `composer dump-env prod` fige tout dans
`.env.local.php`, lu en premier.

### Doctrine

**ORM, ça veut dire quoi ?**
Object-Relational Mapping : je manipule des objets PHP, et Doctrine écrit le SQL. Je change une
propriété puis je `flush()`, et Doctrine fait l'`UPDATE`.

**`persist()` et `flush()`, quelle différence ?**
`persist()` dit à Doctrine « surveille ce nouvel objet » ; `flush()` envoie vraiment les requêtes
en base, toutes ensemble. Pour un objet déjà chargé depuis la base, un `flush()` suffit.

**Une migration, c'est quoi ?**
Un fichier PHP qui contient le SQL pour faire évoluer le schéma (`make:migration`, puis
`doctrine:migrations:migrate`). Doctrine note celles qui sont déjà passées, donc en prod je lance
la même commande sur une base vide et elle crée tout.

**Comment Symfony trouve l'entreprise à partir de l'URL ?**
Avec `#[MapEntity]` : il prend l'`{id}` de la route et fait le `find()` pour moi. Si elle n'existe
pas : 404.

**Pourquoi des UUID et pas 1, 2, 3 ?**
Un id qui se suit se devine (`/companies/42`, puis `/43`). Un UUID non. La vraie protection reste
le voter.

**Les relations ?**
`ManyToOne` : une offre appartient à une entreprise. `OneToOne` : un utilisateur a un profil. Avec
`onDelete: CASCADE`, supprimer un compte supprime ses données, et c'est la base qui s'en charge.

**QueryBuilder, DQL, SQL ?**
Le QueryBuilder construit du **DQL** (le langage de Doctrine, qui parle d'entités et de
propriétés). Doctrine le traduit en SQL pour MariaDB. Pour la recherche plein texte (`MATCH
AGAINST`), que Doctrine ne connaît pas, j'ai ajouté une petite fonction DQL.

### Twig, formulaires, validation

**Twig protège de quoi ?**
Il échappe tout par défaut : un `<script>` venu d'une API s'affiche comme du texte et ne
s'exécute pas. Je n'utilise jamais `|raw` sur des données extérieures.

**`path()` ou `url()` ?**
`path()` donne `/fr/companies`, c'est pour les liens dans le site. `url()` donne l'adresse
complète avec le domaine, pour les mails.

**Le jeton CSRF, ça protège de quoi ?**
Qu'un autre site fasse envoyer un formulaire par mon navigateur, où je suis connecté. Le jeton
caché prouve que le formulaire vient bien de ma page.

**Pourquoi `empty_data` ?**
Un champ vidé envoie `null`. Si la propriété attend du texte, ça plante avant même la validation.
`empty_data => ''` donne une chaîne vide, et c'est la contrainte `NotBlank` qui affiche un
message propre.

**Les messages d'erreur, où sont-ils ?**
Ceux des contraintes dans `translations/validators.fr.yaml`, les autres dans `messages.fr.yaml`.
Mettre un message de validation dans `messages` affiche la clé brute : c'est une erreur que j'ai
eue et corrigée.

**Comment marche ta contrainte perso ?**
Deux classes, comme `BlocSpam` : `SafeUrl` (l'attribut) et `SafeUrlValidator` (qui vérifie et
appelle `buildViolation()`). Je la pose avec `#[SafeUrl]` sur la propriété.

### Sécurité

**`IsGranted` ou voter ?**
`IsGranted('ROLE_USER')` : es-tu connecté, as-tu le rôle ? Le voter : **cet objet-là**
t'appartient-il ? Il faut les deux.

**Comment sont stockés les mots de passe ?**
Hachés avec `password_hashers: auto` (bcrypt ou argon2 selon le serveur). Même moi, je ne peux pas
les relire.

**Session ou JWT ?**
Le site utilise la **session** (cookie). L'**API** utilise le **JWT** : pas de session, le client
renvoie le jeton à chaque requête. Le jeton est signé, donc impossible à modifier, mais pas
chiffré : on n'y met rien de secret.

**Pourquoi pas de CSRF sur l'API ?**
Le CSRF existe parce que le navigateur envoie les cookies tout seul. Un JWT doit être mis à la
main dans l'en-tête : un site pirate ne peut pas le faire à ma place.

### API, Turbo, Messenger

**Turbo, c'est une SPA ?**
Non : le serveur renvoie toujours du HTML. Turbo remplace juste un morceau de page (frame) ou
applique une petite instruction (stream). Si JavaScript ne marche pas, les liens et les
formulaires restent normaux.

**Pourquoi les groupes de sérialisation ?**
Pour choisir les champs envoyés en JSON. Ça évite de divulguer des données (les notes, le
propriétaire) et les boucles infinies entre entités liées.

**C'est quoi Messenger ?**
Une file d'attente : au lieu de faire une tâche longue pendant la requête (appeler un site
extérieur, envoyer un mail), on envoie un message, et un **worker** le traite à part
(`messenger:consume`). La page répond tout de suite.

### Tests et prod

**Les trois types de tests ?**
Unitaire (`TestCase`) : une classe seule, sans base, par exemple le calcul du score.
`KernelTestCase` : les services avec la vraie base de test, par exemple le chargement des
fixtures. `WebTestCase` : on navigue comme un utilisateur, on remplit des formulaires, on vérifie
les pages et les codes HTTP.

**Les tests appellent-ils les vraies API ?**
Non. En test, toute requête HTTP reçoit une fausse réponse : les tests sont rapides et marchent
sans internet.

**Que faut-il faire pour mettre en prod ?**
Pointer le domaine sur `public/` ; `composer install --no-dev` ; créer `.env.local` puis
`dump-env prod` ; lancer les migrations ; `asset-map:compile` ; vider le cache ; créer le compte
admin. Tout est dans `docs/DEPLOY.md`.

### Sur le projet

**Pourquoi ce sujet ?**
Parce que je cherche moi-même un stage : je m'en sers vraiment.

**Ce qui manque ou ce que tu ferais ensuite ?**
La génération de la fiche entreprise (résumé, accroches pour la lettre), le mail de relance
quotidien, et La Bonne Boîte de France Travail, dont la documentation demande un compte pour
être lue. En attendant, j'utilise le registre officiel SIRENE, qui fonctionne sans clé.

**La difficulté principale ?**
Trois exemples concrets :
- le MariaDB de WAMP créait les tables en MyISAM, qui ignore les clés étrangères sans prévenir :
  j'ai forcé InnoDB ;
- les messages de validation s'affichaient en clé brute : ils étaient dans le mauvais fichier de
  traduction ;
- la génération des clés JWT plante sous Windows (l'erreur `error:80000003` du cours 15) :
  réglé avec la variable `OPENSSL_CONF`.

---

## Annexe · Où sont les routes ?

**Pourquoi `config/routes.yaml` est presque vide ?**
Parce que les routes ne sont pas écrites dans un fichier YAML, mais **sur les contrôleurs**, avec
l'attribut PHP `#[Route]`. Le YAML ne contient qu'une ligne qui dit à Symfony d'aller les lire :

```yaml
controllers:
    resource: routing.controllers   # = "lis les #[Route] de tous les contrôleurs de src/Controller/"
```

Les seules routes écrites en YAML sont celles qui n'ont **pas** de contrôleur à nous :
`/api/login` (géré directement par le pare-feu JWT), la déconnexion (`config/routes/security.yaml`),
et les outils de dev (profiler, pages d'erreur, dans `config/routes/`).

Pour voir **toutes** les routes, avec leur méthode HTTP et leur contrôleur :

```
php bin/console debug:router                    # la liste complète
php bin/console debug:router app_offer_save     # le détail d'une route
```

Avantage des attributs : la route est juste au-dessus du code qu'elle déclenche, on n'a pas deux
fichiers à garder synchronisés. C'est la façon recommandée par Symfony aujourd'hui.

## Annexe · Exemple complet : le clic sur « Enregistrer » une offre

### 1. Le clic

Sur la page **Offres**, dans le détail d'une offre à droite, je clique sur le bouton
**« Enregistrer »**. Ce bouton est un petit formulaire, dans
`templates/application/_save_button.html.twig` :

```twig
<form method="post"
      action="{{ path('app_offer_save', {id: offer.id}) }}"
      class="inline-form"
      id="save-offer-{{ offer.id }}">
    <input type="hidden" name="_token" value="{{ csrf_token('save-offer') }}">
    <button type="submit" class="button" aria-pressed="{{ saved ? 'true' : 'false' }}" ...>
        {{ (saved ? 'application.unsave_action' : 'application.save_action')|trans }}
    </button>
</form>
```

- `path('app_offer_save', {id: offer.id})` : Twig fabrique l'URL **à partir du nom de la route**,
  jamais en dur. Résultat : `/fr/offers/0192f3a1-…/save`.
- `csrf_token('save-offer')` : un jeton caché, pour prouver que la requête vient bien de notre page.
- `method="post"` : enregistrer modifie la base, donc POST, jamais GET.

Turbo intercepte l'envoi du formulaire : au lieu de recharger la page, il l'envoie en arrière-plan
et annonce qu'il accepte une réponse « Turbo Stream ».

### 2. La route empruntée

```
POST /fr/offers/{id}/save      nom : app_offer_save
```

Elle est déclarée par deux attributs dans `src/Controller/ApplicationController.php` :

```php
#[Route('/{_locale}', requirements: ['_locale' => 'fr|en'], defaults: ['_locale' => 'fr'])]  // sur la classe : préfixe /fr ou /en
#[IsGranted('ROLE_USER')]                                                                     // toute la classe : connecté obligatoire
final class ApplicationController extends AbstractController
{
    #[Route('/offers/{id}/save', name: 'app_offer_save', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function toggleSave(...)
```

Le routeur colle les deux : `/{_locale}` + `/offers/{id}/save`. Les `requirements` filtrent :
`_locale` vaut seulement `fr` ou `en`, `id` doit ressembler à un UUID, et seule la méthode POST est
acceptée (un GET sur cette URL donne une erreur 405).

### 3. Le contrôleur appelé, et ce qu'il appelle

`ApplicationController::toggleSave()`. Avant même d'entrer dans la méthode, Symfony a déjà :

1. **vérifié la connexion** (`#[IsGranted('ROLE_USER')]`) : sinon, redirection vers `/fr/login` ;
2. **chargé l'offre depuis la base** grâce à `#[MapEntity]` : il prend l'`id` de l'URL, fait la
   requête Doctrine et passe l'objet `JobOffer` tout prêt. Si l'id n'existe pas : 404.

Puis la méthode appelle, dans l'ordre :

| étape | ce qui est appelé | rôle |
|---|---|---|
| 1 | `isCsrfTokenValid('save-offer', …)` | rejette la requête si le jeton CSRF est faux |
| 2 | `ApplicationRepository::findOneForTarget()` | cherche si **cet utilisateur** a déjà enregistré **cette offre** |
| 3a | `new Application(...)` + `EntityManager::persist()` | pas encore enregistrée → on crée une candidature au statut « saved » |
| 3b | `denyAccessUnlessGranted(DELETE)` → `ApplicationVoter` | déjà enregistrée → le voter vérifie qu'elle m'appartient, puis `remove()` |
| 4 | `EntityManager::flush()` | Doctrine écrit réellement en base (INSERT ou DELETE) |
| 5 | `render('application/_save_button.stream.html.twig')` | réponse Turbo : remplace **seulement** le bouton |
| 5 bis | `addFlash()` + `redirect()` | si JavaScript est coupé : message flash et retour à la page précédente |

### 4. Le code du contrôleur, commenté

```php
#[Route('/offers/{id}/save', name: 'app_offer_save', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
public function toggleSave(Request $request, #[MapEntity(mapping: ['id' => 'id'])] JobOffer $offer): Response
{
    // L'utilisateur connecté (getUser() + vérification que c'est bien notre classe User).
    $user = $this->currentUser();

    // Sécurité : sans jeton CSRF valide, on arrête tout (403).
    $this->assertCsrf($request, 'save-offer');

    // Requête Doctrine : findOneBy(['owner' => $user, 'company' => ..., 'jobOffer' => $offer]).
    // Le filtre sur owner garantit qu'on ne tombe jamais sur la candidature de quelqu'un d'autre.
    $application = $this->applications->findOneForTarget($user, $offer->getCompany(), $offer);

    if (null === $application) {
        // Pas encore enregistrée : on crée la candidature (statut "saved" par défaut).
        $application = new Application($user, $offer->getCompany(), $offer);
        $application->setTargetRole($offer->getTitle());
        $this->entityManager->persist($application);   // "à insérer" (rien n'est encore écrit)
        $saved = true;
    } else {
        // Déjà enregistrée : le bouton sert à l'enlever.
        // Le voter vérifie que la ligne est bien à moi avant de la supprimer.
        $this->denyAccessUnlessGranted(OwnedEntityVoter::DELETE, $application);
        $this->entityManager->remove($application);    // "à supprimer"
        $saved = false;
    }

    // Une seule écriture en base, dans une transaction.
    $this->entityManager->flush();

    // Cas normal (Turbo actif) : on renvoie un Turbo Stream qui remplace juste le bouton
    // "Enregistrer" par "Retirer" (ou l'inverse). Pas de rechargement de page.
    if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return $this->render('application/_save_button.stream.html.twig', [
            'offer' => $offer,
            'saved' => $saved,
        ]);
    }

    // Sans JavaScript : message flash traduit, puis retour sur la page d'où on vient.
    $this->addFlash('success', $saved ? 'application.saved' : 'application.unsaved');

    return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_offers'));
}
```

Et la réponse Turbo Stream (`templates/application/_save_button.stream.html.twig`) :

```twig
<turbo-stream action="replace" target="save-offer-{{ offer.id }}">
    <template>
        {% include 'application/_save_button.html.twig' with {offer: offer, saved: saved} only %}
    </template>
</turbo-stream>
```

Turbo cherche dans la page l'élément `id="save-offer-…"` (le formulaire du bouton) et le remplace
par la nouvelle version : le bouton affiche maintenant « Retirer ». On réutilise le **même**
template que l'affichage normal, donc pas de HTML en double.

**À retenir pour l'oral :** clic → formulaire POST avec jeton CSRF → route `app_offer_save`
(déclarée par attribut) → `IsGranted` + `MapEntity` → contrôleur → repository (requête limitée à
l'utilisateur) → voter si suppression → `flush()` → Turbo Stream qui remplace le bouton.
Le contrôleur ne fait que coordonner : la requête est dans le repository, le droit d'accès dans le
voter, le HTML dans Twig.
