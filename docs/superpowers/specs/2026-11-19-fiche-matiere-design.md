# Fiche matière : entité partagée et valeurs résolues de l'EC

Conception du 19/11/2026. Point P0 de `AUDIT.md` (§2) : la fiche matière n'est aujourd'hui qu'une valeur du
référentiel `fiches_matiere`. Ce document la transforme en objet du prototype, au niveau fonctionnel d'ORéOF v2.

## 1. Intention

Le prototype doit servir de référence pour implémenter la maquette dans ORéOF v2. Tant que la fiche matière n'est
qu'un libellé, le modèle de l'EC est incomplet : pas de description partagée, pas de mutualisation de fiche, pas de
fiche hors diplôme, pas de valeurs imposées. Les fiches d'aide à la rédaction v2 (« Fiche EC / matière » et « Fiche EC
/ matière hors diplôme ») ne sont pas saisissables.

Exigence de l'utilisateur : **au moins toutes les fonctionnalités de v2, améliorées si possible, jamais moins.**
Le cycle de vie de la fiche (circuit de validation, réouverture, versioning, historique, commentaires) reste hors
périmètre, comme le reste du cycle de vie (`AUDIT.md`, « Hors périmètre du prototype »).

## 2. Décisions prises avec l'utilisateur (19/11/2026)

- **D1 — Parité v2 minimum.** Toute fonctionnalité de la fiche matière v2 qui touche la maquette existe dans le
  prototype (inventaire §4). Une amélioration est permise si elle ne retire rien.
- **D2 — Pas de cycle de vie.** Ni états, ni workflow `fiche`, ni demande de réouverture, ni versioning, ni
  historique propre, ni commentaires. Le modèle garde seulement la place d'un état (§6.1).
- **D3 — Approche « fiche à part, formulaire configurable, valeurs résolues champ par champ »** (§3, approche 1).
- **D5 — Fiche utilisable par un autre type de diplôme.** Comme en v2, une fiche mutualisée ou hors diplôme peut
  servir à une formation d'un autre type ou d'une autre version que son porteur. Quand une valeur de la fiche ne
  convient pas au diplôme de l'EC (type de MCCC non autorisé par sa version, type d'EC absent de sa structure),
  l'EC le signale en anomalie (validateur MCCC, complétude) et l'utilisateur passe la valeur en « spécifique ». Le
  type d'EC de la fiche ne s'impose (§6.5) que s'il existe dans la version de l'EC ; sinon l'EC garde le sien et
  l'anomalie le signale.
- **D6 — Fiches du portail partagées avec ses sous-parcours.** Avec la ramification, les fiches portées par un
  parcours sont utilisables par tous ses parcours descendants sans mutualisation à déclarer. Pour un EC d'un
  sous-parcours, la fiche se comporte comme une fiche d'un autre parcours (§7.2) : heures, ECTS et MCCC repris de
  la fiche, compétences propres au sous-parcours.
- **Rappel D4 du 22/10** (`2026-10-22-structure-arbre-design.md`) : une seule fiche EC dont le type de diplôme active
  les options ; pas de champ propre à un diplôme.
- **Rappel du principe directeur** : aucun comportement codé pour un diplôme précis.

## 3. Approches

| | Approche | Ce qu'elle donne | Ce qu'elle coûte | Décision |
|---|---|---|---|---|
| 1 | Entité `FicheMatiere` (liens relationnels + contenu JSON), formulaire fait avec le constructeur existant, valeurs de l'EC résolues par une règle unique par valeur | parité v2, options ajoutées par configuration, 8 drapeaux v2 remplacés par une source par valeur | construire un résolveur et l'appliquer à tous les lecteurs de valeurs | **retenue** |
| 2 | Copie exacte de v2 : colonnes dédiées, drapeaux `*Specifiques`, `synchro*`, `*EnfantsIdentiques`, `*Impose` | transposition triviale | chaque option nouvelle demande du code ; contraire au principe directeur ; reproduit la complexité de v2 | écartée |
| 3 | Fiche stockée dans le JSON de la formation porteuse | aucune entité nouvelle | une fiche hors diplôme n'a pas de formation ; la mutualisation entre formations oblige à ouvrir toutes les formations | écartée |

## 4. Inventaire v2 à couvrir

Source : `oreofv2/src/Entity/FicheMatiere.php`, `ElementConstitutif.php`, `FicheMatiereMutualisable.php`,
`src/Classes/GetElementConstitutif.php`, `src/Controller/FicheMatiere*`, `ParcoursEcController.php`.

**Fiche**
- identité : libellé, libellé anglais, sigle, responsable (référent), code Apogée, type de matière (`typeMatiere`) ;
- rattachement : parcours porteur (`parcours`), ou hors diplôme (`horsDiplome`, avec `typeDiplome` facultatif) ;
- changement de parcours porteur, avec écran des impacts ;
- mutualisation : parcours autorisés (`FicheMatiereMutualisable`), ajout, retrait, « tout retirer », impacts ;
- présentation : description, objectifs, langues des cours (`langueDispense`), langues des supports
  (`langueSupport`), modalité d'enseignement ;
- compétences (`competences`) et apprentissages critiques (`apprentissagesCritiques`) ;
- valeurs par défaut : volumes présentiel et distanciel × CM / TD / TP, TE, « sans heures » ; ECTS ; MCCC (type et
  épreuves), « sans note », quitus avec texte ;
- volumes mutualisés par modalité (`is{Cm,Td,Tp}{Presentiel,Distanciel}Mutualise`) ;
- imposition aux parcours utilisateurs : `volumesHorairesImpose`, `ectsImpose`, `mcccImpose` ;
- dupliquer, rechercher une fiche dans un parcours, voir les parcours et EC qui l'utilisent.

**EC**
- fiche matière obligatoire ;
- libellé libre (`texteEcLibre`) ;
- valeurs propres : heures, ECTS, MCCC, compétences, avec drapeaux `heuresSpecifiques`, `ects_specifiques`,
  `mccc_specifiques` ;
- synchronisation avec l'EC du parcours porteur (`synchroHeures`, `synchroEcts`, `synchroMccc`, `synchroBcc`) ;
- EC parent à choix avec « heures / MCCC identiques pour les enfants » (`heuresEnfantsIdentiques`,
  `mcccEnfantsIdentique`) ;
- quitus avec texte, contrôle d'assiduité, « sans heures », code Apogée, volumes mutualisés par modalité.

**Ordre de résolution v2** (`GetElementConstitutif`), repris tel quel au §7 :
valeur imposée par la fiche > valeur spécifique de l'EC > valeur commune du parent à choix > valeur de la fiche.
Pour un EC dont la fiche appartient à un autre parcours, les drapeaux `synchro*` désignent l'EC du parcours porteur
comme source.

## 5. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Saisir une fiche matière complète, depuis un parcours ou hors diplôme, avec les rubriques des fiches d'aide v2 :
  Identité, Mutualisation, Présentation, Compétences, Volumes horaires, MCCC.
- Rattacher chaque EC à une fiche, et voir pour chaque valeur de l'EC d'où elle vient.
- Mutualiser une fiche vers d'autres parcours, y compris d'autres formations.
- Imposer volumes, ECTS ou MCCC aux parcours utilisateurs.
- Faire lire les valeurs résolues par tous les calculs et toutes les sorties du prototype (totaux, complétude,
  règles de structure, règles MCCC, saisie rapide, export Excel des MCCC, export JSON).
- Donner une correspondance exacte vers v2 pour la migration (§10).

**Non-objectifs**
- Cycle de vie de la fiche (D2).
- Gestion des droits par fiche : le prototype garde ses trois rôles simulés.
- Coefficients EC × niveau (point P0 séparé de l'audit).
- 2ᵉ session, « dont TP » et autres compléments MCCC (point P0 séparé) : la fiche reprend la saisie MCCC telle
  qu'elle existe ; quand elle s'enrichira, la fiche et l'EC en profiteront ensemble.

**Anti-objectifs** (échec même si ça marche)
- Un champ, une condition ou un gabarit qui teste la clé d'un diplôme précis.
- Une valeur affichée dans la maquette différente de celle utilisée par un export ou un calcul.
- Une modification de fiche qui change silencieusement une valeur que l'utilisateur a saisie sur un EC.
- Une perte de données à la migration depuis v2 : chaque drapeau v2 doit se traduire.

## 6. Modèle

### 6.1 Entité `FicheMatiere`

Nouvelle entité Doctrine, hors du JSON des formations.

| Propriété | Type | Rôle |
|---|---|---|
| `id` | int | identifiant ; c'est lui que l'EC référence |
| `libelle` | string | colonne pour lister et rechercher (copie du champ de formulaire du même nom) |
| `formation` | ManyToOne `Formation`, nullable | formation porteuse ; null pour une fiche hors diplôme |
| `parcoursNid` | string, nullable | nœud parcours porteur dans cette formation ; null pour le parcours unique d'une formation sans parcours |
| `horsDiplome` | bool | fiche sans porteur, utilisable par toutes les formations |
| `typeDiplome` | ManyToOne `TypeDiplome`, nullable | type de diplôme d'une fiche hors diplôme (facultatif, comme v2) ; sert à choisir les options actives |
| `data` | JSON | valeurs des champs du formulaire de fiche (§6.3), clé de champ → valeur |
| `defaults` | JSON | valeurs par défaut transmises aux EC : `hours`, `ects`, `mccc`, `competencies` (mêmes formes que les attributs d'EC actuels) |
| `imposed` | JSON | `{hours: bool, ects: bool, mccc: bool}` |
| `etat` | string, nullable | place réservée au cycle de vie (D2) ; jamais lue ni écrite par le prototype |
| `createdAt`, `updatedAt` | datetime | |

Contraintes : soit `horsDiplome` est vrai et `formation` est null, soit `formation` est renseignée.

### 6.2 Entité `FicheMatiereMutualisation`

Un parcours autorisé à utiliser une fiche : `fiche` (ManyToOne), `formation` (ManyToOne), `parcoursNid` (string,
nullable, même convention que §6.1). Unicité sur le triplet. Équivalent de `FicheMatiereMutualisable`.

### 6.3 Formulaire de la fiche

- Un nouveau formulaire socle, type de nœud de famille `Parameter`, clé `param_fiche_matiere`, avec une nouvelle
  portée `FormScope::FicheMatiere`. Il s'édite dans `/node-types/{id}/edit` comme les autres, accepte des variantes,
  des règles d'onglet et des champs ajoutés par l'admin.
- Onglets et champs socles (fixtures), dans l'ordre des fiches d'aide v2 :
  - **Identité** : `libelle` (obligatoire, 250 car.), `libelleEn` (250), `sigle` (250), `referent` (annuaire
    `responsables`), `codeApogee`, `typeEc` (types d'EC de la version, cf. §6.5) ;
  - **Mutualisation** : écran intégré (§8.3), pas de champ ;
  - **Présentation** : `description` (3000), `objectifs` (3000), `languesCours` et `languesSupport` (cases à cocher,
    au moins une, référentiel `langues`), `modaliteEnseignement` (référentiel `modalites_enseignement`) ;
  - **Compétences** : champ de type `competencies`, dans le contexte du porteur (§7.4) ; onglet absent pour une
    fiche hors diplôme ;
  - **Volumes horaires** : champ `hours` (valeur par défaut), case « volumes imposés », volumes mutualisés par
    modalité ;
  - **MCCC** : champ `ects`, case « ECTS imposés », champ `mccc`, case « MCCC imposées », et les options du diplôme
    (`sansNote`, `quitus` avec texte) quand la version les active.
- Les valeurs des champs `hours`, `ects`, `mccc`, `competencies` vont dans `defaults` ; les trois cases d'imposition
  dans `imposed` ; le reste dans `data`.
- **Choix du formulaire par diplôme.** Le canevas d'une version (`CanvasSpec`) gagne une troisième fiche,
  `fiche_matiere`, avec une seule entrée qui désigne le formulaire (socle ou variante). La fiche d'une formation
  utilise le canevas de la version de sa formation porteuse ; une fiche hors diplôme utilise celui de son
  `typeDiplome`, à défaut le formulaire socle. Les options `quitus`, `assiduite`, `sansNote` de la version
  (`VersionConfig::options`) affichent ou masquent les champs correspondants.

### 6.4 Attributs de l'EC

Les attributs actuels de l'EC (`hours`, `ects`, `mccc`, `competencies`) restent ses **valeurs propres**. S'ajoutent :

| Attribut | Forme | Rôle |
|---|---|---|
| `ficheMatiere` | int (id de fiche) | remplace la chaîne du référentiel `fiches_matiere` |
| `sources` | `{hours, ects, mccc, competencies}` → `fiche` \| `ec` \| `parent` \| `porteur` | d'où vient chaque valeur (§7) ; absent = valeur par défaut du §7.2 |
| `libelleLibre` | string, nullable | libellé propre à l'EC (`texteEcLibre` v2) ; sinon le libellé de l'EC est celui de la fiche |
| (dans `mccc`) `sansNote`, `quitus`, `quitusTexte`, `assiduite` ; (dans `hours`) `none` | bool / string | options de l'EC, affichées quand la version les active ; elles suivent la résolution de leur valeur (§7.1) |
| `volumesMutualises` | `{pres: {cm, td, tp}, dist: {cm, td, tp}}` → bool | volumes partagés avec d'autres parcours (v2 `is*Mutualise`) |

Le bloc de choix peut porter `hours` et `mccc` avec un drapeau `enfantsIdentiques: {hours: bool, mccc: bool}` :
c'est la source `parent` de ses EC enfants (v2 `heuresEnfantsIdentiques`, `mcccEnfantsIdentique`).

### 6.5 Type d'EC

La fiche porte un type d'EC facultatif (`typeEc`, v2 `typeMatiere`). Quand il est renseigné, l'EC qui utilise la
fiche prend ce type et ne peut pas le changer ; sinon l'EC garde son attribut `natureElement` actuel. Le type
continue de choisir le jeu MCCC (`VersionConfig::natureOf`).

## 7. Résolution des valeurs d'un EC

### 7.1 Règle

Un service unique, `EcValueResolver`, rend pour un EC et une valeur (`hours`, `ects`, `mccc`, `competencies`) :
la valeur effective, sa source, et si elle est verrouillée.

1. **Imposée** : si la fiche impose la valeur (`imposed.hours`, `imposed.ects`, `imposed.mccc`), la source est
   `fiche`, verrouillée, quoi que dise `sources`.
2. Sinon, la source est celle de `sources`, ou la valeur par défaut (§7.2) :
   - `ec` : la valeur propre de l'EC ;
   - `parent` : la valeur du bloc de choix parent qui a `enfantsIdentiques` pour cette valeur ;
   - `porteur` : la valeur résolue de l'EC du parcours porteur qui utilise la même fiche ;
   - `fiche` : la valeur de `defaults`.
3. Une source qui ne peut pas répondre (pas de fiche, pas de parent concerné, pas d'EC porteur) retombe sur la
   valeur propre de l'EC, et le résolveur le signale (§8.2).

`competencies` n'est jamais imposable (v2 non plus).

Les options rattachées à une valeur suivent sa résolution, comme en v2 :
- `sansNote`, `quitus`, `quitusTexte` et `assiduite` font partie de la valeur `mccc` (rangés dans sa structure) ;
- « sans heures » fait partie de la valeur `hours` (clé `none` existante).
Il n'y a donc pas d'attribut séparé pour ces options : le tableau du §6.4 les décrit comme parties de `mccc` et de
`hours`. Affichage et exports lisent la même valeur résolue.

**Imposition activée après coup.** Cocher « imposé » sur une fiche affiche d'abord les impacts : « N EC ont une
valeur propre qui sera masquée ». La valeur propre de ces EC est conservée ; elle redevient effective si
l'imposition est retirée.

**Source `porteur` avec plusieurs EC.** Si plusieurs EC du parcours porteur utilisent la fiche, la source est le
premier dans l'ordre de l'arbre (comme `GetElementConstitutif::getEcSource` en v2) ; le panneau de l'EC utilisateur
signale qu'il y en a plusieurs.

### 7.2 bis Sources écrites, jamais recalculées en silence

La source par défaut du §7.2 ne s'applique qu'aux valeurs **vides** de l'EC. Chaque fois que le contexte d'un EC
change, le système écrit explicitement `sources: ec` pour toute valeur déjà remplie sur l'EC, afin qu'aucune valeur
saisie ne soit remplacée sans action de l'utilisateur. Contextes concernés :
- choix ou changement de la fiche d'un EC ;
- changement de parcours porteur de sa fiche ;
- entrée de l'EC dans un bloc de choix qui a `enfantsIdentiques`, ou sortie ;
- déplacement, copie ou raccrochage de l'EC vers un autre parcours.

Le panneau signale ces bascules (« Valeur conservée : spécifique à cet EC ») et propose « Reprendre la fiche ».

### 7.2 Source par défaut

- EC dans le parcours porteur de sa fiche : `fiche` pour les quatre valeurs.
- EC dans un autre parcours (fiche mutualisée, hors diplôme, ou portée par un parcours ancêtre, D6) : `fiche` pour `hours`, `ects`, `mccc` ;
  `ec` pour `competencies` (les compétences d'un autre parcours ne sont pas les siennes).
- EC sans fiche : `ec` partout.
- EC enfant d'un bloc de choix qui a `enfantsIdentiques` pour une valeur : `parent` pour cette valeur.

### 7.3 Correspondance avec les drapeaux v2

| v2 | `sources` |
|---|---|
| `heuresSpecifiques`, `ects_specifiques`, `mccc_specifiques` vrai | `ec` pour la valeur |
| `ecParent.heuresEnfantsIdentiques`, `ecParent.mcccEnfantsIdentique` vrai | `parent` |
| `synchroHeures`, `synchroEcts`, `synchroMccc`, `synchroBcc` vrai | `porteur` |
| aucun drapeau | `fiche` (ou `ec` pour un EC sans fiche) |
| `volumesHorairesImpose`, `ectsImpose`, `mcccImpose` | `imposed` de la fiche |

### 7.4 Compétences de la fiche

La fiche choisit ses compétences dans les BCC de son porteur : le parcours porteur, ou la formation pour le parcours
unique, ou le référentiel national quand la formation porteuse en a un (apprentissages critiques). Une fiche hors
diplôme n'a pas de compétences. `bcc_competences` et le type de champ `competencies` prennent un **contexte** (le
porteur) au lieu d'un nœud.

### 7.5 Qui lit les valeurs résolues

Tous les lecteurs actuels des attributs `hours`, `ects`, `mccc`, `competencies` d'un EC passent par le résolveur :
`MaquetteBuilder` (totaux), `Completion`, `McccValidator`, `StructureContextBuilder` (règles de structure),
`QuickGrid`, `McccTableBuilder` et `McccXlsxWriter` (export Excel), `MaquetteExporter` (export JSON), `MaquetteExtension`
(affichage). Les champs `hours` et `mccc` (gabarits `field_type/hours`, `field_type/mccc`) prennent eux aussi un
contexte au lieu d'un nœud, pour servir à la fois au panneau EC et à la fiche.

## 8. Écrans

### 8.1 Fiches d'un parcours

- Nouvelle entrée « Fiches matières » dans la barre latérale du parcours (et de la formation sans parcours) :
  liste des fiches portées, puis des fiches mutualisées vers ce parcours, avec recherche par libellé, nombre d'EC
  qui utilisent chaque fiche, actions Créer, Ouvrir, Dupliquer.
- Page d'une fiche : ses onglets (§6.3), un bandeau « Porteur : … · Utilisée par N EC dans M parcours » avec la
  liste dépliable des EC (formation › parcours › chemin), comme `app_fiche_matiere_detail_parcours`.

### 8.2 Panneau d'un EC

- Champ « Fiche matière » : liste des fiches disponibles pour ce parcours (portées, portées par un parcours
  ancêtre (D6), mutualisées vers lui, hors diplôme), recherche, « Créer une nouvelle fiche » (créée dans ce parcours, libellé repris de l'EC), lien
  « Ouvrir la fiche ».
- Pour chacune des valeurs `hours`, `ects`, `mccc`, `competencies`, un sélecteur de source au-dessus du champ :
  « Reprendre la fiche » / « Spécifique à cet EC », plus « Reprendre l'EC du parcours porteur » si l'EC n'est pas
  dans le parcours porteur, plus « Identique au bloc de choix » sous un bloc concerné.
  - Source autre que `ec` : le champ affiche la valeur effective en lecture seule, avec son origine.
  - Passer à « Spécifique » pré-remplit la valeur propre avec la valeur effective.
  - Valeur imposée : sélecteur désactivé, mention « Imposé par la fiche ».
  - Source impossible (§7.1, point 3) : avertissement sous le champ.
- Le libellé affiché est celui de la fiche ; un champ « Libellé propre à cet EC » le remplace s'il est rempli.
- **Saisie rapide** (`QuickGrid`) : une cellule d'heures ou d'ECTS n'est modifiable que si la valeur vient de l'EC
  (source `ec`). Sinon elle affiche la valeur effective en lecture seule, avec son origine ; on passe en
  « Spécifique » depuis le panneau. La saisie rapide n'écrit jamais dans une fiche.

### 8.3 Mutualisation d'une fiche

Écran intégré de l'onglet Mutualisation (déclaré comme les écrans intégrés existants, `src/Formulaire/Builtin/`) :
- liste des parcours autorisés ; ajout par recherche de formation puis de parcours ; retrait ; « Tout retirer » ;
- avant un retrait : impacts, c'est-à-dire les EC du parcours retiré qui utilisent la fiche (ils gardent le lien,
  signalé en anomalie, jusqu'à ce qu'on change leur fiche) ;
- changement de parcours porteur, avec impacts (EC qui passent de « porteur » à « utilisateur » et inversement,
  compétences de la fiche qui ne valent plus dans le nouveau porteur et qui sont donc retirées après confirmation).

### 8.4 Fiches hors diplôme

Page d'administration `/administration/fiches-matieres` : liste, création, modification, avec le type de diplôme
facultatif. Création et modification réservées à l'admin ; toutes les formations peuvent utiliser ces fiches.

## 9. Règles de cohérence

- **Suppression d'une fiche** : refusée tant qu'un EC l'utilise ; l'écran liste ces EC.
- **Suppression d'un parcours porteur** : ses fiches restent, sans porteur. Elles apparaissent dans une liste
  « Fiches sans porteur » de la formation, d'où l'on choisit un nouveau porteur. Raison : l'historique d'une formation
  (annuler / rétablir, restauration) ne couvre que son JSON ; une fiche ne doit donc jamais disparaître à cause d'une
  action annulable.
- **Suppression d'une formation porteuse** : refusée tant qu'un EC d'une autre formation utilise une de ses fiches
  (l'écran liste ces EC et propose de changer le porteur des fiches concernées) ; sinon ses fiches sont supprimées
  avec elle. `FicheMatiere.formation` est donc en `onDelete: RESTRICT` côté base, la suppression des fiches étant
  faite par le service de suppression de formation.
- **Complétude** : une fiche a son propre statut (pastille), calculé par son formulaire comme les fiches formation
  et parcours. Un EC est incomplet s'il n'a pas de fiche ou si sa fiche est introuvable ; l'état de la fiche ne
  compte pas dans la complétude de l'EC. La page « Vérifier » d'une formation liste en plus les fiches incomplètes
  qu'elle porte. Enregistrer une fiche ne recalcule donc que les statistiques de sa formation porteuse.
- **Fiche introuvable** (EC restauré par l'historique après la suppression autorisée d'une fiche) : l'EC est
  incomplet, le résolveur retombe sur ses valeurs propres et le panneau le signale.
- **Copier / coller, dupliquer un EC** : dans un parcours qui peut utiliser la fiche, l'EC garde la fiche ; sinon
  la fiche est dupliquée dans le parcours cible, qui en devient porteur.
- **Raccrocher un nœud mutualisé** (`NodeController::attach`) : les EC copiés gardent leur fiche, et le parcours
  cible est ajouté aux mutualisations de chaque fiche concernée.
- **Création d'une formation** : la structure de départ crée des EC sans fiche ; ils sont incomplets tant qu'on
  n'en a pas choisi une.
- **Champ `ficheMatiere` obligatoire** pour l'EC, comme aujourd'hui (capacité verrouillée).

## 10. Correspondance avec v2 (migration)

| v2 | Prototype |
|---|---|
| `FicheMatiere` (champs descriptifs) | `FicheMatiere.data` |
| `FicheMatiere.volume*`, `ects`, `mcccs`, `typeMccc`, `competences`, `apprentissagesCritiques` | `FicheMatiere.defaults` |
| `volumesHorairesImpose`, `ectsImpose`, `mcccImpose` | `FicheMatiere.imposed` |
| `FicheMatiere.parcours`, `horsDiplome`, `typeDiplome` | `formation` + `parcoursNid`, `horsDiplome`, `typeDiplome` |
| `FicheMatiereMutualisable` | `FicheMatiereMutualisation` |
| `typeMatiere` | `data.typeEc` |
| `etatFiche`, `etatSteps`, workflow `fiche` | `etat` (réservé) |
| `ElementConstitutif.ficheMatiere` | attribut `ficheMatiere` |
| drapeaux `*Specifiques`, `synchro*`, `*EnfantsIdentiques` | attribut `sources` (§7.3) |
| `texteEcLibre`, `quitus`, `quitusText`, `controleAssiduite`, `sansHeure`, `is*Mutualise` | attributs du §6.4 |

Côté v2, `GetElementConstitutif` est remplacé par l'équivalent d'`EcValueResolver`.

## 11. Contraintes

- Conventions du dépôt (`AGENTS.md`) : attributs PHP, autowiring, contrôleurs minces, Symfony Form pour les
  formulaires serveur, Validator, CSRF sur les nouvelles actions POST.
- Aucune condition sur la clé d'un diplôme, d'un formulaire ou d'un écran intégré hors de sa propre déclaration.
- Pas de migrations dans ce prototype : après le changement de schéma, `make db-reset`, lancé seulement avec
  l'accord de l'utilisateur (efface les données locales).
- Fixtures : formulaire socle `param_fiche_matiere` ; une fiche pour chaque EC des formations de démonstration
  (reprise des 4 valeurs actuelles du référentiel `fiches_matiere`, plus une fiche par EC qui n'en a pas) ; au moins
  une fiche mutualisée entre deux parcours, une fiche hors diplôme, une fiche avec valeurs imposées, un EC en source
  `porteur`. Le référentiel `fiches_matiere` disparaît.
- Tests dans le conteneur : `docker compose exec -T app php bin/phpunit`. Chaque règle du §7 et du §9 a un test,
  et chaque écran un test HTTP.
- `docs/diplomes.md` et `AUDIT.md` mis à jour.

## 12. Laissé au constructeur

- Découpage en lots et ordre de livraison.
- Forme exacte des routes et des gabarits, dans le style existant.
- Mise en cache des résolutions pendant une requête.
- Présentation de la liste des fiches et du bandeau d'usage.

## 13. Choix faits par l'assistant (à vérifier en lisant)

- Source par défaut `ec` pour les compétences d'un EC hors du parcours porteur (§7.2).
- Le type d'EC porté par la fiche s'impose à l'EC (§6.5).
- Suppression d'un parcours porteur : fiches conservées sans porteur plutôt que supprimées ou suppression refusée
  (§9).
- Retrait d'un parcours des mutualisations : les EC gardent le lien, signalé en anomalie (§8.3).
- Copier un EC vers un parcours qui n'a pas accès à la fiche : duplication de la fiche (§9).
- Raccrocher un nœud : mutualisation automatique des fiches vers le parcours cible (§9).
- Fiches hors diplôme créées et modifiées par l'admin seulement (§8.4).
- Le choix du formulaire de fiche passe par une troisième fiche du canevas de version (§6.3).
- Sources écrites explicitement à chaque changement de contexte (§7.2 bis), plutôt que recalculées.
- Options MCCC et « sans heures » rangées dans les valeurs `mccc` et `hours` (§7.1).
- Imposition activée après coup : écran d'impacts, valeurs propres conservées (§7.1).
- Source `porteur` : premier EC dans l'ordre de l'arbre (§7.1).
- Saisie rapide en lecture seule pour les valeurs qui ne viennent pas de l'EC (§8.2).
- Suppression d'une formation porteuse refusée tant que ses fiches servent ailleurs (§9).
- Complétude de la fiche séparée de celle de l'EC (§9).
