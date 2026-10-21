# Types de diplôme : fonctionnement

Ce document décrit le fonctionnement **réel** des types de diplôme dans le prototype : ce que fait l'admin, ce que voit le responsable de formation, les règles de contrôle, et comment étendre le système. La conception d'origine et ses arbitrages sont dans `docs/superpowers/specs/2026-10-20-creation-diplome-design.md`.

## En bref

- Un **type de diplôme** (Licence, LP, Master, M2E, BUT, ou tout type futur) est entièrement défini par configuration, dans la page **Diplômes** (`/diplomes`). Aucun code ne connaît un diplôme par son nom.
- Un type a une **version par année universitaire** (`2026-2027`…). Chaque version porte toute la configuration : structure, natures d'élément, MCCC, BCC, formulaires, options.
- Une **formation** est rattachée à un type. Elle utilise la version de son année, ou à défaut la plus récente antérieure.
- La structure d'une version se décrit par une **chaîne de niveaux** et des **bornes**, plus des **éléments imposés** et des **règles**. La maquette de la formation est contrôlée en direct : une modification qui sort des bornes est refusée, sauf **dérogation** accordée par l'admin.
- Seuls l'admin et le SES créent ou modifient un type, et créent une formation d'un type.

## Vocabulaire

| Terme | Sens |
|---|---|
| Type de diplôme | Identité stable : libellé, sigle (= clé), code Apogée, description, accrédité, passage en CFVU, archivé. |
| Version | Configuration du type pour une année universitaire (`AAAA-AAAA`, deux années consécutives). |
| Chaîne de niveaux | Liste ordonnée de types de nœud, du premier niveau à la feuille. Ex. Licence : `annee › semestre › ue › ec` ; M2E : `annee › bloc_formation › ec` (sans semestre). Le niveau parcours est géré à part (« parcours autorisés »). |
| Borne | Contrainte « au moins », « au plus », « entre » ou « exactement ». Elle s'applique **par parent** : « 3 à 6 UE » vaut pour chaque semestre. |
| Durée | Borne sur le nombre de nœuds du premier niveau (ex. Licence : 3 à 4 années, LP : 1 à 2). La formation choisit sa durée à la création. |
| Numéro de départ | Premier indice de numérotation d'un niveau (une LP numérote ses semestres à partir de 5). |
| Nature d'élément | Ce qu'est une feuille : `ec`, `stage`, `ressource`, `sae`… Déclarée par le type, stockée sur le nœud dans l'attribut `natureElement`. Distincte de l'attribut `nature` (obligatoire / à choix). |
| Élément imposé | Nœud précis qui doit exister (ex. UE « Projet tutoré et stage » de la LP), avec une position (`first`, `last`, `all` ou un numéro parmi les nœuds du niveau parent) et des verrous (ajout, suppression, duplication). |
| Règle | Contrainte supplémentaire choisie dans un catalogue de **gabarits** et paramétrée par l'admin (ex. ECTS des SAÉ ≥ 40 % par semestre). |
| Dérogation | Écart accordé par l'admin pour une règle, sur un nœud, jusqu'à une valeur précise. |

## Côté admin

### Page Diplômes

`/diplomes`, entrée « Diplômes » de la barre de navigation (admin seulement, séparée de l'Administration).

- La liste affiche : libellé, sigle, type de départ, durée, niveaux de BCC, nombre de formations, dernière version.
- **Nouveau diplôme** ouvre l'assistant. **Dupliquer** ouvre l'assistant pré-rempli avec la version choisie.
- **Modifier** ouvre la dernière version en onglets. **Nouvelle version** crée l'année suivante par copie de la dernière version.
- **Archiver** retire le type de la création de formation, sans rien supprimer. **Supprimer** n'est possible que si aucune formation n'utilise le type ; le refus est aussi appliqué côté serveur.
- Toutes les actions passent par un jeton CSRF et sont réservées à l'admin côté serveur (préfixe de route `diplome_`).

### Assistant « Nouveau diplôme »

Sept étapes, dans cet ordre. Le brouillon est gardé en session ; les étapes suivantes ne sont accessibles qu'une fois les informations valides. « Précédent » et la barre latérale enregistrent l'étape courante avant de changer d'étape. Revenir sur `/diplomes/nouveau` reprend le brouillon ; « Recommencer » (ou `?nouveau=1`) le vide.

1. **Informations** : libellé, sigle (unique, insensible à la casse), code Apogée (1 caractère), année universitaire, description, accrédité, passage en CFVU.
2. **Type de départ** (facultatif) : copie toute la configuration d'une version existante. On ne modifie ensuite que les différences. Changer de type de départ remplace la configuration du brouillon.
3. **Formulaires** : pour chaque emplacement (formation › organisation, formation › présentation, parcours › organisation, parcours › présentation), le type d'ELP de famille « paramètre » utilisé. Seuls les formulaires de la bonne portée sont proposés (`*_formation` / `*_parcours`).
4. **Structure** : durée, parcours autorisés, natures d'élément, niveaux de la chaîne avec leurs bornes (nombre par parent, ECTS de chaque nœud, somme des ECTS des enfants, numéro de départ), éléments imposés, règles. Un **aperçu** montre la maquette minimale obtenue avec la plus petite durée autorisée (lecture seule, jamais enregistrée).
5. **MCCC** : pour chaque nature déclarée, les types de MCCC autorisés et le profil de règles ; options quitus, contrôle d'assiduité, « sans note ». Une nature sans type coché n'a aucune MCCC proposée.
6. **BCC** : un à trois noms de niveaux (ex. BUT : Compétence › Niveau › Apprentissage critique ; M2E : Bloc › Objectif › Attendu) et le niveau auquel les éléments sont reliés (par défaut, le dernier).
7. **Récapitulatif** : toute la configuration en clair, et les différences avec le type de départ. **Enregistrer** revalide tout côté serveur (sigle encore libre, année valide…) avant de créer le type et sa version.

### Modifier une version

`/diplomes/{id}/versions/{versionId}/{étape}` : les mêmes étapes, en onglets (Informations, Formulaires, Structure, MCCC, BCC).

- **Informations** modifie le type lui-même (toutes ses versions). Le sigle est figé dès qu'une formation utilise le type. L'année de la version ne se modifie pas.
- Si des formations utilisent la version (ou le type, pour l'onglet Informations), la page les liste et l'enregistrement exige de cocher la **confirmation d'impact**. Une modification de version est faite pour corriger une erreur de saisie ; un changement de règle se fait dans la version de l'année suivante (**Nouvelle version**).
- Bornes, règles et options sont lues en direct par les formations : une correction s'applique aussitôt.
- Les éléments imposés vivent dans l'arbre des formations. Après en avoir ajouté ou modifié, le bouton **Propager aux formations** ajoute les éléments manquants et réapplique les verrous. La propagation passe par le contrôle normal ; une formation qu'elle ferait sortir des bornes est signalée et laissée intacte.

### Dérogations

`/administration/derogations` : l'admin voit, pour chaque demande, la formation, la règle, le nœud et la valeur demandée, puis accorde ou refuse. Chaque décision est horodatée et visible dans l'historique de la formation (page « Configuration de la structure »).

## Côté formation

### Création

Modale « Ajouter une formation » de la liste (admin seulement) : type (non archivé), année universitaire, durée dans les bornes du type, avec ou sans parcours (si le type l'autorise). La maquette de départ est générée :

- le nombre de nœuds de chaque niveau vaut le minimum de sa borne (1 sans minimum), jusqu'à l'avant-dernier niveau ; le niveau feuille n'est pas créé ;
- les éléments imposés sont ajoutés avec leurs verrous ;
- en multi-parcours, un premier parcours est créé et la structure est bâtie dedans.

Tout est refusé avant écriture si un paramètre est invalide, et la création est faite dans une transaction : un échec ne laisse aucune formation.

### Édition et contrôle

- Chaque modification de la maquette (ajout, suppression, déplacement, duplication, collage, grille rapide, paramètres) est contrôlée à l'enregistrement.
- Une modification qui crée une **nouvelle** violation bloquante, ou qui aggrave une violation existante, est **refusée**. Le message indique la règle et propose « Demander une dérogation » (formulaire pré-rempli avec la règle, le nœud et la valeur constatée). En glisser-déposer et dans la grille rapide, le refus s'affiche dans un message en bas de page avec le même lien.
- Une maquette déjà hors bornes n'empêche pas de modifier autre chose.
- Annuler, rétablir et restaurer une révision ne sont jamais bloqués.
- Une formation sans type de diplôme n'est jamais contrôlée.
- La page **Vérifier** (`/formations/{id}/verifier`) liste toutes les violations, bloquantes ou non, avec le nœud concerné. Chaque violation compte comme un élément incomplet dans la progression de la formation.

## Règles de contrôle en détail

### Bloquant ou non

| Règle | Effet |
|---|---|
| Partie « au plus » d'une borne (nombre, ECTS, somme, durée) | Bloquante |
| Partie « au moins » d'une borne | Non bloquante : la maquette est « incomplète » tant que le minimum n'est pas atteint |
| Élément imposé absent | Bloquante |
| Règle par gabarit | Selon le gabarit (minimum non bloquant, maximum bloquant), et toujours non bloquante si l'admin l'a mise en « avertissement » |
| Ratio « ≥ » ou « > » | Non bloquant (exigence de minimum) ; une portée sans feuille est conforme |
| Ratio « ≤ » ou « < » | Bloquant |
| Règle dont le gabarit est inconnu | Signalée (« Règle inconnue ») sans bloquer |

### Points particuliers

- **Bloc à choix** : il compte pour un seul enfant dans les bornes, avec ses propres ECTS. Pour les sommes sur les feuilles, un seul de ses enfants est compté.
- **Durée et ramification** : la durée se mesure par cursus. Pour chaque parcours feuille, on additionne ses périodes et celles de ses parcours parents (portail compris). Un parcours qui a des sous-parcours n'est pas mesuré seul.
- **Éléments imposés** :
  - position `first`, `last` ou un numéro (`1`, `2`…) : l'élément doit exister quelque part dans la portée (le parcours, ou le cursus en ramification). La position ne sert qu'à le **placer** à la création et à la propagation. Agrandir la maquette (ajouter une UE après la dernière, passer une LP de 1 à 2 ans) n'est donc pas bloqué ;
  - position `all` : l'élément est attendu sous **chaque** nœud de son niveau parent. L'éditeur le place d'office sous un nouvel hôte (ajout, duplication, raccrochage) ;
  - le niveau parent d'un élément imposé est toujours le niveau juste au-dessus de son type dans la chaîne.
- **Nature par défaut** : un nœud sans `natureElement` prend la première nature déclarée par le type, pour les MCCC comme pour les règles.
- **Niveaux nationaux** (référentiel porté par la formation, ex. BUT) : le niveau d'une année est retrouvé par son rang, jamais par son libellé.

### Clés de règles

Les violations et les dérogations utilisent ces clés, avec le nœud concerné (`scopeNid`, vide pour la racine d'une formation sans parcours) :

| Clé | Signification |
|---|---|
| `level:<type>:count:min` / `:max` | Nombre de nœuds `<type>` sous chaque parent |
| `level:<type>:ects:min` / `:max` | ECTS de chaque nœud `<type>` |
| `level:<type>:ectsSum:min` / `:max` | Somme des ECTS des enfants de chaque nœud `<type>` |
| `duration:min` / `:max` | Durée, par cursus |
| `imposed:<uid>` | Élément imposé absent (scope : la portée, ou l'hôte en position `all`) |
| `rule:<uid>` / `rule:<uid>:min` / `rule:<uid>:max` | Règle par gabarit |
| `rule:<uid>:unknown` | Gabarit inconnu |

Une dérogation accordée couvre la même clé et le même nœud : jusqu'à la valeur accordée pour une clé `:max`, à partir de cette valeur pour `:min`, sans condition pour `imposed:`.

### Gabarits de règles disponibles

| Gabarit | Paramètres | Exemple |
|---|---|---|
| `child_count` | type compté, minimum, maximum | 3 à 6 UE par semestre |
| `attribute_sum` | attribut, minimum, maximum, type compté | 30 ECTS par semestre |
| `attribute_value` | attribut, minimum, maximum | au plus 10 ECTS par UE |
| `ratio` | attribut, nature, comparaison, pourcentage | ECTS des SAÉ ≥ 40 % du semestre |
| `same_child_value` | attribut | tous les choix d'un bloc ont les mêmes ECTS |
| `required_element` | type, nature | une UE de nature stage par parcours |

Chaque règle a une portée (le niveau sur lequel elle est évaluée) et une gravité (erreur ou avertissement).

## Données de démonstration

`src/DataFixtures/TypeDiplomeFixtures.php` recrée par configuration :

| Type | Points notables |
|---|---|
| Licence (`L`) | 3 à 4 ans, 2 semestres de 30 ECTS par année, 3 à 6 UE par semestre, natures `ec` et `stage`, profil MCCC Licence, BCC Bloc › Compétence |
| Licence professionnelle (`LP`) | Basée sur L, 1 à 2 ans, semestres numérotés à partir de 5, UE imposée « Projet tutoré et stage » dans le dernier semestre, profil MCCC Master |
| Master (`M`) | Basé sur L, 2 ans, profil MCCC Master |
| Master M2E (`M2E`) | `annee › bloc_formation › ec` sans semestre, 1 à 4 blocs par année, BCC Bloc › Objectif › Attendu |
| BUT (`BUT`) | 3 ans, natures `ressource`, `sae`, `stage`, MCCC en contrôle continu, stage imposé dans la dernière UE, règle « SAÉ ≥ 40 % par semestre » (valeur de démonstration) |

Le rattachement d'une ressource ou d'une SAÉ à plusieurs UE avec un coefficient dans chacune n'est pas encore géré (chantier 5).

## Pour les développeurs

### Où est quoi

| Brique | Fichiers |
|---|---|
| Entités | `src/Entity/TypeDiplome.php`, `TypeDiplomeVersion.php` ; `Formation::typeDiplome` |
| Configuration typée | `src/Diplome/Spec/` (`VersionConfig`, `StructureSpec`, `LevelSpec`, `Bound`, `ImposedElement`, `RuleSpec`) : le JSON de version est toujours lu et écrit par ces objets |
| Version d'une formation | `src/Diplome/TypeDiplomeResolver.php` |
| Maquette de départ | `src/Diplome/StructureInstantiator.php`, `ImposedHostLocator.php` |
| Création de formation | `src/Diplome/FormationCreator.php` |
| Contrôle | `src/Diplome/Check/StructureChecker.php` (violations), `StructureContextBuilder.php` (données évaluées), `StructureGuard.php` (refus à l'enregistrement, appelé par `Maquette::save()`), `src/EventSubscriber/StructureViolationListener.php` (réponse HTTP / JSON) |
| Gabarits de règles | `src/Diplome/Rule/` ; moteur `src/Rules/RuleEvaluator.php` (AST JSON, jamais d'`eval`) |
| Assistant et édition | `src/Diplome/Wizard/` (une classe par étape), `src/Controller/DiplomeWizardController.php`, `DiplomeEditController.php`, `src/Form/Diplome/` |
| Versions et propagation | `src/Diplome/NextVersionCreator.php`, `VersionUsage.php`, `ImposedPropagator.php` |
| MCCC par nature | `src/Diplome/VersionMccc.php` |
| Formulaires de paramètres | `src/Diplome/ParamTypeResolver.php` |
| Rendu des champs | `src/FieldType/` (une classe par type de champ, avec ses gabarits `templates/field_type/`) |

### Étendre sans toucher au reste

- **Nouveau gabarit de règle** : une classe qui implémente `App\Diplome\Rule\RuleTemplateInterface` (`key()`, `label()`, `params()`, `compile()`). Elle est découverte automatiquement ; l'assistant génère ses champs à partir de `params()`. Un paramètre `RuleParam` marqué `internal` n'est jamais montré à l'admin.
- **Nouvelle étape d'assistant** : une classe qui implémente `App\Diplome\Wizard\WizardStepInterface` (ou étend `AbstractWizardStep`). `position()` fixe son rang ; `editable()` en fait aussi un onglet d'édition.
- **Nouveau type de champ** : une classe qui implémente `App\FieldType\FieldTypeInterface`, avec `widgetTemplate()` et `viewTemplate()`. Aucun gabarit ne doit tester le type ou la clé d'un champ.
- **Options de champ calculées** : une classe qui implémente `App\FieldType\Options\FieldOptionsSourceInterface`, référencée par `FieldDef::optionsSource` (ex. `version_natures` pour `natureElement`). Un champ dont la source ne renvoie rien n'est pas affiché.

### Règles à respecter

- Aucun code, gabarit ou condition ne teste le nom ou la clé d'un diplôme précis : toute spécificité devient un réglage générique.
- Toute écriture de maquette passe par `Maquette::save()` (et donc par le contrôle). Seule la restauration d'une révision le contourne.
- Pas de migrations dans ce prototype : le schéma est créé par `doctrine:schema:create`. Après un changement de schéma, `make db-reset` est nécessaire et **efface les données locales**.
- Tests : uniquement dans le conteneur, `docker compose exec -T app php bin/phpunit`.

## Limites connues

- Les MCCC détaillées par profil (chantier 2), le contenu des BCC à plusieurs niveaux (chantier 3), la création de formation guidée complète (chantier 4) et le rattachement multiple (chantier 5) restent à faire.
- Les règles de validation du diplôme (compensation, seuils de note) ne sont pas décrites.
- Les spécificités des règlements intérieurs des composantes ne sont pas gérées.
- Changer d'onglet dans l'édition d'une version perd la saisie non enregistrée.
- Les messages de règle citent la clé brute de l'attribut (« ects »).
