# CLAUDE.md — FixThisPlz (plugin WordPress)

Ce dépôt est développé avec l'aide d'un assistant IA (Claude Code). Ce fichier donne à l'assistant le
contexte et les règles du projet ; il est public pour que chacun puisse voir comment le projet est construit.

## Ce qu'est ce projet

Un plugin WordPress **générique** et open source de signalement de bugs : un visiteur désigne un élément de
la page, décrit le problème, joint éventuellement une capture annotée ; les signalements sont suivis dans un
menu « Bugs » (liste, Kanban, fiche). Écrit **nativement pour WordPress** (PHP + hooks, table dédiée, API REST
WP, Settings API, admin natif, JavaScript sans build ni CDN). Il fait partie du projet FixThisPlz :
https://github.com/fixthisplz/fixthisplz

- **Spec (fait foi)** : `docs/specs/fixthisplz-design.md`. Les commentaires « spec x.y » du code renvoient à
  ses sections. En cas de doute, la spec prime ; un vrai désaccord avec elle ⇒ on demande au mainteneur.
- Racine du dépôt = racine du plugin. Tout ce qui n'est pas livré (`docs/`, `tests/`, fichiers du dépôt) est
  exclu de l'archive par `.gitattributes` (`export-ignore`) : vérifier avec `git archive HEAD`.

## Règles

1. **Générique** : aucun lien avec un site, un client, un thème ou un constructeur de pages particulier. Le
   plugin doit tourner sur n'importe quel WordPress (≥ 6.5, PHP ≥ 8.1).
2. **Standards WordPress** (WPCS) ; code simple, **zéro code mort** (supprimer ce qui devient inutile, pas de
   sur-protection des actions volontaires) ; fichiers d'environ 300 lignes au plus, découpés par responsabilité.
3. **Sécurité** : capacité vérifiée et nonce sur chaque action d'administration, assainir tôt, échapper tard
   (chaque sortie), SQL toujours préparé (`$wpdb->prepare`), aucune donnée saisie dans le texte d'un
   signalement, aucune clé secrète envoyée au navigateur.
4. **Langues** : code, commentaires et chaînes source en anglais (text domain `fixthisplz`), traduction
   `fr_FR` fournie et tenue à jour (`.pot`, `.po`, `.mo`). Conversation avec le mainteneur en français.
5. **Commits uniquement sur demande explicite du mainteneur** ; messages Conventional Commits en anglais.
6. Aucun déploiement ni accès à un serveur distant depuis une session de développement.

## Méthode

- **Catalogue d'abord** : avant d'écrire du code, lire `CATALOGUE.md` puis faire un grep ; réutiliser ce qui existe.
  Tout élément réutilisable ajouté (helper, constante, hook, option, route, export JS, token CSS, helper de test)
  entre au catalogue **dans le même commit**.
- Une tâche à la fois, exécutée par des sous-agents, avec une **relecture indépendante** avant la suivante,
  puis une relecture d'ensemble à la fin.
- Chaque tâche : test qui échoue → code → test qui passe ; **tous les tests passent avant un commit**.
- Chaque fonctionnalité visible est **vérifiée dans un vrai navigateur** sur un WordPress de test, pas
  seulement par les tests.
- Vérifier soi-même avant de demander ; ne poser au mainteneur que des questions **produit**.
- Nettoyer en fin de tâche les signalements de test créés.

## Tests

Sur n'importe quel WordPress local de test (jamais un site de production), plugin dans
`wp-content/plugins/fixthisplz/` :

```bash
wp eval-file wp-content/plugins/fixthisplz/tests/php/run.php            # tests PHP via WP-CLI (transactions annulées)
wp eval-file wp-content/plugins/fixthisplz/tests/php/run.php redactor   # filtre sur le nom de fichier
node --test tests/js/*.test.mjs                                         # tests JS (Node ≥ 22, depuis la racine)
```

## Instructions locales

Les instructions propres à un poste (environnement de test, chemins, identifiants, plans de travail) vont dans
`CLAUDE.local.md`, non versionné (voir `.gitignore`).
