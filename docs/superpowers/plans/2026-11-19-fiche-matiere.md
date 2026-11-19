# Fiche matière — plan de construction

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** faire de la fiche matière une entité partagée (porteur, hors diplôme, mutualisation, imposition) et faire
lire à tout le prototype les valeurs résolues de l'EC.

**Architecture :** deux entités Doctrine (`FicheMatiere`, `FicheMatiereMutualisation`) hors du JSON des formations ;
un formulaire socle `param_fiche_matiere` fait avec le constructeur existant ; un résolveur unique
(`EcValueResolver`) qui rend, pour chaque EC et chaque valeur (`hours`, `ects`, `mccc`, `competencies`), la valeur
effective, sa source et son verrou ; tous les lecteurs actuels passent par lui.

**Tech Stack :** Symfony 8.1, PHP 8.5, Doctrine ORM + SQLite, Twig + Stimulus, PHPUnit 13.

**Spec :** `docs/superpowers/specs/2026-11-19-fiche-matiere-design.md` (à lire avant toute tâche ; les § cités
renvoient à elle).

## Global Constraints

- Aucun code, gabarit ou condition ne teste la clé d'un diplôme, d'un formulaire ou d'un écran intégré hors de sa
  propre déclaration.
- Conventions `AGENTS.md` : attributs PHP, autowiring, contrôleurs minces (logique dans des services), Validator,
  CSRF sur toute nouvelle action POST (jeton sans état, comme `config/packages/csrf.yaml` ; ajouter les identifiants
  de jeton nécessaires à `stateless_token_ids`).
- Pas de migrations : schéma créé par `doctrine:schema:create`. `make db-reset` efface les données locales : ne le
  lancer qu'avec l'accord de l'utilisateur (tâche 13).
- Tests : `docker compose exec -T app php bin/phpunit` ; tests HTTP en `WebTestCase` avec le trait
  `App\Tests\Support\ResetsDatabase` et `FormationFactory` ; services en `KernelTestCase`.
- Constantes de source, exactes : `fiche`, `ec`, `parent`, `porteur`. Clés de valeur : `hours`, `ects`, `mccc`,
  `competencies`. Clés d'imposition : `hours`, `ects`, `mccc` (jamais `competencies`).
- Libellés d'interface en français, vocabulaire de la spec (« Reprendre la fiche », « Spécifique à cet EC »,
  « Reprendre l'EC du parcours porteur », « Identique au bloc de choix », « Imposé par la fiche »).
- Chaque tâche se termine par la suite complète au vert et un commit sur la branche `fiche-matiere`.

**Décisions du plan (complètent la spec) :**
- Le « libellé propre à cet EC » (§6.4 `libelleLibre`) est le libellé existant du nœud (`TreeNode::getLabel()`) ;
  le libellé affiché d'un EC est le sien s'il est rempli, sinon celui de sa fiche. Pas d'attribut `libelleLibre`.
- Les volumes mutualisés par modalité (§6.4 `volumesMutualises`) sont rangés dans la valeur `hours` sous la clé
  `mutualise` (`{pres: {cm, td, tp}, dist: {cm, td, tp}}` → bool), pour suivre sa résolution comme les autres
  options (§7.1).

## Review Focus

1. **Fiche introuvable** (EC restauré par l'historique après suppression de sa fiche, ou id inconnu) : aucune page,
   aucun export ne plante ; l'EC est incomplet, ses valeurs propres sont utilisées (tâche 4, tâche 12).
2. **Boucle de source `porteur`** : l'EC du porteur lui-même en source `porteur`, ou deux EC qui se renvoient l'un
   à l'autre : pas de récursion infinie, retour à la valeur propre avec avertissement (tâche 4).
3. **Fiche d'un autre diplôme (D5)** : type de MCCC non autorisé ou type d'EC absent dans la version de l'EC :
   anomalie affichée, export produit quand même (tâche 5, tâche 11).
4. **Enregistrer le panneau d'un EC dont la valeur ne vient pas de lui** : sa valeur propre n'est jamais effacée
   par les champs désactivés non postés (tâche 11).
5. **Parcours retiré de la mutualisation** : les EC concernés gardent leur fiche et l'affichent, avec l'anomalie,
   même si elle n'est plus dans la liste des fiches proposées (tâche 9, tâche 11).

---

### Task 1 : Entités et dépôt

**Files :**
- Create : `src/Entity/FicheMatiere.php`, `src/Entity/FicheMatiereMutualisation.php`,
  `src/Repository/FicheMatiereRepository.php`, `src/Repository/FicheMatiereMutualisationRepository.php`
- Modify : `src/Enum/FormScope.php` (cas `FicheMatiere = 'fiche_matiere'`, libellé « Fiche matière »)
- Test : `tests/FicheMatiere/FicheMatiereRepositoryTest.php`

**Interfaces :**
- Produces :
  - `FicheMatiere` (propriétés du §6.1, getters/setters fluides) ; `FicheMatiere::IMPOSABLE = ['hours', 'ects', 'mccc']` ;
    `getDefault(string $key): mixed`, `setDefault(string $key, mixed $v): self`, `isImposed(string $key): bool`,
    `setImposed(string $key, bool $on): self`, `getDataValue(string $key): mixed` ; `setData(array)` recopie
    `data['libelle']` dans `libelle`.
  - Contrainte de classe (Validator `Callback`) : `horsDiplome` xor `formation !== null`.
  - `FicheMatiere.formation` : `#[ORM\JoinColumn(onDelete: 'RESTRICT')]` (§9).
  - `FicheMatiereMutualisation(FicheMatiere $fiche, Formation $formation, ?string $parcoursNid)`, unicité
    `(fiche, formation, parcoursNid)`.
  - `FicheMatiereRepository::portedBy(Formation $f, ?string $parcoursNid): list<FicheMatiere>` (triées par libellé) ;
    `horsDiplome(): list<FicheMatiere>` ; `mutualisedTo(Formation $f, ?string $parcoursNid): list<FicheMatiere>` ;
    `ofFormation(Formation $f): list<FicheMatiere>` ; `search(string $q, int $limit = 20): list<FicheMatiere>`.

- [ ] **Step 1 : tests**
  - `testPortedByReturnsOnlyThatParcours` : 2 fiches portées par `n17`, 1 par `n27` → `portedBy($f, 'n17')` a 2 éléments.
  - `testMonoParcoursUsesNullNid` : fiche avec `parcoursNid` null → `portedBy($f, null)` la rend.
  - `testHorsDiplomeWithFormationIsInvalid` : `horsDiplome=true` + formation → 1 violation du Validator.
  - `testMutualisedTo` : mutualisation vers `(f2, 'n5')` → `mutualisedTo($f2, 'n5')` contient la fiche, `mutualisedTo($f2, 'n6')` non.
  - `testImposedNeverCompetencies` : `setImposed('competencies', true)` lève `\InvalidArgumentException`.
- [ ] **Step 2 :** lancer `docker compose exec -T app php bin/phpunit tests/FicheMatiere/FicheMatiereRepositoryTest.php` → FAIL (classes absentes).
- [ ] **Step 3 :** implémenter les entités, dépôts et le cas d'enum.
- [ ] **Step 4 :** relancer le test → PASS ; suite complète → PASS.
- [ ] **Step 5 :** commit `Fiche matière : entités et dépôt`.

### Task 2 : Accès aux fiches depuis un EC

**Files :**
- Create : `src/FicheMatiere/FicheMatiereAccess.php`, `src/FicheMatiere/FicheRelation.php`
- Test : `tests/FicheMatiere/FicheMatiereAccessTest.php`

**Interfaces :**
- Consumes : dépôts de la tâche 1 ; `TreeNode::getParent()`, `isParcours()`, `getParcoursParent()`, `getFormation()`.
- Produces :
  - `enum FicheRelation: string { Porteur = 'porteur'; Ancetre = 'ancetre'; Mutualisee = 'mutualisee'; HorsDiplome = 'hors_diplome'; Interdite = 'interdite' }`
  - `FicheMatiereAccess::parcoursOf(TreeNode $node): ?TreeNode` (premier ancêtre parcours, null pour le parcours unique)
  - `FicheMatiereAccess::relation(FicheMatiere $fiche, TreeNode $ec): FicheRelation` (D6 : un parcours ancêtre via
    `getParcoursParent()`, récursivement, donne `Ancetre`)
  - `FicheMatiereAccess::available(TreeNode $ec): list<FicheMatiere>` dans l'ordre : portées, portées par un ancêtre,
    mutualisées vers ce parcours, hors diplôme ; sans doublon.
  - `FicheMatiereAccess::isPorteurOf(FicheMatiere $fiche, TreeNode $ec): bool` (relation `Porteur`)

- [ ] **Step 1 : tests** (formation de test `FormationFactory::fromType('L', 3, avecParcours: true, withLeaves: true)`, ramification portail › parcours posée par `setParcoursParent`)
  - `testRelationPorteur`, `testRelationAncetre` (fiche du portail, EC du sous-parcours → `Ancetre`),
    `testRelationMutualisee`, `testRelationHorsDiplome`, `testRelationInterdite` (fiche d'un parcours frère).
  - `testAvailableOrderAndNoDuplicate` : une fiche portée et aussi mutualisée vers le même parcours n'apparaît qu'une fois, en tête.
  - `testMonoParcoursEc` : formation sans parcours, fiche `parcoursNid` null → `Porteur`.
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** test → PASS ; suite → PASS.
- [ ] **Step 5 :** commit `Fiche matière : fiches accessibles depuis un EC`.

### Task 3 : Lecture des valeurs postées (heures, MCCC)

Les lectures `readHours` / `readMccc` de `NodeController` (l. 712-760) servent aussi à la fiche : on les sort dans un
service, et on y ajoute les options rangées dans ces valeurs (§7.1).

**Files :**
- Create : `src/Maquette/AttributeReader.php`
- Modify : `src/Controller/NodeController.php` (utilise le service ; supprime les deux méthodes privées)
- Modify : `templates/field_type/hours.html.twig` (cases « mutualisé » par modalité), `templates/field_type/mccc.html.twig`
  (cases `sansNote`, `assiduite`, `quitus` + zone `quitusTexte`, affichées selon une variable `options`
  `{quitus: bool, assiduite: bool, sansNote: bool}` passée au gabarit)
- Test : `tests/Maquette/AttributeReaderTest.php`

**Interfaces :**
- Produces :
  - `AttributeReader::hours(array $raw): array` → même forme qu'aujourd'hui, plus `mutualise` (seules les cases cochées, en `true`) et `none`.
  - `AttributeReader::mccc(array $raw): array` → `{type, evaluations, sansNote?, assiduite?, quitus?, quitusTexte?}` ; `quitusTexte` gardé seulement si `quitus`.
  - `AttributeReader::number(mixed $v): ?float` (remplace `numOrNull`).

- [ ] **Step 1 : tests** : `testHoursKeepsMutualiseFlags` (`['pres' => ['cm' => '12'], 'mutualise' => ['pres' => ['cm' => '1']]]` → `['pres' => ['cm' => 12.0], 'mutualise' => ['pres' => ['cm' => true]]]`), `testMcccDropsQuitusTextWithoutQuitus`, `testMcccKeepsOptions`, `testEmptyRowsIgnored` (ligne d'épreuve sans type ni poids ignorée, comme aujourd'hui).
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter, brancher dans `NodeController`, ajouter les cases aux gabarits.
- [ ] **Step 4 :** test → PASS ; suite complète → PASS (les tests existants de `NodeController` couvrent la non-régression).
- [ ] **Step 5 :** commit `Maquette : lecture des heures et MCCC dans un service`.

### Task 4 : Résolveur des valeurs d'un EC

**Files :**
- Create : `src/FicheMatiere/EcValueResolver.php`, `src/FicheMatiere/ResolvedValue.php`
- Test : `tests/FicheMatiere/EcValueResolverTest.php`

**Interfaces :**
- Consumes : `FicheMatiereAccess` (tâche 2), `FicheMatiereRepository::find`, `Maquette::open` (pour l'EC du porteur dans une autre formation).
- Produces :
  - `final readonly class ResolvedValue { mixed $value; string $source; bool $locked; ?string $warning; }`
  - `EcValueResolver::SOURCES = ['fiche', 'ec', 'parent', 'porteur']`, `EcValueResolver::KEYS = ['hours', 'ects', 'mccc', 'competencies']`
  - `EcValueResolver::resolve(TreeNode $ec, string $key): ResolvedValue`
  - `EcValueResolver::value(TreeNode $node, string $key): mixed` (raccourci ; pour un nœud qui n'est pas le niveau élément, rend `getAttribute($key)`)
  - `EcValueResolver::fiche(TreeNode $ec): ?FicheMatiere` (null si pas d'attribut `ficheMatiere` ou id introuvable)
  - `EcValueResolver::defaultSource(TreeNode $ec, string $key): string` (§7.2, D6)
  - `EcValueResolver::porteurEc(TreeNode $ec): ?TreeNode` (premier EC du porteur, dans l'ordre de l'arbre, qui a la même fiche ; null si l'EC est lui-même chez le porteur)
  - `EcValueResolver::label(TreeNode $ec): string` (libellé propre, sinon libellé de la fiche)
  - implémente `ResetInterface` (cache par requête des fiches et des documents ouverts)
  - Le niveau élément se reconnaît par `$node->getType()->isElementLevel()`, jamais par la clé `ec`.

- [ ] **Step 1 : tests**, un par règle :
  - `testImposedWinsOverSource` : fiche `imposed.ects=true`, `defaults.ects=6`, EC `sources.ects='ec'`, `ects=3` → value 6, source `fiche`, locked true.
  - `testDefaultSourceInPorteur` : EC chez le porteur, pas de `sources` → `fiche` pour les 4 clés.
  - `testDefaultSourceElsewhereCompetenciesFromEc` : EC dans un parcours mutualisé → `hours` source `fiche`, `competencies` source `ec`.
  - `testDefaultSourceAncestorParcours` (D6) : même résultat pour un EC de sous-parcours.
  - `testParentSource` : EC sous un bloc de choix `enfantsIdentiques.hours=true`, `hours` du bloc → valeur du bloc, source `parent`.
  - `testPorteurSource` : EC utilisateur `sources.mccc='porteur'` → MCCC de l'EC porteur (résolue elle aussi).
  - `testPorteurFirstInTreeOrderWithWarning` : deux EC du porteur avec la fiche → le premier ; `warning` non null.
  - `testPorteurLoopFallsBack` : l'EC du porteur lui-même en source `porteur` → valeur propre, `warning` non null, pas de récursion.
  - `testMissingFicheFallsBackToOwnValue` : `ficheMatiere = 999` → `fiche()` null, valeur propre, `warning` non null.
  - `testNoFicheMeansEc` : EC sans fiche → source `ec`.
  - `testLabelFallsBackToFiche` : libellé du nœud vide → libellé de la fiche.
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter. Ordre exact : imposition (§7.1 point 1) → `sources[$key] ?? defaultSource()` → valeur de la source → si impossible, valeur propre + `warning`. Protection de boucle : pile des EC en cours de résolution.
- [ ] **Step 4 :** test → PASS ; suite → PASS.
- [ ] **Step 5 :** commit `Fiche matière : résolution des valeurs d'un EC`.

### Task 5 : Tous les lecteurs passent par le résolveur

**Files (Modify) :** `src/Maquette/MaquetteBuilder.php:102-103`, `src/Maquette/Completion.php` (`missing()`),
`src/Maquette/McccValidator.php:44`, `src/Diplome/Check/StructureContextBuilder.php:171-172`,
`src/Maquette/QuickGrid.php:94` (lecture seulement, l'écriture est en tâche 11),
`src/Export/Mccc/McccTableBuilder.php:258-275`, `src/Maquette/MaquetteExporter.php` (`enriched()` : valeurs résolues
et `sources`), `src/Twig/MaquetteExtension.php` (`node_display_label` → `EcValueResolver::label` pour le niveau
élément ; nouvelles fonctions `ec_value(TreeNode, string): ResolvedValue` et `ec_fiche(TreeNode): ?FicheMatiere`).
- Test : `tests/FicheMatiere/ResolvedReadersTest.php`

**Interfaces :**
- Consumes : `EcValueResolver::value()`, `resolve()`, `label()` (tâche 4).
- Produces : `Completion::missing()` ajoute `['key' => 'ficheMatiere', 'label' => 'fiche matière introuvable', 'tab' => 'props']` quand l'attribut est rempli mais `fiche()` null ; ajoute une anomalie `['key' => 'mccc', 'label' => 'type de MCCC non autorisé par le diplôme', 'tab' => 'mccc']` quand le type résolu n'est pas dans `VersionMccc::typesFor($ec)` (D5).

- [ ] **Step 1 : tests** : un EC sans valeur propre, fiche `defaults` = `hours.pres.cm=10`, `ects=4`, `mccc` CT 100 % :
  - `testBuilderTotalsUseFicheValues` (total ECTS de l'UE = 4, heures = 10) ;
  - `testCompletionCountsFicheValuesAsProvided` (pas de `hours`/`ects`/`mccc` manquants) ;
  - `testMcccExportUsesResolvedValues` (ligne de l'EC dans `McccTableBuilder::build()` : ects 4, type CT) ;
  - `testJsonExportCarriesSources` (`enriched()` : `sources.ects = 'fiche'`) ;
  - `testMcccTypeNotAllowedIsAnAnomaly` (D5 : type de MCCC de la fiche absent de la version de l'EC → entrée dans `missing()`, export toujours produit) ;
  - `testMissingFicheIsReported`.
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** remplacer chaque lecture d'attribut par `EcValueResolver::value()` ; `McccValidator::forNode` lit la MCCC résolue.
- [ ] **Step 4 :** test → PASS ; suite → PASS.
- [ ] **Step 5 :** commit `Maquette : valeurs résolues partout`.

### Task 6 : Formulaire socle et choix du formulaire par diplôme

**Files :**
- Modify : `src/DataFixtures/FieldDefFixtures.php`, `src/DataFixtures/NodeTypeFixtures.php`
- Modify : `src/Diplome/Spec/CanvasSpec.php` (`FICHE_MATIERE = 'fiche_matiere'`, ajouté à `FICHES` ; propriété
  `$ficheMatiere`, `entries()`, `withEntries()`, `fromArray()`, `toArray()`), `src/Formulaire/CanvasResolver.php`
  (canevas par défaut : entrée unique `fiche_matiere` → formulaire de portée `FicheMatiere`)
- Create : `src/FicheMatiere/FicheMatiereForms.php`
- Modify : `src/Twig/MaquetteExtension.php` : `bcc_competences`, `mccc_types_for`, `mccc_results` acceptent
  `TreeNode|FicheMatiere` ; `src/Diplome/VersionMccc.php` : `typesForFiche(FicheMatiere): ?array`,
  `profileForFiche(FicheMatiere, string): ?string` ; `templates/field_type/competencies.html.twig`, `hours.html.twig`,
  `mccc.html.twig` utilisent une variable `subject` (nœud ou fiche) au lieu de `node`.
- Test : `tests/FicheMatiere/FicheMatiereFormsTest.php`

**Champs socles** (`FieldDef`, onglet = tab ; nouvelles clés sauf mention) :
- `identite` : `libelle` (text, obligatoire, max 250), `libelleEn` (text, 250), `sigle` (existant, réutilisé),
  `referent` (choice, référentiel `responsables`, allowExtra), `codeApogeeFiche` (text), `typeEc` (choice,
  `optionsSource` `version_natures`, facultatif)
- `presentation` : `description` (textarea, 3000), `objectifsEnseignement` (textarea, 3000), `languesCours` et
  `languesSupport` (checkbox, référentiel `langues`, obligatoires), `modaliteEnseignement` (choice, référentiel
  `modalites_enseignement`)
- `competences` : `competencies` (existant)
- `volume_horaire` : `hours` (existant), `volumesImposes` (radio oui/non)
- `mccc` : `ects` (existant), `ectsImposes` (radio oui/non), `mccc` (existant), `mcccImposes` (radio oui/non)
- Type de nœud `param_fiche_matiere` : famille `Parameter`, portée `FormScope::FicheMatiere`, `defaultEntryKey`
  `fiche_matiere`, système, capacités = ces clés.

**Interfaces :**
- Produces :
  - `FicheMatiereForms::formFor(FicheMatiere $fiche): NodeType` (version de la formation porteuse via
    `TypeDiplomeResolver::versionFor` ; hors diplôme : dernière version du `typeDiplome` ; sinon le socle)
  - `FicheMatiereForms::configFor(FicheMatiere $fiche): ?VersionConfig` (même règle)
  - `FicheMatiereForms::fields(FicheMatiere $fiche): list<ResolvedField>` (sans l'onglet `competences` pour une fiche hors diplôme)
  - `FicheMatiereForms::options(FicheMatiere $fiche): array{quitus: bool, assiduite: bool, sansNote: bool}`
  - `FicheMatiereForms::TABS = ['identite', 'mutualisation', 'presentation', 'competences', 'volume_horaire', 'mccc']`

- [ ] **Step 1 : tests** : `testDefaultFormIsSocle`, `testVersionCanvasPicksVariant` (variante posée dans le canevas `fiche_matiere` d'une version → `formFor` la rend pour une fiche de cette formation), `testHorsDiplomeHasNoCompetencesTab`, `testCanvasSpecRoundTrip` (`fromArray(toArray())` garde l'entrée `fiche_matiere`), `testOptionsFollowVersion`.
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter ; vérifier `bin/console lint:twig templates/`.
- [ ] **Step 4 :** test → PASS ; suite → PASS.
- [ ] **Step 5 :** commit `Fiche matière : formulaire socle et choix par diplôme`.

### Task 7 : Page d'une fiche

**Files :**
- Create : `src/Controller/FicheMatiereController.php`, `src/FicheMatiere/FicheMatiereEditor.php`,
  `src/FicheMatiere/FicheMatiereUsage.php`, `templates/fiche_matiere/edit.html.twig`, `templates/fiche_matiere/_usage.html.twig`
- Test : `tests/Controller/FicheMatiereEditTest.php`

**Routes :**
- `GET /fiches-matieres/{id}/{tab}` `fiche_matiere_edit` (tab parmi `FicheMatiereForms::TABS`, défaut `identite`)
- `POST /fiches-matieres/{id}/{tab}` `fiche_matiere_save` (réponse JSON `{status, issues}` si `Accept: application/json`, comme `FicheEntryController::save`)

**Interfaces :**
- Consumes : `FicheMatiereForms` (tâche 6), `AttributeReader` (tâche 3), `EcValueResolver` (tâche 4).
- Produces :
  - `FicheMatiereEditor::save(FicheMatiere $fiche, string $tab, array $posted, bool $confirmImpact): SaveResult`
    — `hours`/`ects`/`mccc`/`competencies` → `defaults` ; `volumesImposes`/`ectsImposes`/`mcccImposes` → `imposed` ;
    le reste → `data`. Seuls les champs de l'onglet sont réécrits.
  - `final readonly class SaveResult { bool $saved; list<array{formation: string, path: string, key: string}> $impacts; }`
    — passage d'une imposition de faux à vrai avec des EC en source `ec` sur cette clé et sans `$confirmImpact` :
    `saved=false`, impacts listés (§7.1).
  - `FicheMatiereUsage::usages(FicheMatiere $fiche): list<array{formation: Formation, ec: TreeNode, path: string, relation: FicheRelation}>`
  - `FicheMatiereUsage::status(FicheMatiere $fiche): string` (`ok` | `incomplete`, champs obligatoires du formulaire)
- Accès : fiche hors diplôme modifiable par l'admin seulement (`RoleExtension::isAdmin()`), sinon 403.

- [ ] **Step 1 : tests** : `testEditShowsTabs` (6 onglets, `competences` absent en hors diplôme), `testSaveRoutesValues` (POST onglet `mccc` : `ects=3` → `defaults.ects=3`, `ectsImposes=oui` → `imposed.ects=true`, `data` inchangé), `testImposingWithImpactsAsksConfirmation` (un EC en `sources.ects='ec'` → pas d'enregistrement, réponse liste l'EC ; avec `confirmImpact=1` → enregistré, valeur propre de l'EC conservée), `testUsageBanner` (2 EC dans 2 parcours → « Utilisée par 2 EC dans 2 parcours »), `testHorsDiplomeForbiddenToResponsable`, `testLibelleTooLongRejected` (251 caractères → erreur sur `libelle`).
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter (contrôleur mince ; rendu des champs avec `formation/param/_fields.html.twig` et `subject` = la fiche).
- [ ] **Step 4 :** test → PASS ; suite → PASS.
- [ ] **Step 5 :** commit `Fiche matière : page de saisie`.

### Task 8 : Fiches d'un parcours

**Files :**
- Create : `src/Formulaire/Builtin/FichesMatieresScreen.php` (fiche `parcours`, non masquable), `templates/fiche_matiere/index.html.twig`
- Modify : `src/Controller/FicheMatiereController.php`
- Test : `tests/Controller/FicheMatiereIndexTest.php`

**Routes :**
- `GET /formations/{fid}/fiches-matieres` `fiche_matiere_index` (query `parcours` = nid, absent pour le parcours unique) : fiches portées, puis fiches sans porteur de la formation (§9), puis mutualisées vers ce parcours ; recherche `q` ; nombre d'EC par fiche.
- `POST /formations/{fid}/fiches-matieres` `fiche_matiere_create` (`parcours`, `libelle`)
- `POST /fiches-matieres/{id}/dupliquer` `fiche_matiere_duplicate` (copie `data`, `defaults`, `imposed`, même porteur, libellé suffixé « (copie) », sans mutualisations)
- `POST /fiches-matieres/{id}/supprimer` `fiche_matiere_delete` (refus avec la liste des EC si `usages()` non vide)
- `POST /fiches-matieres/{id}/porteur` `fiche_matiere_porteur` pour rattacher une fiche sans porteur (même service que la tâche 9)

**Interfaces :**
- Produces : `FicheMatiereRepository::withoutPorteur(Formation $f, list<?string> $existingParcoursNids): list<FicheMatiere>`

- [ ] **Step 1 : tests** : `testIndexListsPortedThenMutualised`, `testSearchFiltersByLibelle`, `testCreate`, `testDuplicate`, `testDeleteRefusedWhenUsed`, `testDeleteWhenUnused`, `testOrphanListedAfterParcoursDeletion` (supprimer le parcours porteur via `node_delete` → la fiche existe toujours et apparaît dans la liste « Fiches sans porteur »), `testScreenInParcoursSidebar`.
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** test → PASS ; suite → PASS.
- [ ] **Step 5 :** commit `Fiche matière : fiches d'un parcours`.

### Task 9 : Mutualisation et changement de porteur

**Files :**
- Create : `src/FicheMatiere/FicheMatiereMutualiser.php`, `src/FicheMatiere/SourcePinner.php`, `templates/fiche_matiere/mutualisation.html.twig`
- Modify : `src/Controller/FicheMatiereController.php` (onglet `mutualisation`)
- Test : `tests/Controller/FicheMatiereMutualisationTest.php`, `tests/FicheMatiere/SourcePinnerTest.php`

**Routes :** `POST /fiches-matieres/{id}/mutualisation` `fiche_matiere_mutualisation_add` (`formation`, `parcours`) ;
`POST /fiches-matieres/{id}/mutualisation/{mid}/retirer` `fiche_matiere_mutualisation_remove` ;
`POST /fiches-matieres/{id}/mutualisation/tout-retirer` `fiche_matiere_mutualisation_clear` ;
`POST /fiches-matieres/{id}/porteur` `fiche_matiere_porteur` (`formation`, `parcours`, `confirmImpact`).
Chaque retrait et le changement de porteur affichent d'abord les impacts et attendent `confirmImpact=1`.

**Interfaces :**
- Produces :
  - `SourcePinner::pinFilled(TreeNode $ec, ?callable $defaultSource = null): bool` — pour chaque clé de
    `EcValueResolver::KEYS` dont la valeur propre est remplie et sans `sources[$key]` explicite, écrit
    `sources[$key] = 'ec'` ; rend vrai si quelque chose a changé (§7.2 bis).
  - `FicheMatiereMutualiser::add(FicheMatiere, Formation, ?string): void`, `remove(FicheMatiereMutualisation): void`,
    `clear(FicheMatiere): void`, `impactsOfRemoval(FicheMatiereMutualisation): list<array{formation: Formation, ec: TreeNode, path: string}>`,
    `changePorteur(FicheMatiere, Formation, ?string, bool $confirm): SaveResult` (avant le changement : `pinFilled` sur
    chaque EC utilisateur de toutes les formations, enregistrées par `Maquette::save` ; compétences de la fiche
    hors BCC du nouveau porteur retirées et listées dans les impacts).

- [ ] **Step 1 : tests** : `testAddAndRemove`, `testClear`, `testRemovalImpactsListedAndLinkKept` (après retrait, l'EC garde `ficheMatiere` ; `Completion::missing()` signale la fiche non autorisée), `testChangePorteurPinsFilledValues` (EC utilisateur avec `ects=2` propre et sans `sources` → après changement, `sources.ects='ec'`, valeur effective toujours 2), `testChangePorteurDropsForeignCompetencies`, `testPinFilledIgnoresEmptyValues`.
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter ; `Completion::missing()` gagne l'anomalie `fiche matière non autorisée dans ce parcours` quand `FicheMatiereAccess::relation()` rend `Interdite`.
- [ ] **Step 4 :** test → PASS ; suite → PASS.
- [ ] **Step 5 :** commit `Fiche matière : mutualisation et porteur`.

### Task 10 : Fiches hors diplôme

**Files :**
- Create : `templates/admin/fiches_matieres.html.twig`
- Modify : `src/Controller/FicheMatiereController.php`, `templates/admin/_layout.html.twig` (entrée de menu), `src/Controller/AdminController.php` (tuile de l'index)
- Test : `tests/Controller/FicheMatiereHorsDiplomeTest.php`

**Routes :** `GET /administration/fiches-matieres` `admin_fiches_matieres` ; `POST /administration/fiches-matieres`
`admin_fiche_matiere_create` (`libelle`, `typeDiplome` facultatif). Le préfixe `admin_` suffit à réserver ces routes
à l'admin (`AdminAccessSubscriber`).

- [ ] **Step 1 : tests** : `testAdminListsAndCreates`, `testResponsableRedirected`, `testHorsDiplomeAvailableEverywhere` (la fiche apparaît dans `FicheMatiereAccess::available()` d'un EC de chaque formation de démonstration).
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** test → PASS ; suite → PASS.
- [ ] **Step 5 :** commit `Fiche matière : fiches hors diplôme`.

### Task 11 : Panneau de l'EC et saisie rapide

**Files :**
- Create : `src/FieldType/Options/FichesMatiereSource.php` (clé `fiches_matiere` : options = `available()` du nœud, plus la fiche actuelle si elle n'y est pas, libellé suffixé « (non autorisée) ») ; `templates/node/_value_source.html.twig`
- Modify : `templates/node/panel.html.twig` (sélecteur de source au-dessus de `hours`, `ects`, `mccc`, `competencies` ; lien « Ouvrir la fiche » ; avertissements `ResolvedValue::warning`), `src/Controller/NodeController.php` (`save()`), `src/Maquette/QuickGrid.php` (`rows()` marque les cellules non modifiables ; `apply()` refuse d'écrire une valeur dont la source n'est pas `ec`), `templates/formation/quick_grid.html.twig`
- Modify : `src/DataFixtures/FieldDefFixtures.php` : `ficheMatiere` passe de `referentielKey: fiches_matiere` à `optionsSource: fiches_matiere`, `allowExtra` gardé (« Créer une nouvelle fiche »)
- Test : `tests/Controller/EcPanelFicheTest.php`, `tests/Controller/QuickGridTest.php` (ajouts)

**Comportement de `NodeController::save()`** (les noms postés sont `sources[hours|ects|mccc|competencies]`) :
1. `attr_ficheMatiere` = id d'une fiche accessible → l'écrit (int) ; `attr_ficheMatiere_autre` rempli → crée la
   fiche dans le parcours de l'EC (porteur), libellé = valeur saisie, et l'écrit. Fiche changée → `SourcePinner::pinFilled()`.
2. Pour chaque clé de `EcValueResolver::KEYS` : source postée enregistrée dans `sources` (absente si égale à
   `defaultSource()`) ; la valeur propre n'est relue depuis la requête que si la source résultante est `ec` et la
   valeur non imposée ; sinon l'attribut existant est conservé tel quel.
3. Type d'EC (§6.5, D5) : si la fiche a un `typeEc` présent dans les natures de la version de l'EC, `natureElement`
   reçoit cette valeur et le champ est désactivé ; sinon l'EC garde le sien et `Completion` signale l'écart.

**Interfaces :**
- Consumes : `EcValueResolver`, `FicheMatiereAccess`, `SourcePinner`, `AttributeReader`.

- [ ] **Step 1 : tests** :
  - `testFicheChoicesAreAvailableOnes` (fiche d'un parcours frère absente des options) ;
  - `testCreateFicheFromPanel` (`attr_ficheMatiere_autre=Nouvelle` → fiche portée par le parcours de l'EC, EC relié) ;
  - `testSourceSelectorSwitchesToEcAndPrefills` (GET : valeur effective dans les champs, désactivés ; POST `sources[ects]=ec` + `attr_ects=5` → `ects=5`, `sources.ects='ec'`) ;
  - `testSavingWithFicheSourceKeepsOwnValue` (EC `ects=5` propre, source `fiche`, POST sans `attr_ects` → `ects` toujours 5) ;
  - `testImposedSelectorDisabled` (« Imposé par la fiche » présent, POST `sources[ects]=ec` ignoré) ;
  - `testTypeEcFromFiche` et `testTypeEcAbsentInVersionKeepsOwn` (D5) ;
  - `testCurrentFicheShownEvenIfNotAuthorised` ;
  - `testPinnedValueShowsKeptMessage` (EC avec `ects` propre, choix d'une fiche → GET affiche « Valeur conservée : spécifique à cet EC » et le bouton « Reprendre la fiche », §7.2 bis) ;
  - `QuickGridTest::testCellReadOnlyWhenNotFromEc` et `testApplyRefusesNonEcSource`.
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** test → PASS ; suite → PASS.
- [ ] **Step 5 :** commit `Fiche matière : panneau de l'EC et saisie rapide`.

### Task 12 : Cohérence (blocs, copies, raccrochage, suppressions)

**Files :**
- Modify : `src/DataFixtures/NodeTypeFixtures.php` (bloc de choix : capacités `hours`, `mccc` ; attribut
  `enfantsIdentiques` saisi par deux cases du panneau), `templates/node/panel.html.twig`,
  `src/Controller/NodeController.php` (`move`, `duplicate`, `paste`, `attach`, `save` d'un bloc), `src/Controller/FormationController.php` (`delete`)
- Create : `src/FicheMatiere/FicheMatiereLifecycle.php`
- Test : `tests/FicheMatiere/CoherenceTest.php`

**Interfaces :**
- Produces :
  - `FicheMatiereLifecycle::afterMove(TreeNode $ec, ?TreeNode $oldParcours): void` (parcours changé → `pinFilled`)
  - `FicheMatiereLifecycle::afterCopy(TreeNode $copy): void` (fiche non accessible dans le parcours cible → duplique la fiche, porteur = parcours cible, et relie la copie ; puis `pinFilled`)
  - `FicheMatiereLifecycle::afterAttach(TreeNode $root, Formation $target): void` (ajoute le parcours cible aux mutualisations de chaque fiche des EC copiés)
  - `FicheMatiereLifecycle::afterBlocChange(TreeNode $bloc): void` (`enfantsIdentiques` modifié → `pinFilled` sur ses EC enfants)
  - `FicheMatiereLifecycle::formationDeletionBlockers(Formation $f): list<array{fiche: FicheMatiere, formation: Formation, path: string}>` ; `deleteFichesOf(Formation $f): void`

- [ ] **Step 1 : tests** : `testParentSourceWithEnfantsIdentiques`, `testEnteringBlocPinsFilledValues`, `testMoveToOtherParcoursPins`, `testPasteToUnauthorisedParcoursDuplicatesFiche`, `testPasteInSameParcoursKeepsFiche`, `testAttachMutualisesFiches`, `testFormationDeleteRefusedWhenFicheUsedElsewhere`, `testFormationDeleteRemovesItsFiches`, `testUndoAfterFicheDeletionDoesNotCrash` (supprimer une fiche non utilisée après avoir retiré son EC, puis `formation_undo` → éditeur 200, EC incomplet « fiche matière introuvable »).
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** test → PASS ; suite → PASS.
- [ ] **Step 5 :** commit `Fiche matière : cohérence des actions sur la maquette`.

### Task 13 : Données de démonstration, page Vérifier, documentation

**Files :**
- Modify : `src/DataFixtures/FormationFixtures.php`, `src/DataFixtures/ReferentielFixtures.php` (retrait de `fiches_matiere`),
  `src/Controller/FormationController.php` (`check()` : fiches incomplètes portées par la formation, via `FicheMatiereUsage::status`),
  `templates/formation/check.html.twig`, `docs/diplomes.md`, `AUDIT.md`, `README.md` (ligne « fiche matière »)
- Create : `src/DataFixtures/FicheMatiereFixtures.php` (dépend de `FormationFixtures`)
- Test : `tests/FicheMatiere/DemoFichesTest.php`, `tests/Controller/CheckPageTest.php` (ajout)

**Données (§11) :** une fiche par EC de démonstration (les 4 libellés de l'ancien référentiel réutilisés là où ils
servaient) ; au moins une fiche mutualisée entre deux parcours de la Licence Sciences & Technologies ; une fiche hors
diplôme « Anglais » ; une fiche avec `imposed.mccc` ; un EC en `sources.mccc='porteur'`.

- [ ] **Step 1 : tests** : `testEveryDemoEcHasAFiche`, `testDemoCoversEveryCase` (mutualisée, hors diplôme, imposée, porteur présents), `CheckPageTest::testListsIncompleteFiches`.
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter ; mettre à jour la documentation (`docs/diplomes.md` « Où est quoi », `AUDIT.md` §0 et §2 P0 fiche matière et héritage passés en « fait »).
- [ ] **Step 4 :** suite complète → PASS ; `bin/console lint:container`, `lint:twig templates/`, `lint:yaml config/` → OK.
- [ ] **Step 5 :** commit `Fiche matière : démonstration, vérification et documentation`.
- [ ] **Step 6 :** demander à l'utilisateur l'accord pour `make db-reset` (efface les données locales), puis vérifier dans l'application : page d'une fiche, liste d'un parcours, panneau d'un EC avec sélecteur de source, page hors diplôme.
