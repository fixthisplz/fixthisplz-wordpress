# FixThisPlz — plugin WordPress de signalement de bugs

Date : 2026-11-19 (mis à jour au fil des versions) · Statut : document de conception

Les renvois « spec x.y » présents dans le code désignent les sections de ce document.

## 1. Intention

Un plugin WordPress **générique** de signalement de bugs, utilisable sur n'importe quel site. Il est écrit
**nativement pour WordPress** : PHP + hooks, table SQL, API REST WordPress, Settings API, écrans d'admin
natifs, JavaScript sans framework ni build.

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Un visiteur (connecté ou non, même parcours) désigne un élément de la page ou « Toute la page »,
  décrit le problème, joint éventuellement une capture, **valide** : le signalement est enregistré dans
  le back-office (après anti-robot), puis il peut **télécharger** ce même signalement (`.md`/`.json`/`.txt`
  + capture `.png`/`.jpg`).
- Un menu admin **« Bugs »** : mini-gestion de bugs (statuts, priorité), vue **Liste** et vue **Kanban**,
  fiche détaillée, e-mail à chaque nouveau bug.
- Un écran de réglages riche (visibilité, apparence du bouton, fenêtre, anti-robot, notifications,
  protection, contexte, RGPD, gestion).

**Non-objectifs**
- Pas de système de support : aucune réponse au déclarant, aucun fil de discussion, aucun SLA, aucun
  e-mail au déclarant.
- Pas de modes `ticket`/`download`/`both` ni de « publics » distincts : l'envoi est toujours fait,
  le téléchargement est toujours proposé après.
- Pas d'enregistrement vidéo, pas de rejeu de session, pas de capture des corps de requêtes, pas de
  dédoublonnage automatique.

**Anti-objectifs (échec même si « ça marche »)**
- Le moindre lien avec un site, un client ou un thème particulier (rôles, préfixes, constructeurs de pages
  propres à un site) : le plugin doit fonctionner sur n'importe quel WordPress.
- Une **valeur saisie** dans un champ (ou un mot de passe, même sa longueur) qui part dans le **texte** d'un
  signalement (contexte technique). *(Capture : voir §5.2 — depuis le 2026-11-20, la capture
  est prise volontairement par le visiteur, qui la voit avant envoi ; elle n'est masquée que si le réglage
  « Masquer les données sensibles » est activé.)*
- Du JavaScript du signalement chargé sur une page où le bouton n'est pas visible.
- Une dépendance externe chargée depuis un CDN (tout est embarqué), une étape de build obligatoire.
- Des captures accessibles publiquement par URL.

## 3. Contraintes

- WordPress ≥ 6.5, PHP ≥ 8.1. Testé avec WordPress 7.1, PHP 8.5 et MySQL 8. Compatible multisite non
  requis (activation par site suffit).
- Slug `fixthisplz`, dossier `wp-content/plugins/fixthisplz/`, text domain `fixthisplz`, préfixe PHP
  `FixThisPlz\` (namespace) / `fixthisplz_` (options, hooks, table).
- Traduisible : `.pot` fourni, `fr_FR` et `en_US` (source en anglais, traduction française livrée).
- Aucun style ni JS en dehors des pages concernées. Admin : classes et composants natifs (`WP_List_Table`,
  `postbox`, Dashicons, `wp-color-picker`, `jquery-ui-sortable`, `wp.media`).
- Le plugin ne doit **pas** être en conflit avec un cache de page (WP Rocket, etc.) : le HTML du bouton
  peut être servi en cache, donc rien de sensible ni de propre à l'utilisateur n'y est rendu côté
  serveur, en dehors de ce que la visibilité impose (voir §6.1).

## 4. Approches envisagées

| Approche | Pour | Contre | Choix |
|---|---|---|---|
| **A. JS vanilla, sans build, chargement par étapes** (`import()` natif de modules ES) | zéro outillage, lisible, « WordPress », poids minimal | un peu plus de code à la main | **retenue** |
| B. React/TypeScript compilé (`@wordpress/scripts`) | écosystème React familier | étape de build, bundle plus lourd, maintenance plus lourde | écartée |
| C. Porter tel quel un composant React existant | réutilisation | dépend d'un écosystème Next/React, pas natif | écartée |

Stockage : **table dédiée** plutôt qu'un CPT ; captures dans un **dossier privé**
`wp-content/uploads/fixthisplz/` (protégé), pas dans la Médiathèque (publique).

## 5. Parcours visiteur

1. **Bouton flottant** (apparence et position réglables, §7.2).
2. **Inspection** : barre d'instructions focalisée annoncée en `aria-live="polite"`
   avec « Toute la page » et « Annuler » ; **Échap** annule. Souris : survol surligné, clic sélectionne
   (le clic n'atteint pas la page). Tactile : 1er toucher surligne, 2e toucher sur le même élément (ou
   « Sélectionner ») sélectionne. Clavier : Tab parcourt les éléments focalisables, Entrée sélectionne,
   ↑ remonte au parent, le nom de l'élément surligné est annoncé. Surlignage contraste ≥ 3:1, sans
   décaler le contenu ; focus rendu au bouton à la fermeture.
3. **Fenêtre** (dialogue modal accessible) : élément désigné (résumé + « Changer d'élément »),
   **description** obligatoire (≤ 5 000 car.), e-mail de contact facultatif (seulement non connecté, si
   activé), case **« Joindre une capture d'écran »** décochée par défaut ⇒ capture puis **aperçu** avant
   envoi (retirer / refaire), **mention d'information RGPD**, panneau dépliable **« Contexte technique
   joint »** montrant exactement ce qui part, widget anti-robot, bouton **« Valider »**.
4. **Envoi** : `POST` REST multipart. Succès ⇒ écran de confirmation « Signalement n° #42 envoyé » avec
   boutons **Télécharger** `.md` / `.json` / `.txt` (formats réglables) et **Télécharger la capture** si
   cochée, puis « Terminer ». Le fichier téléchargé est construit **dans le navigateur** à partir du même
   objet que celui envoyé (+ numéro renvoyé par le serveur). Nom : `signalement-AAAAMMJJ-HHmm.{ext}`.
5. **Échec** d'envoi (réseau, anti-robot, limite) : message clair, la saisie est conservée, et le
   téléchargement reste proposé (pour ne pas perdre le signalement).

### 5.1 Contexte capturé (liste fermée)

| Champ | Contenu | Borne |
|---|---|---|
| `url` | sans fragment ; valeurs de requête remplacées par `…` (noms gardés) | 2 048 |
| `title` | titre du document | 255 |
| `locale`, `timezone`, `user_agent`, `viewport` | navigateur | 16, 64, 512, 32 |
| `plugin_version`, `wp_page` | version du plugin ; type de page WP (id/post_type si exposés par le loader) | 32 / 64 |
| `element` | `null` = page entière ; sinon `selector` (512 — `id` non généré / `data-*` stables d'abord, puis chemin `tag:nth-of-type` ≤ 5 niveaux), `tag` (32), `role` (64), `name` accessible (200), `text` visible tronqué (300), `rect {x,y,width,height}` entiers | — |
| `console` | ≤ 20 dernières entrées `{level: error|warn, message (500), at}`, erreurs non capturées et rejets de promesses compris (réglable) | 20 |
| `network` | ≤ 20 dernières requêtes **en échec** (`fetch` et `XMLHttpRequest`) `{method, url (512, même nettoyage), status 0–599, at}` (réglable) | 20 |
| `capture` | *(ajout 2026-11-20)* seulement si une capture est jointe : `{method: tab\|dom, masked: bool}` — vraie capture ou reconstruction, masquée ou non | — |

Côté serveur, s'y ajoutent : utilisateur WP (ID) si connecté, `ip_hash`, date.

### 5.2 Minimisation et masquage (non négociable)

- Jamais la valeur d'un `input`/`textarea`/`select`/`[contenteditable]` : seulement type, nom, libellé.
  `type=password` : rien, même pas la longueur.
- Élément marqué `data-ftp-mask`, ou correspondant à un **sélecteur sensible réglé** (§7.7), ou
  situé dessous ⇒ texte `[masked]` (et occulté dans la capture si le masquage est activé, voir ci-dessous).
- Jamais cookie, `localStorage`, `sessionStorage`, en-tête, jeton, corps de requête, DOM complet.
- **Double caviardage** : navigateur (pour que le panneau montre ce qui part) **et** serveur
  (`Redactor`, qui ne fait pas confiance au client) : URL nettoyées, e-mails ⇒ `[email]`, jetons
  (`Bearer …`, JWT, suites ≥ 32 caractères alphanumériques) ⇒ `[token]`, longueurs re-bornées.
- **Capture** *(révisée le 2026-11-20 : « la capture ne doit introduire aucun faux bug »)* :
  - **Ordinateur — vraie capture** (API navigateur de partage d'écran, `getDisplayMedia`, onglet courant
    proposé en priorité quand le navigateur le permet) : image **identique aux pixels affichés**. Le
    navigateur demande l'accord du visiteur ; refus ⇒ case décochée, message clair, envoi sans capture
    possible (aucun repli silencieux). *(Simplifié le 2026-11-20 : le visiteur choisit ce
    qu'il partage — onglet, fenêtre ou écran — et le voit dans l'aperçu ; aucune vérification de surface.)*
    UI du plugin masquée le temps de la prise. Le partage est **toujours arrêté** aussitôt l'image prise
    (y compris fenêtre fermée, case décochée, délai dépassé). Si le site interdit le partage d'écran
    (`Permissions-Policy: display-capture`), on utilise le repli.
    *Limite acceptée* : cette interdiction n'est détectable que dans Chromium ; ailleurs, une capture
    bloquée affiche « capture annulée » : le visiteur peut envoyer sans capture, ou l'administrateur peut
    choisir « Reconstruction ».
  - **Masquage — optionnel** (réglage « Masquer les champs saisis et les zones sensibles dans la capture »,
    **désactivé par défaut**) : s'il est activé, champs de saisie, `[contenteditable]` et zones sensibles
    sont recouverts d'un rectangle plein (vraie capture, au mieux) ou remplacés par des puces / occultés
    dans le clone (repli). La page n'est jamais modifiée.
    *Limite acceptée (pas de vérification CropTarget)* : masquage activé et visiteur qui
    partage un autre onglet ⇒ les rectangles sont dessinés aux positions des champs de cette page.
  - **Mobile / navigateur sans cette API — repli** : `modern-screenshot` (MIT) embarqué dans
    `assets/vendor/`, chargé seulement au cochage ; dans le **clone** : UI du plugin exclue ; **polices
    embarquées** et éléments fixes correctement placés, pour rester aussi fidèle que possible (fidélité
    non garantie au pixel).
  - Commun : JPEG, qualité dégressive jusqu'à ≤ 5 Mo ; aperçu avant envoi (Annoter / Refaire / Retirer).
  - *(ajout 2026-11-20)* **Annotation** : le bouton « Annoter » de l'aperçu ouvre un éditeur
    plein écran (UI du plugin, jamais capturée ; module chargé au premier clic seulement) qui affiche la
    capture ajustée à l'espace disponible (ordinateur et mobile). Outils : **Rectangle** (contour rouge, outil
    par défaut), **Flèche** (rouge, avec pointe), **Masquer** (rectangle plein, même couleur que le masquage
    automatique) ; tracé à la souris, au doigt ou au stylet, tracés minuscules ignorés. *(ajout 2026-11-20)*
    4ᵉ outil **Sélectionner** : un clic ou un toucher sur une forme la sélectionne (la plus
    haute d'abord ; zones de saisie élargies pour le doigt : trait de la flèche, contour du rectangle, surface
    de la zone masquée, poignées), un clic dans le vide désélectionne ; la forme sélectionnée a un contour
    pointillé et des poignées (4 coins pour rectangle et zone masquée, 2 extrémités pour la flèche), affichés
    dans l'éditeur seulement, jamais dans l'image envoyée. Glisser la forme la déplace (sans sortir de
    l'image) ; glisser une poignée la redimensionne (le coin opposé reste fixe, on peut le dépasser) ou déplace
    l'extrémité de la flèche (réorientation). « Supprimer » (désactivé sans sélection) ou les touches
    Suppr / Retour arrière, focus dans l'éditeur, retirent la forme sélectionnée. Une forme qui vient d'être
    tracée est aussitôt sélectionnée (outil Sélectionner) pour être ajustée. « Annuler le dernier » annule la
    dernière action (ajout, déplacement ou redimensionnement — un glissé = une action —, suppression,
    « Tout effacer ») ; les formes d'une session précédente s'annulent une à une comme si elles venaient d'être
    tracées. « Annuler » (Échap : abandonne les changements de la session, même avec une forme sélectionnée) et
    « Appliquer ». Appliquer dessine l'image **originale** + les formes à sa résolution native (épaisseurs
    proportionnelles à l'image), encode en JPEG ≤ 5 Mo (sinon message « trop volumineuse », état précédent
    conservé) ; le résultat remplace l'image prévisualisée, envoyée et téléchargée. L'original et la liste
    des formes sont gardés : rouvrir « Annoter » montre les formes existantes, annulables (jamais incrustées
    deux fois) ; plus aucune forme ⇒ l'original est renvoyé tel quel. Refaire / Retirer / décocher / fermer
    la fenêtre abandonnent l'original, les formes et l'image annotée. Le tracé est réservé au pointeur
    (l'annotation est facultative) ; tous les boutons fonctionnent au clavier, le focus reste dans l'éditeur
    puis revient sur « Annoter ». Aucun réglage, aucun changement serveur (le serveur reçoit un JPEG comme
    avant ; « Masquer » est donc définitif dans l'image envoyée, le ré-encodage serveur n'y change rien).
    *(ajout 2026-11-22)* Outils, dans cet ordre : Rectangle, Flèche, **Crayon**, **Texte**,
    Masquer, Sélectionner, puis une **palette de 6 couleurs** (rouge par défaut, orange, jaune foncé, vert,
    bleu, noir : boutons à `aria-pressed`, nom accessible traduit, pastille visible), puis Supprimer /
    Annuler le dernier / Tout effacer, et en bas Annuler / Appliquer (la barre passe à la ligne sur mobile).
    La couleur courante s'applique aux nouveaux rectangles, flèches, traits et textes ; cliquer une couleur
    avec une forme sélectionnée la recolore (une action annulable). Une zone masquée garde toujours le gris
    du masquage automatique (la palette ne la change pas). Les formes d'une session sans couleur restent
    rouges. **Crayon** : trait libre lissé (points trop proches ignorés, courbes passant par les milieux),
    même épaisseur que les autres formes ; saisi par son trait, déplaçable, supprimable, redimensionnable
    par les 4 coins de son cadre (mise à l'échelle depuis le coin opposé, retourné en miroir s'il est
    dépassé) ; un trait minuscule est ignoré. **Texte** : un clic sur l'image ouvre un champ de saisie
    posé à cet endroit, à la taille affichée (200 caractères au plus) ; Entrée ou un clic ailleurs valide,
    Échap n'abandonne que ce texte (l'éditeur reste ouvert), un texte vide n'ajoute rien. Rendu dans la
    couleur choisie, police sans empattement du système, sur un fond blanc semi-transparent ; taille par
    défaut proportionnelle à l'image. Il se sélectionne (toute sa surface), se déplace et se supprime comme
    une forme ; ses poignées d'angle changent la taille de police (le coin opposé reste fixe) ; un double
    clic ou double toucher avec Sélectionner rouvre le champ pour le modifier (une action annulable ; vidé,
    il est retiré). Un geste qui ne change rien (clic, retour au point de départ) n'ajoute aucune action
    à annuler ; Suppr / Retour arrière ne retirent pas de forme pendant la saisie d'un texte.
    *(ajout 2026-11-22)* **Taille** : à côté de la palette, 3 boutons Petite / Moyenne (par
    défaut, les tailles d'avant) / Grande (`aria-pressed`, noms accessibles « Petite taille », « Taille
    moyenne », « Grande taille », pastilles de taille croissante). Comme la couleur : la taille courante
    s'applique aux nouvelles formes, et cliquer une taille avec une forme sélectionnée la lui applique (une
    action annulable ; rien si elle ne change rien). Rectangle, flèche et crayon : épaisseur du trait
    (×0,6 / ×1 / ×1,8 de l'épaisseur proportionnelle à l'image ; la pointe de flèche suit) ; texte : taille de
    police (×0,6 / ×1 / ×1,8 de la taille par défaut, au moins 8 px ; cadre remesuré, gardé dans l'image et
    réduit s'il ne peut y tenir ; les poignées d'angle restent libres, cliquer une taille remet la valeur du
    préréglage) ; une zone masquée n'a pas de taille. Les formes d'une session sans taille restent moyennes.
  Serveur : type vérifié dans le contenu (PNG/JPEG seulement), **ré-encodage** via
  `wp_get_image_editor()` (supprime les métadonnées), stockage en dossier privé.

## 6. Serveur

### 6.1 Visibilité et chargement

- Le loader n'est **enfilé** (`wp_enqueue_scripts`, et `admin_enqueue_scripts` si l'option admin est
  active) que si : plugin activé ET page non exclue ET (visiteur non connecté coché, ou un des rôles de
  l'utilisateur coché).
- « Masquer sur mobile » : pas de détection serveur (compatibilité cache). Le loader commence par un
  `matchMedia` ; sur mobile masqué, il **s'arrête aussitôt** : aucun tampon installé, aucun bouton, aucun
  autre fichier chargé. *(Arbitrage : l'anti-objectif « pas de JS du signalement là où le bouton
  n'est pas visible » prime ; le seul coût est le loader lui-même, quelques Ko.)*
- Le loader (≈ quelques Ko) installe les tampons console/réseau et le bouton ; le reste
  (`reporter.js` : inspecteur, fenêtre, export) arrive par `import()` au premier clic ; la bibliothèque de
  capture au cochage ; le script de l'anti-robot à l'ouverture de la fenêtre.
- Versions des modules : `reporter.js`, `captcha.js` et la bibliothèque de capture sont chargés avec
  `?ver=`, mais le navigateur perd ce paramètre en résolvant leurs imports relatifs (`./dialog.js`) : après
  une mise à jour, il pourrait mélanger d'anciens fichiers `lib/` en cache avec les nouveaux. Chaque
  `assets/js/lib/*.js` est donc enregistré comme script module dont l'identifiant est son URL sans version
  (`wp_register_script_module`, version `FIXTHISPLZ_VERSION`), et le loader comme `admin-captcha-test.js` (test anti-spam des réglages) les
  déclarent en `module_dependencies` : WordPress (7.0+) imprime alors son unique import map, qui remplace
  chaque URL sans version par l'URL `?ver=`, pour les imports statiques comme dynamiques, sans aucun
  préchargement (les modules restent chargés au clic). Avant 7.0, l'argument est ignoré sans erreur :
  comportement inchangé.
- Config passée par `wp_add_inline_script` (JSON) : endpoint REST, réglages d'apparence et de fenêtre,
  fournisseur + clé **publique** d'anti-robot, libellés traduits. Jamais de clé secrète.

### 6.2 API REST — namespace `fixthisplz/v1`

| Méthode | Route | Qui | Rôle |
|---|---|---|---|
| POST | `/reports` | public | créer un signalement (multipart : `description`, `context` JSON, `reporter_email?`, `website` pot de miel, `captcha_token`, `screenshot?`) |
| PATCH | `/reports/{id}` | cap `fixthisplz_manage` + nonce REST | statut, priorité (Kanban, fiche) |
| GET | `/reports/{id}/screenshot` | cap `fixthisplz_manage` | servir la capture du dossier privé |

`POST /reports`, dans l'ordre : pot de miel rempli ⇒ **réponse succès identique, rien écrit** ;
limite par IP (transients sur `ip_hash`, défaut 5/heure et 20/jour) ⇒ 429 ; anti-robot vérifié côté
serveur ⇒ 403 (échec) / 503 (fournisseur injoignable : **fail-closed**) ; validation et caviardage
(§5.2) ⇒ 422 ; enregistrement ; e-mail ; réponse 201 `{ id, number }`.

**Rattachement à l'utilisateur** : si la requête porte un nonce REST valide (`X-WP-Nonce`) et un cookie
de session, l'utilisateur WP est enregistré. Un nonce **absent ou périmé** (page servie depuis un cache
de plus de 24 h) ne fait **pas** échouer l'envoi : le signalement est enregistré sans utilisateur. La
protection de l'envoi repose sur l'anti-robot, le pot de miel et la limite, pas sur le nonce.

`ip_hash` = HMAC-SHA256 de l'IP avec la clé propre au plugin ; l'IP en clair n'est jamais stockée. L'IP
retenue est `REMOTE_ADDR` ; un réglage avancé permet de faire confiance à un en-tête de proxy
(`X-Forwarded-For` / `CF-Connecting-IP`).

### 6.3 Anti-robot

Interface `Captcha_Provider` (`render_config()`, `verify($token, $ip)`), implémentations :
`None`, `Turnstile`, `Recaptcha_V2`, `Recaptcha_V3` (seuil réglable, défaut 0,5, action `bug_report`),
`Hcaptcha`. Vérification par `wp_remote_post` vers l'endpoint officiel du fournisseur. Bouton « Tester la
configuration » dans les réglages (vérifie que les clés répondent).

### 6.4 Données

Table `{$wpdb->prefix}fixthisplz_reports` (créée par `dbDelta` à l'activation, version de schéma en option
`fixthisplz_db_version`, migrations au chargement si version différente) :

| Colonne | Type |
|---|---|
| `id` | bigint unsigned PK auto-incrément — sert de **numéro** affiché `#id` |
| `status` | varchar(20) — `new`, `seen`, `confirmed`, `in_progress`, `fixed`, `wont_fix`, `duplicate` |
| `priority` | varchar(20) — clé d’une priorité des réglages (§7.11) ; par défaut `low`, `normal` (défaut), `high`, `critical` *(ajout 2026-11-22 : varchar(10) en version de schéma 1, élargie par `dbDelta` en version 2 sans toucher aux lignes)* |
| `description` | text |
| `page_url` | varchar(2048) (copie de `context.url` pour filtrer/afficher) |
| `context` | longtext JSON (§5.1, caviardé) |
| `user_id` | bigint unsigned null |
| `reporter_email` | text null, **chiffré** (libsodium `secretbox`, clé propre au plugin, voir ci-dessous) |
| `reporter_email_hash` | char(64) null — HMAC de l'e-mail normalisé, pour que l'exporteur/effaceur RGPD retrouve les lignes |
| `screenshot` | varchar(255) null — nom de fichier dans le dossier privé |
| `ip_hash` | char(64) null |
| `created_at`, `updated_at`, `seen_at`, `status_changed_at` | datetime (UTC) |
| `assigned_to` | bigint unsigned null, indexé — ID de la personne à qui le signalement est attribué *(ajout 2026-11-22, version de schéma 3)* |

Index : `status`, `created_at`, `user_id`.

**Table `{$wpdb->prefix}fixthisplz_activity`** *(ajout 2026-11-22, version de schéma 3, créée par le même
`dbDelta` ; la version n'est enregistrée qu'une fois la colonne `assigned_to` et la table présentes)* : notes
internes et historique d'un signalement. Colonnes `id`, `report_id` (indexé), `user_id` (indexé ; `0` = le
plugin lui-même, « Système », `NULL` = auteur effacé, « Utilisateur supprimé »), `type` (`note` ou `change`),
`field` (`status`, `priority` ou `assigned_to`), `old_value`, `new_value` (clé, ou ID d'utilisateur, `NULL` =
non attribué), `content` (texte de la note), `created_at` (UTC). Supprimer un signalement (fiche, actions
groupées, effaceur RGPD, rétention) supprime son activité.

Dossier privé `uploads/fixthisplz/` : nom de fichier aléatoire, `index.php` vide + `.htaccess`
`Require all denied` ; servi uniquement par la route REST protégée. (Sur nginx, le `.htaccess` est
inopérant : les noms aléatoires non listables limitent le risque ; documenté dans le readme.)

Options : une seule option tableau `fixthisplz_settings` (sanitisée par schéma), `fixthisplz_db_version`,
`fixthisplz_secret` (autoload non).

**Clé propre au plugin** : générée une fois à l'activation (`random_bytes(32)`,
option `fixthisplz_secret`) ; une constante `FIXTHISPLZ_SECRET` dans `wp-config.php` la remplace si elle est
définie. Elle sert au chiffrement de l'e-mail et aux HMAC (`ip_hash`, `reporter_email_hash`). Elle ne
dépend **pas** des salts WordPress : une rotation des salts (fréquente avec les plugins de sécurité) ne
rend donc rien illisible.

Capacité `fixthisplz_manage` ajoutée aux rôles cochés dans « Gestion » (administrateur par défaut), et
recalculée à chaque enregistrement des réglages. Elle donne accès aux bugs (liste, Kanban, fiche,
statuts, captures, export CSV). **L'écran Réglages exige `manage_options`** : un
gestionnaire de bugs ne voit ni les clés secrètes d'anti-robot ni le choix des rôles, et ne peut pas
s'auto-attribuer des droits.

## 7. Réglages (menu Bugs › Réglages, onglets, Settings API)

*(Refonte 2026-11-23)* Les blocs ci-dessous gardent leur contenu et leurs clés d'option
(aucune migration), mais l'écran les range désormais en **7 onglets**, par intention, chacun découpé en
sections (un `<h2>` court, une phrase d'explication, puis son `form-table`) :
**Général** (`general` : Activation, Qui peut signaler, Où afficher le bouton ; blocs 1 sauf le mobile),
**Bouton** (`button` : Apparence, Couleurs, Position, Sur mobile — avec « Masquer le bouton sur mobile », venu
du bloc 1 —, Réglages avancés : ombre, arrondi, z-index ; bloc 2), **Formulaire** (`form` : Textes, Capture
d'écran, Champs du formulaire, Copie téléchargeable, Mention d'information (RGPD) ; bloc 3), **Suivi**
(`tracking`, trois sous-vues `subsubsub` avec chacune son formulaire, `&view=` : Équipe et menu (`team`, bloc 9),
Statuts (`statuses`, bloc 10), Priorités (`priorities`, bloc 11)), **Alertes** (`alerts` : E-mail, Webhooks ;
blocs 5 et 12), **Anti-spam** (`spam` : Vérification anti-spam, Limites d'envoi, Réglages avancés (en-tête de
proxy) ; blocs 4 et 6) et **Confidentialité** (`privacy` : Données techniques jointes, Zones à masquer,
Conservation, Désinstallation ; blocs 7 et 8). La case « Masquer les champs saisis et les zones sensibles »
reste dans Formulaire › Capture d'écran (elle dépend de « autoriser la capture », et un champ conditionnel reste
dans le formulaire du champ qui le commande) ; des renvois en texte relient les deux onglets. Une liste
(`visibility.roles`, `management.roles`, `dialog.formats`, `webhooks`, statuts, priorités) reste toujours
entière dans un même formulaire. Les anciens `?tab=` restent valables : `visibility` → `general`, `dialog` →
`form`, `captcha` et `protection` → `spam`, `notifications` et `integrations` → `alerts`, `context` et `data` →
`privacy`, `management`, `statuses` et `priorities` → `tracking` avec la sous-vue correspondante ; après
l'enregistrement, on revient à l'adresse canonique de l'onglet. Le drapeau « Clos » des statuts s'appelle
désormais « Traité » (EN « Done »), et le « déclarant » est l'« auteur » du signalement.

1. **Visibilité** — activer ; rôles qui voient le bouton (cases) + « Visiteurs non connectés » ;
   pages exclues (sélection de pages + préfixes d'URL) ; masquer sur mobile ; afficher aussi dans l'admin WP.
2. **Apparence du bouton** — icône (liste de Dashicons ou SVG de la médiathèque via `wp.media`) ; forme
   (rond, carré arrondi, pilule avec texte) ; texte de la pilule ; infobulle ; taille (S/M/L) ; couleurs
   fond / icône-texte / fond au survol (`wp-color-picker`) ; **ombre** (aucune, légère, moyenne, forte,
   ou personnalisée : décalage X/Y, flou, étalement, couleur avec opacité) ; **arrondi** (préréglé par la
   forme, ou personnalisé en px) ; position (bas droite, bas gauche, haut droite, haut gauche, milieu
   droite, milieu gauche, **personnalisée** : bord de référence + distances X/Y en px ou %) ; position
   différente sur mobile (mêmes choix) ; `z-index`. Aperçu en direct du bouton dans l'onglet.
3. **Fenêtre** — titre ; consigne ; texte de la mention RGPD + lien (page de confidentialité WP par
   défaut) ; autoriser la capture ; **méthode de capture** *(ajout du 2026-11-20)* :
   « Vraie capture de l'onglet » (défaut — image exacte, accord du visiteur demandé par le navigateur) ou
   « Reconstruction de la page » (sans demande d'accord, fidélité non garantie) ; mention affichée sous le
   choix : *sur mobile (et navigateurs sans partage d'écran), la reconstruction est toujours utilisée* ;
   **« Masquer les champs saisis et les zones sensibles dans la capture »** (case, désactivée par défaut,
   s'applique aux deux méthodes) ; champ e-mail de contact pour non connectés ; formats de téléchargement
   proposés.
4. **Anti-robot** — aucun / Turnstile / reCAPTCHA v2 / reCAPTCHA v3 (+ seuil) / hCaptcha ; clé du site,
   clé secrète ; bouton « Tester ».
5. **Notifications** — activer l'e-mail ; destinataires (plusieurs, séparés par virgule ; défaut
   `admin_email`) ; joindre la capture.
6. **Protection** — limite par heure et par jour par IP ; en-tête de proxy de confiance (avancé). Pot de
   miel toujours actif.
7. **Contexte collecté** — erreurs console oui/non ; requêtes en échec oui/non ; sélecteurs CSS
   sensibles supplémentaires.
8. **Données et RGPD** — suppression automatique des bugs `fixed`/`wont_fix`/`duplicate` après X mois
   (0 = jamais ; cron quotidien, supprime aussi la capture ; *depuis le 2026-11-20 : les statuts cochés « Traité » dans Suivi › Statuts*) ; supprimer toutes les données à la
   désinstallation (défaut : non).
9. **Gestion** — rôles qui peuvent gérer les bugs (cap `fixthisplz_manage`) ; pastille du nombre de « Non
   vus » sur le menu.
   *(Ajout du 2026-11-20)* **Nom du menu** (défaut vide ⇒ « Bugs » traduit), **nom du
   sous-menu de la liste** (défaut vide ⇒ « Tous les bugs » traduit) — ils s'appliquent au menu, au
   sous-menu et aux titres des pages de gestion, jamais aux textes visiteurs ; **position dans le menu
   admin** (liste de positions nommées : après le Tableau de bord, après Articles, après Médias, après
   Pages, après Commentaires (défaut, 26), après Apparence, après Extensions, après Utilisateurs, après
   Outils, après Réglages).
   *(Ajout 2026-11-22)* **Nom d’un signalement** (au singulier, 40 caractères au plus, défaut vide ⇒ « Bug »
   traduit) : il sert aux titres « {Nom} n° 12 » de la fiche, du widget du tableau de bord et de l’e-mail
   (objet « [Site] Nouveau signalement : {Nom} n° 12 » et corps).
10. **Statuts** *(ajout du 2026-11-20)* — liste ordonnée éditable : ajouter, renommer,
   réordonner, couleur, case « Traité », supprimer. L'ordre est celui du Kanban (une colonne par statut) et
   de tous les sélecteurs. Règles :
   - Chaque statut a une **clé** technique (lettres minuscules, chiffres, `_`, ≤ 20 caractères, unique),
     créée à l'ajout à partir du nom et **jamais modifiée** ensuite ; seuls le nom, la couleur, « Traité » et
     l'ordre changent.
   - `new` (statut de chaque signalement reçu, compté par la pastille) et `seen` (posé automatiquement à
     l'ouverture d'une fiche `new`) sont **obligatoires** : renommables et coloriables, jamais supprimés,
     jamais « Traité ».
   - Les statuts « Traité » sont ceux que purge la rétention (Confidentialité › Conservation) ; par défaut `fixed`,
     `wont_fix`, `duplicate`.
   - Nom vide ⇒ nom par défaut traduit (pour les 7 statuts d'origine) ; un nom saisi s'affiche tel quel
     dans toutes les langues.
   - **Supprimer un statut encore utilisé** : l'admin choisit vers quel statut restant déplacer ses
     signalements (défaut `new`) ; aucun signalement ne garde un statut inexistant. Un statut inconnu en
     base (ex. option modifiée à la main) s'affiche avec sa clé et reste modifiable.
   - Au plus 20 statuts ; couleur `#rrggbb` validée côté serveur. Les priorités sont réglables depuis le 2026-11-22 (voir 11).
11. **Priorités** *(ajout 2026-11-22)* — même éditeur que les statuts (code partagé :
   `Choice_List`, `List_Editor`, `Settings_List_Tab`) : ordre, nom, couleur, ajout, suppression, sans case
   « Traité ». L’ordre va de la plus basse à la plus haute et c’est lui que suit le tri par priorité
   (`ORDER BY FIELD(priority, …)` construit avec un marqueur préparé par clé). Règles :
   - Par défaut les 4 priorités d’origine : Basse `#787c82`, Normale `#2271b1`, Haute `#b26200`, Critique
     `#d63638` (les couleurs des cartes Kanban d’avant).
   - `normal` (priorité de chaque signalement reçu) est **obligatoire** : renommable et coloriable, jamais
     supprimée ; elle reçoit les signalements d’une priorité supprimée quand la cible choisie n’est pas valide.
   - Clés générées côté serveur à partir du nom et jamais modifiées ; au plus 10 priorités.
   - La liste, son filtre, le tri, le Kanban, la fiche, l’export CSV, les actions groupées et l’API REST
     suivent les réglages. Une priorité inconnue en base s’affiche avec sa clé et reste conservée tant qu’on
     ne la change pas. Rien ne change côté visiteur.
   - Les pastilles de statut et de priorité prennent la couleur réglée (teinte claire dans la liste et la
     fiche, couleur pleine sur les cartes Kanban) : plus de classes CSS de priorité figées.

12. **Intégrations** *(ajout 2026-11-22)* — jusqu’à 5 webhooks (`Webhooks::MAX`), présentés en 5 blocs fixes
   (les enregistrés d’abord, puis des blocs vides ; un bloc dont l’adresse est vide n’est pas enregistré, vider
   l’adresse supprime le webhook). Chacun : **adresse** (https obligatoire et `wp_http_validate_url` — hôte
   public, port 80/443/8080 —, sauf `localhost`, `127.0.0.1`, `*.localhost` et `*.test`, acceptés en http et sans
   contrôle d’adresse **seulement** quand le type d’environnement WordPress est `local` ou `development` (le filtre
   `fixthisplz_allow_dev_webhooks` peut forcer l’un ou l’autre ; ailleurs, ni les messages ni l’onglet ne les
   mentionnent) ; une adresse refusée n’est pas enregistrée et un avis le dit ; une
   adresse déjà enregistrée n’est pas revérifiée quand on enregistre un autre onglet ; un avis n’est affiché qu’une
   fois même si WordPress assainit deux fois une option nouvelle), **format** (JSON générique
   signé, Slack, Discord, Microsoft Teams), **événements** cochés (nouveau signalement, changement de statut,
   attribution — retirer l’attribution n’en est pas un), **actif** oui/non, et pour un webhook enregistré un bouton
   « Envoyer un test » (REST `POST fixthisplz/v1/webhook-test`, `manage_options` + nonce REST, appel bloquant de
   5 s vers l’adresse enregistrée, même inactive, qui affiche le code HTTP reçu ou l’erreur).
   - **Événements** : un seul endroit par événement. `fixthisplz_report_created` (id) est déclenché par
     `Report_Submission` après l’enregistrement (Mailer et les webhooks l’écoutent) ; `fixthisplz_report_changed`
     (id, changements, auteur) par `Reports_Repository::update()` (fiche, Kanban, actions groupées) et
     `mark_seen()` (passage automatique `new` ⇒ `seen`), une fois par modification réelle, jamais pour une
     modification sans effet ; les déplacements « Système » (statut ou priorité supprimé, compte supprimé) n’en
     déclenchent pas. Le passage `new` ⇒ `seen` (automatique à l’ouverture ou manuel) n’envoie **aucun** webhook,
     mais une attribution faite dans la même modification part quand même.
   - **Actions groupées** (`Webhook_Sender::batch()`, qui enveloppe `Mailer::batch_assignments()` dans
     `Bugs_Page::apply_bulk()`) : le JSON générique reçoit toujours un événement signé par signalement (contrat
     machine) ; chaque webhook Slack, Discord ou Teams reçoit **un seul** message par événement : le message
     habituel si un seul signalement a vraiment changé, sinon un récapitulatif (« 12 signalements : statut →
     Corrigé », « 3 signalements attribués à X ») avec un lien vers la liste. Pendant l’action, **aucun** appel
     ne part : tous les événements sont mis de côté et envoyés une fois toutes les écritures en base, l’historique
     et les e-mails d’attribution terminés, de sorte qu’un destinataire lent ne peut jamais interrompre l’action
     au milieu.
   - **Contenu** (le même pour tous les formats, `Webhook_Payload`) : événement (`report.created`,
     `report.status_changed`, `report.assigned`, `test`), nom et adresse du site, horodatage ISO 8601 UTC, le
     changement en texte (« Statut : Non vu → Corrigé ») et le signalement : numéro, titre « {Nom} n° 12 »,
     extrait de la description (300 caractères), page, statut et priorité (clé + libellé), nom affiché de la
     personne attribuée, lien vers la fiche. **Jamais** l’e-mail du déclarant, l’empreinte d’IP, la capture ni le
     contexte technique. Slack : `{text, blocks}` (une section mrkdwn, `& < >` échappés) ; Discord :
     `{content, embeds}` avec `allowed_mentions` vide (aucune mention ne notifie) ; pour Discord et Teams, les
     caractères Markdown `\ * _ [ ] ( ) < > `` ` `` des textes et des valeurs sont précédés d’une barre oblique inverse ; Teams : carte adaptative 1.4
     enveloppée dans `{type: "message", attachments}`, le format des webhooks « Workflows » de Teams qui
     remplacent les connecteurs Office 365 retirés.
   - **Signature** (JSON générique) : en-têtes `X-FixThisPlz-Event` et `X-FixThisPlz-Signature: sha256=` +
     HMAC-SHA256 hexadécimal du corps brut avec le secret du webhook. Le secret (32 octets aléatoires en
     hexadécimal) est créé à l’ajout, stocké chiffré avec `Secret`, affiché en clair aux administrateurs dans
     l’onglet (seul `manage_options` y accède) avec une case « Créer un nouveau secret à l’enregistrement » ; le
     formulaire renvoie le texte chiffré dans un champ caché, gardé seulement s’il se déchiffre.
   - **Envoi** (`Webhook_Sender`) : `wp_remote_post` non bloquant, délai 2 s, sans redirection, sans file
     d’attente ni nouvelle tentative, juste après l’écriture en base ; `reject_unsafe_urls` sauf pour les hôtes
     de développement admis. Chaque envoi est protégé (`try`/`catch \Throwable`) : un webhook en échec ne casse ni
     la requête d’administration ni les autres webhooks. **Latence** : même non bloquant, chaque appel ouvre la
     connexion (DNS, TCP, TLS) dans la requête en cours ; un destinataire lent ou injoignable peut donc ajouter
     jusqu’à environ 2 s **par webhook** concerné à la requête d’administration (au plus 5 webhooks). Dans une
     action groupée, le JSON générique coûte un appel par signalement modifié (après la sauvegarde, voir
     ci-dessus).

Valeurs par défaut : désactivé, visible seulement par les administrateurs, bas droite, rond, anti-robot
« aucun » (avec avertissement visible tant que le bouton est ouvert aux non connectés sans anti-robot).

## 8. Back-office « Bugs »

Menu de premier niveau **« Bugs »** (Dashicon `dashicons-buddicons-replies` ou similaire, pastille
« Non vus »), sous-menus : **Tous les bugs** (onglets Liste | Kanban), **Réglages**.

- **Liste** (`WP_List_Table`) : colonnes n°, extrait de description, page, déclarant (lien vers le profil
  WP ou « Visiteur » + e-mail déchiffré si présent), priorité, statut, date ; filtres statut (vues en
  haut avec compteurs) et période (du/au), priorité ; recherche texte (description, URL) ; tri par date
  ↑/↓ (défaut : plus récent) et par priorité ; actions groupées : changer statut, changer priorité,
  supprimer ; **export CSV** des résultats filtrés.
- **Suivi interne** *(ajout 2026-11-22)* :
  - **Attribution** : on peut attribuer un signalement à une personne qui a la capacité `fixthisplz_manage`
    (seule source : `Team`). Menu « Attribué à » sur la fiche (« — Non attribué — » ; une personne qui a
    perdu la capacité reste affichée tant qu'on ne change pas), colonne « Attribué à » dans la liste,
    colonne « Attribué à » (nom affiché, vide sinon) dans l'export CSV,
    initiales (nom complet en infobulle) sur la carte Kanban, filtre par personne ou « Non attribué » et
    bouton « Mes signalements » (liste et Kanban), actions groupées « Attribuer à : {nom} » (une par
    personne) et « Retirer l'attribution ». Chaque nouvelle attribution déclenche l'action
    `fixthisplz_report_assigned` (id, personne, auteur) ; `Mailer` y envoie à la personne choisie un e-mail (HTML
    + texte, même contenu que l'e-mail de nouveau bug, sans pièce jointe, titre « [site] Vous est attribué :
    {Nom} n° 12 »), jamais à l'auteur de l'attribution. Une action groupée envoie **un seul** e-mail par
    personne : le même si un seul signalement change vraiment, sinon un récapitulatif (titres et liens).
    L'e-mail est rédigé dans la langue de la personne attribuée (celle de son profil, sinon celle du site).
  - **Notes internes** : boîte « Notes et historique » de la fiche ; texte brut enregistré tel que saisi
    (espaces de bord retirés, UTF-8 invalide retiré, 5 000 caractères au plus), échappé à l'affichage avec les
    retours à la ligne ; ajout par toute personne qui a `fixthisplz_manage`
    (nonce) ; suppression par son auteur ou par un administrateur (`manage_options`) ; jamais montrées au
    déclarant.
  - **Historique** : chaque changement de statut, de priorité ou d'attribution est enregistré avec son
    auteur, sa date, l'ancienne et la nouvelle valeur, en **un seul endroit** (`Reports_Repository`, la
    validation et le calcul des différences dans `Report_Changes`) : fiche, Kanban (REST), actions groupées,
    et le passage automatique `new` ⇒ `seen` à l'ouverture d'une fiche (au nom de la personne qui l'ouvre) ;
    la réaffectation d'un statut ou d'une priorité supprimé et le retrait d'attribution d'un compte supprimé
    sont enregistrés comme changements « Système ». Une modification qui ne change rien n'écrit rien (ni
    `updated_at`, ni historique) et ne compte pas dans « N signalements mis à jour ». Fil commun avec les notes, plus récent en
    premier ; libellés des réglages, clé inconnue affichée telle quelle ; un historique n'est pas supprimable.
  - **Suppression d'un compte** (`deleted_user`, et `remove_user_from_blog` en multisite) : ses signalements ne sont plus attribués ; ses notes
    restent, signées « Utilisateur supprimé ».
  - **RGPD** : exporteur « Notes et historique de FixThisPlz » (notes et changements signés par le compte
    WP de l'e-mail demandé, par pages de 50) ; effaceur associé qui retire l'auteur (`user_id` NULL) de ces
    mêmes entrées, sans supprimer leur texte. La désinstallation (si demandée) supprime aussi la table d'activité.
- **Kanban** : une colonne par statut, cartes (n°, extrait, page, priorité en couleur, date, vignette si
  capture), glisser-déposer `jquery-ui-sortable` ⇒ `PATCH` REST, retour arrière visuel si échec. Mêmes
  filtres (période, priorité, recherche) que la liste. Colonnes à volume important : 50 cartes chargées
  puis « Afficher plus ».
- **Fiche** : description, déclarant, page (lien), élément désigné, navigateur, erreurs console,
  requêtes en échec, capture (agrandissable), JSON brut dépliable, sélecteurs statut et priorité,
  suppression. Ouvrir une fiche `new` la passe automatiquement en `seen`.
- **E-mail** de nouveau bug (`wp_mail`, HTML simple + texte) : n°, extrait, page, déclarant, lien vers la
  fiche, capture jointe si réglé.
- **Widget du tableau de bord** *(ajout 2026-11-22)* (`wp_add_dashboard_widget`, seulement pour la capacité
  `fixthisplz_manage`, titré du nom du menu) : nombre de signalements par statut non clos (lien vers la liste
  filtrée), les 5 derniers signalements (« {Nom} n° 12 », statut, extrait, lien vers la fiche) et un lien
  vers la liste (nom du sous-menu).
- **Confidentialité** : exporteur et effaceur de données personnelles WordPress (par e-mail : comptes
  WP correspondants + `reporter_email` déchiffré) ; texte suggéré pour la politique de confidentialité
  (`wp_add_privacy_policy_content`).
- `uninstall.php` : supprime table, options, capacités, dossier privé **seulement** si le réglage le dit.

## 9. Tests attendus

- PHPUnit (sans chargement de WordPress complet là où c'est possible) : `Redactor` (URL, e-mails,
  jetons, bornes), validation du contexte (clés inconnues ignorées, bornes ⇒ 422), limiteur, chiffrement
  e-mail aller-retour, providers anti-robot (réponses HTTP simulées).
- Tests d'intégration REST sur un WordPress de test (WP-CLI / requêtes) : pot de miel ⇒ succès sans
  écriture ; anti-robot refusé ⇒ 403 ; limite ⇒ 429 ; capture non image ⇒ 422 ; utilisateur rattaché avec
  nonce valide, non rattaché sans ; capture inaccessible sans la capacité.
- JS : tests unitaires des fonctions pures (caviardage, sélecteur, export md/json/txt, masquage du
  clone) avec `node --test` (aucune dépendance) ; vérification navigateur réelle (Chrome) du parcours
  souris, clavier, tactile simulé, capture, téléchargement, Kanban.

## 10. Laissé au constructeur

Découpage exact des classes PHP et des modules JS, styles visuels de la fenêtre (sobres, thème-agnostiques,
variables CSS), texte exact des libellés, choix de l'icône du menu, détail du rendu de l'e-mail,
structure du readme. Le plugin vit dans son propre dépôt (racine du dépôt = racine du plugin).
