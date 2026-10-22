# Types de diplôme : fonctionnement

Ce document décrit le fonctionnement **réel** des types de diplôme dans le prototype : ce que fait l'admin, ce que voit le responsable de formation, les règles de contrôle, et comment étendre le système. La conception d'origine et ses arbitrages sont dans `docs/superpowers/specs/2026-10-20-creation-diplome-design.md`.

## En bref

- Un **type de diplôme** (Licence, LP, Master, M2E, BUT, ou tout type futur) est entièrement défini par configuration, dans la page **Diplômes** (`/diplomes`). Aucun code ne connaît un diplôme par son nom.
- Un type a une **version par année universitaire** (`2026-2027`…). Chaque version porte toute la configuration : structure, natures d'élément, MCCC, BCC, canevas des fiches, options.
- Les **fiches** formation et parcours que remplit le responsable suivent le **canevas** de la version : entrées choisies dans une **bibliothèque de formulaires** commune, masquées, renommées, réordonnées ou ajoutées. Un formulaire peut avoir des **variantes** qui ne stockent que leurs écarts et suivent leur formulaire de départ.
- Une **formation** est rattachée à un type. Elle utilise la version de son année, ou à défaut la plus récente antérieure.
- La structure d'une version se décrit par une **chaîne de niveaux** et des **bornes**, plus des **types d'EC** et des **règles** (dont les obligations « au moins N EC d'un type »). La maquette de la formation est contrôlée en direct : une modification qui sort des bornes est refusée, sauf **dérogation** accordée par l'admin.
- Seuls l'admin et le SES créent ou modifient un type, et créent une formation d'un type.

## Vocabulaire

| Terme | Sens |
|---|---|
| Type de diplôme | Identité stable : libellé, sigle (= clé), code Apogée, description, accrédité, passage en CFVU, archivé. |
| Version | Configuration du type pour une année universitaire (`AAAA-AAAA`, deux années consécutives). |
| Chaîne de niveaux | Liste ordonnée de types de nœud, du premier niveau à la feuille. Ex. Licence : `annee › semestre › ue › ec` ; M2E : `annee › bloc_formation › ec` (sans semestre). Le niveau parcours est géré à part (« parcours autorisés »). |
| Borne | Contrainte « au moins », « au plus », « entre » ou « exactement ». Elle s'applique **par parent** : « 3 à 6 UE » vaut pour chaque semestre. |
| Durée | Borne sur le nombre de nœuds du niveau qui compte la durée, le premier par défaut (ex. Licence : 3 à 4 années, LP : 1 à 2, BUT : 6 semestres). La formation choisit sa durée à la création. |
| Numéro de départ | Premier indice de numérotation d'un niveau (une LP numérote ses semestres à partir de 5). |
| Nature d'élément | Ce qu'est une feuille : `ec`, `stage`, `ressource`, `sae`… Déclarée par le type, stockée sur le nœud dans l'attribut `natureElement`. Distincte de l'attribut `nature` (obligatoire / à choix). |
| Règle d'obligation | Règle « Au moins N EC d'un type » sur une portée : chaque nœud d'un niveau, le premier, le dernier ou le numéro affiché n (ex. LP : un projet tutoré et un stage dans le dernier semestre ; BUT : un stage en S2, S4 et S6). Elle remplace les anciens « éléments imposés » (nœuds placés d'office et verrouillés). |
| Règle | Contrainte supplémentaire choisie dans un catalogue de **gabarits** et paramétrée par l'admin (ex. ECTS des SAÉ ≥ 40 % par semestre). |
| Dérogation | Écart accordé par l'admin pour une règle, sur un nœud, jusqu'à une valeur précise. |
| Fiche | Ce que le responsable remplit pour la formation ou pour un parcours (la barre latérale de l'éditeur). |
| Canevas | Configuration, portée par la version, des entrées de chaque fiche (clé `canvas` de la configuration). |
| Entrée | Une ligne d'une fiche : un formulaire de la bibliothèque, ou un écran intégré. Ses données sont rangées sous sa clé (`parametres[<entrée>]`). |
| Écran intégré | Entrée dont le contenu est un écran dédié : BCC, configuration de la structure, ramification, positionnement d'un parcours. Il se place (et, pour BCC, se masque) mais ne se remplace pas. |
| Formulaire | Liste de champs d'une fiche : un `NodeType` de famille « paramètre » avec une portée (formation ou parcours). |
| Socle / variante | Un socle ne dérive d'aucun formulaire ; une variante dérive d'un autre et ne stocke que ses écarts (champs masqués ou ajoutés, libellé, aide, obligatoire, onglet, ordre). |

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
3. **Structure** : durée, parcours autorisés, natures d'élément, niveaux de la chaîne avec leurs bornes (nombre par parent, ECTS de chaque nœud, somme des ECTS des enfants, numéro de départ, verrou, rattachement des EC), règles. Un **aperçu** montre la maquette minimale obtenue avec la plus petite durée autorisée (lecture seule, jamais enregistrée).
4. **Formulaires** : le canevas des fiches formation et parcours. Pour chaque entrée : formulaire (de la portée de la fiche, variantes comprises), libellé, masquage, ordre ; « Ajouter une entrée » crée une entrée propre au diplôme. Les écrans intégrés sont toujours présents ; Structure, ramification et positionnement ne se masquent pas. L'enregistrement est refusé si un champ verrouillé de la fiche (nom de la formation, responsables, nom du parcours…) ne figure dans aucune entrée affichée. « Adapter » crée une variante du formulaire d'une entrée (« <formulaire> · <sigle> ») et la choisit ; « Modifier » ouvre le formulaire choisi ; « Aperçu » ouvre la vraie page d'une formation rendue avec le canevas en cours, sans rien enregistrer.
5. **MCCC** : pour chaque nature déclarée, les types de MCCC autorisés et le profil de règles ; options quitus, contrôle d'assiduité, « sans note ». Une nature sans type coché n'a aucune MCCC proposée.
6. **BCC** : un à trois noms de niveaux (ex. BUT : Compétence › Niveau › Apprentissage critique ; M2E : Bloc › Objectif › Attendu) et le niveau auquel les éléments sont reliés (par défaut, le dernier).
7. **Récapitulatif** : toute la configuration en clair, et les différences avec le type de départ. **Enregistrer** revalide tout côté serveur (sigle encore libre, année valide…) avant de créer le type et sa version.

### Modifier une version

`/diplomes/{id}/versions/{versionId}/{étape}` : les mêmes étapes, en onglets (Informations, Structure, Formulaires, MCCC, BCC).

- **Informations** modifie le type lui-même (toutes ses versions). Le sigle est figé dès qu'une formation utilise le type. L'année de la version ne se modifie pas.
- Si des formations utilisent la version (ou le type, pour l'onglet Informations), la page les liste et l'enregistrement exige de cocher la **confirmation d'impact**. Une modification de version est faite pour corriger une erreur de saisie ; un changement de règle se fait dans la version de l'année suivante (**Nouvelle version**).
- Bornes, règles et options sont lues en direct par les formations : une correction s'applique aussitôt.
- Rien n'est propagé dans l'arbre des formations : les obligations sont des règles, lues en direct comme les bornes.

### Bibliothèque de formulaires

`/node-types`, section « Formulaires de fiche » : les socles, puis leurs variantes en retrait, avec le nombre de versions de diplôme qui les utilisent.

- **Créer une variante** depuis la liste ou depuis le canevas (« Adapter »). L'écran d'édition d'une variante montre l'origine de chaque champ (hérité, ajouté, masqué, libellé changé, rendu obligatoire…), permet de masquer ou réafficher un champ hérité (un champ verrouillé reste affiché) et signale les champs apparus depuis dans le formulaire de départ.
- Libellé, aide, obligatoire, onglet et ordre d'un champ sont **propres à chaque formulaire de fiche** : renommer un champ dans un formulaire ne touche pas les autres. Le type et les options du champ restent partagés.
- Un formulaire utilisé par des formations ne se modifie qu'avec la **confirmation d'impact** ; un formulaire utilisé par un canevas, ou dont dérive une variante, ne se supprime pas.
- **Aperçu** : la fiche d'une formation rendue avec ce formulaire.

### Aperçu

L'aperçu ouvre l'éditeur d'une vraie formation (de la version, à défaut du type, à défaut une autre) avec le canevas substitué pour cette seule formation, le temps de la session. Un bandeau le signale ; la formation est en lecture seule (champs désactivés, écritures refusées) jusqu'à « Quitter l'aperçu ».

### Dérogations

`/administration/derogations` : l'admin voit, pour chaque demande, la formation, la règle, le nœud et la valeur demandée, puis accorde ou refuse. Chaque décision est horodatée et visible dans l'historique de la formation (page « Configuration de la structure »).

## Côté formation

### Création

Modale « Ajouter une formation » de la liste (admin seulement) : type (non archivé), année universitaire, durée dans les bornes du type, avec ou sans parcours (si le type l'autorise). La maquette de départ est générée :

- le nombre de nœuds de chaque niveau vaut le minimum de sa borne (1 sans minimum), jusqu'à l'avant-dernier niveau ; le niveau feuille n'est pas créé ;
- aucun élément n'est placé d'office : les obligations (« au moins N EC d'un type ») restent à remplir et apparaissent comme écarts. La maquette générée est enregistrée sans passer par le garde ; ses écarts de départ servent de référence aux modifications suivantes (ajouter un EC d'un autre type n'est pas refusé, retirer le seul stage exigé l'est) ;
- en multi-parcours, un premier parcours est créé et la structure est bâtie dedans.

Tout est refusé avant écriture si un paramètre est invalide, et la création est faite dans une transaction : un échec ne laisse aucune formation.

### Fiches

- La barre latérale de l'éditeur de formation et de l'éditeur de parcours est construite depuis le canevas : entrées non masquées, dans l'ordre, numérotées à la suite.
- Une **formation sans parcours** affiche aussi, dans un groupe « Parcours », les entrées à formulaire de la fiche parcours ; leurs données sont rangées sur la formation (`parametres['parcours.<entrée>']`) et suivent le passage avec ↔ sans parcours. L'écran « Configuration de la structure » ne porte plus de champs de parcours.
- Une entrée masquée n'apparaît ni dans la barre latérale, ni sur les pages Vérifier et Voir, ni dans l'export enrichi ; ses données restent stockées (l'export brut les contient). Changer le formulaire d'une entrée conserve les valeurs par clé de champ.
- Les champs verrouillés liés à l'entité (nom, domaine, composante de la formation ; nom et ECTS du parcours) sont rendus par `templates/formation/param/bound/<fiche>/<clé>.html.twig` et postés sous `b[]`.

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
| « Au moins N EC d'un type » non atteint | Bloquante |
| Règle par gabarit | Selon le gabarit (minimum non bloquant, maximum bloquant), et toujours non bloquante si l'admin l'a mise en « avertissement » |
| Ratio « ≥ » ou « > » | Non bloquant (exigence de minimum) ; une portée sans feuille est conforme |
| Ratio « ≤ » ou « < » | Bloquant |
| Règle dont le gabarit est inconnu | Signalée (« Règle inconnue ») sans bloquer |

### Points particuliers

- **Bloc à choix** : il compte pour un seul enfant dans les bornes, avec ses propres ECTS. Pour les sommes sur les feuilles, un seul de ses enfants est compté.
- **Durée et ramification** : la durée se mesure par cursus. Pour chaque parcours feuille, on additionne ses périodes et celles de ses parcours parents (portail compris). Un parcours qui a des sous-parcours n'est pas mesuré seul.
- **Position d'une règle** : `all` (chaque nœud du niveau), `first`, `last` ou le numéro affiché (après le numéro de départ : en LP, « S6 » est `6`). Premier, dernier et numéro se lisent cursus par cursus. En `first` / `last`, l'écart est signalé sous le cursus (le parcours feuille, la racine sans parcours) : agrandir la maquette (passer une LP de 1 à 2 ans) déplace le dernier semestre sans créer d'écart nouveau. Un numéro reste signalé sous son nœud.
- **Maquettes anciennes** : un nœud marqué `_imposed` (ancien élément imposé) redevient un nœud ordinaire au chargement : le marqueur et ses verrous sont retirés.
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
| `rule:<uid>` / `rule:<uid>:min` / `rule:<uid>:max` | Règle par gabarit |
| `rule:<uid>:unknown` | Gabarit inconnu |

Une dérogation accordée couvre la même clé et le même nœud : jusqu'à la valeur accordée pour une clé `:max`, à partir de cette valeur pour `:min` ; pour les autres clés, à la valeur accordée exactement, ou sans condition si aucune valeur n'est accordée (ex. `lock:<type>`).

### Gabarits de règles disponibles

| Gabarit | Paramètres | Exemple |
|---|---|---|
| `child_count` | type compté, minimum, maximum | 3 à 6 UE par semestre |
| `attribute_sum` | attribut, minimum, maximum, type compté | 30 ECTS par semestre |
| `attribute_value` | attribut, minimum, maximum | au plus 10 ECTS par UE |
| `ratio` | attribut, nature, comparaison, pourcentage | ECTS des SAÉ ≥ 40 % du semestre |
| `same_child_value` | attribut | tous les choix d'un bloc ont les mêmes ECTS |
| `min_element_type` | type d'EC, minimum | au moins 1 stage dans le dernier semestre |

Chaque règle a une portée (le niveau sur lequel elle est évaluée, et sa position dans ce niveau) et une gravité (erreur ou avertissement).

## Données de démonstration

`src/DataFixtures/TypeDiplomeFixtures.php` recrée par configuration :

| Type | Points notables |
|---|---|
| Licence (`L`) | 3 à 4 ans, 2 semestres de 30 ECTS par année, 3 à 6 UE par semestre, natures `ec` et `stage`, profil MCCC Licence, BCC Bloc › Compétence |
| Licence professionnelle (`LP`) | Basée sur L, 1 à 2 ans, semestres numérotés à partir de 5, types d'EC « Projet tutoré » et « Stage » avec une règle « au moins 1 » chacun dans le dernier semestre, profil MCCC Master |
| Master (`M`) | Basé sur L, 2 ans, profil MCCC Master |
| Master M2E (`M2E`) | `annee › bloc_formation › ec` sans semestre, 1 à 4 blocs par année, BCC Bloc › Objectif › Attendu |
| BUT (`BUT`) | 6 semestres (durée comptée en semestres), exactement 5 UE par semestre (niveau verrouillé, EC rattachés par coefficient), types d'EC Ressource, SAÉ, Stage, Portfolio, MCCC en contrôle continu, règles « au moins 1 stage » en S2, S4 et S6 et « SAÉ ≥ 40 % par semestre » (valeurs de démonstration) |

Le rattachement d'une ressource ou d'une SAÉ à plusieurs UE avec un coefficient dans chacune n'est pas encore géré (chantier 5).

## Pour les développeurs

### Où est quoi

| Brique | Fichiers |
|---|---|
| Entités | `src/Entity/TypeDiplome.php`, `TypeDiplomeVersion.php` ; `Formation::typeDiplome` |
| Configuration typée | `src/Diplome/Spec/` (`VersionConfig`, `StructureSpec`, `LevelSpec`, `Bound`, `DurationSpec`, `EcType`, `RuleSpec`) : le JSON de version est toujours lu et écrit par ces objets |
| Version d'une formation | `src/Diplome/TypeDiplomeResolver.php` |
| Maquette de départ | `src/Diplome/StructureInstantiator.php` ; enregistrée par `Maquette::saveGenerated()` |
| Création de formation | `src/Diplome/FormationCreator.php` |
| Contrôle | `src/Diplome/Check/StructureChecker.php` (violations), `StructureContextBuilder.php` (données évaluées), `StructureGuard.php` (refus à l'enregistrement, appelé par `Maquette::save()`), `src/EventSubscriber/StructureViolationListener.php` (réponse HTTP / JSON) |
| Gabarits de règles | `src/Diplome/Rule/` ; moteur `src/Rules/RuleEvaluator.php` (AST JSON, jamais d'`eval`) |
| Assistant et édition | `src/Diplome/Wizard/` (une classe par étape), `src/Controller/DiplomeWizardController.php`, `DiplomeEditController.php`, `src/Form/Diplome/` |
| Versions | `src/Diplome/NextVersionCreator.php`, `VersionUsage.php` |
| MCCC par nature | `src/Diplome/VersionMccc.php` |
| Canevas, écrans intégrés, navigation | `src/Diplome/Spec/CanvasSpec.php`, `CanvasEntry.php` ; `src/Formulaire/CanvasResolver.php`, `CanvasDefaults.php`, `FicheNavigation.php`, `Builtin/` |
| Formulaires, variantes, usage | `src/Formulaire/FormResolver.php`, `ResolvedField.php`, `VariantFactory.php`, `FormUsage.php` ; `src/Controller/NodeTypeController.php` |
| Fiches (données, enregistrement) | `src/Formulaire/Fiche/` ; `src/Formulaire/EntryCompletion.php` ; `src/Controller/FicheEntryController.php` ; `templates/formation/param/entry.html.twig` |
| Étape « Formulaires », Adapter, Aperçu | `src/Diplome/Wizard/CanvasStep.php`, `src/Form/Diplome/CanvasType.php` ; `src/Controller/CanvasController.php` ; `src/Formulaire/Preview/` ; `src/EventSubscriber/PreviewGuardSubscriber.php` |
| Rendu des champs | `src/FieldType/` (une classe par type de champ, avec ses gabarits `templates/field_type/`) |

### Étendre sans toucher au reste

- **Nouveau gabarit de règle** : une classe qui implémente `App\Diplome\Rule\RuleTemplateInterface` (`key()`, `label()`, `params()`, `compile()`). Elle est découverte automatiquement ; l'assistant génère ses champs à partir de `params()`. Un paramètre `RuleParam` marqué `internal` n'est jamais montré à l'admin.
- **Nouvelle étape d'assistant** : une classe qui implémente `App\Diplome\Wizard\WizardStepInterface` (ou étend `AbstractWizardStep`). `position()` fixe son rang ; `editable()` en fait aussi un onglet d'édition.
- **Nouveau type de champ** : une classe qui implémente `App\FieldType\FieldTypeInterface`, avec `widgetTemplate()` et `viewTemplate()`. Aucun gabarit ne doit tester le type ou la clé d'un champ.
- **Nouvel écran intégré** : une classe qui implémente `App\Formulaire\Builtin\BuiltinScreenInterface` (`key()`, `fiche()`, `hideable()`, `defaultPosition()`, `appliesTo()`, `url()`, `status()`, `isCurrent()`). Il apparaît dans le canevas par défaut et dans tous les canevas.
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
- Les fiches des éléments (année, semestre, UE, élément par nature) ne sont pas encore dans le canevas (lot 2) : leurs champs restent réglés globalement sur le type de nœud.
- Après la mise à jour du schéma (colonnes de `NodeType`), `make db-reset` est nécessaire.
