# Onglet Structure en arbre — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** remplacer le tableau de l'onglet Structure par un arbre épuré + panneau de réglages, avec durée sur un niveau temporel choisi, statut verrouillé / modifiable par niveau, EC toujours en dernier, types d'EC en étiquettes, et sans éléments imposés.

**Architecture:** le modèle (`App\Diplome\Spec`) gagne `DurationSpec`, `EcType`, `LevelSpec::locked` et `LevelSpec::ecLink` ; le contrôle (`StructureChecker`, `StructureGuard`) apprend la durée sur un niveau descendant, le verrou de niveau et la portée positionnelle des règles ; les éléments imposés et leur propagation sont supprimés ; l'écran est réécrit sur le même formulaire Symfony (`StructureType`) avec un nouveau contrôleur Stimulus.

**Tech Stack:** Symfony (Form, Validator, Twig), Doctrine ORM sur SQLite (pas de migrations, `make db-reset`), Stimulus, Tailwind (CDN), PHPUnit (`WebTestCase` / `KernelTestCase`, base `var/test.db`).

**Spec:** `docs/superpowers/specs/2026-10-22-structure-arbre-design.md` (s'appuie sur `docs/superpowers/specs/2026-10-20-creation-diplome-design.md`, §10 pour les arbitrages A1–A19).

## Global Constraints

- Conventions `AGENTS.md` : attributs PHP, autowiring, contrôleurs fins, Form + Validator, constructeur promu, `readonly` pour les objets de valeur.
- Aucune condition, gabarit ou constante ne teste une clé de diplôme ou de type de nœud (`'ec'`, `'ue'`, `'but'`, `'stage'`…) ; le niveau élément se reconnaît par `NodeType::isElementLevel()`.
- CSRF et rôle admin sur toute action (A17) ; les mêmes classes servent à l'assistant et à l'édition (A11).
- Pas de migrations : un changement de schéma impose `make db-reset`, **jamais lancé sans l'accord de l'utilisateur** (A14/A19). Les tests utilisent `ResetsDatabase` et ne touchent pas `var/proto.db`.
- Tests : `docker compose exec app php bin/phpunit <chemin>` ; toute la suite doit passer à la fin de chaque tâche.
- Messages de commit en français, préfixe de zone (« Structure : … »), terminés par `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Textes d'interface en français, dans `translations/diplome.fr.yaml` (domaine `diplome`).

## Review Focus

1. Ancienne config enregistrée (versions, brouillons `WizardDraft`) avec `duration` = `{min,max}` et `natures` = liste de clés : doit se charger sans erreur (Task 1, test `testLegacyShapeLoads`).
2. Durée non divisible (« 5 semestres » avec 2 semestres par année) ou niveau intermédiaire non « exactement » : refus à l'enregistrement avec message clair, pas d'exception (Task 2, `testDurationNotDivisibleIsRefused`).
3. Niveau verrouillé : supprimer puis recréer un nœud (nouveau `nid`) doit être refusé, alors que renommer ou changer ses heures passe (Task 4, `testRecreatedNodeIsRefused`, `testRenameIsAllowed`).
4. Suppression d'un type d'EC encore cité par une règle ou un jeu MCCC : refusée avec la raison, pas une règle orpheline (Task 5, `testRemovingUsedEcTypeIsRefused`).
5. Formation existante portant des nœuds `_imposed` après passage au nouveau modèle : elle s'ouvre, s'édite, se vérifie sans erreur (Task 6, `testLegacyImposedNodesStayOrdinary`).

---

### Task 1 : Modèle — durée, types d'EC, verrou et rattachement des niveaux

**Files:**
- Create: `src/Diplome/Spec/DurationSpec.php`, `src/Diplome/Spec/EcType.php`
- Modify: `src/Diplome/Spec/StructureSpec.php`, `src/Diplome/Spec/LevelSpec.php`, et tous les lecteurs de `->duration` / `->natures` (`grep -rn "structure->duration\|->natures\|structure->natures" src templates`) : `FormationCreator`, `StructurePreview`, `DiplomeController::durationLabel`, `FormationController`, `StructureStep`, `StructureType`, `ConfigPresenter`, `FieldDef` (`optionsSource = version_natures`), `VersionMccc`.
- Test: `tests/Diplome/Spec/VersionConfigTest.php`

**Interfaces:**
- Produces:
  - `final readonly class DurationSpec { __construct(public string $levelKey, public Bound $bound); static fromArray(?array $a, string $defaultLevelKey): ?self; toArray(): array{levelKey: string, bound: array} }`
  - `final readonly class EcType { __construct(public string $key, public string $label); static fromArray(array|string $a): self; toArray(): array{key: string, label: string} }`
  - `StructureSpec::$duration : ?DurationSpec`, `StructureSpec::$natures : list<EcType>`, `StructureSpec::natureKeys(): list<string>`
  - `LevelSpec::$locked : bool` (défaut `false`), `LevelSpec::$ecLink : string` (`LevelSpec::EC_CONTAINS = 'contains'` défaut, `LevelSpec::EC_COEFFICIENT = 'coefficient'`)

- [ ] **Step 1 : tests qui échouent** dans `VersionConfigTest` :
  - `testNewShapeRoundTrips` : `StructureSpec::fromArray(['levels' => [['typeKey' => 'annee'], ['typeKey' => 'semestre', 'locked' => true, 'ecLink' => 'contains']], 'duration' => ['levelKey' => 'semestre', 'bound' => ['min' => 6, 'max' => 6]], 'natures' => [['key' => 'stage', 'label' => 'Stage']]])->toArray()` est égal à l'entrée normalisée (`levels[1]['locked'] === true`, `duration['levelKey'] === 'semestre'`, `natures[0] === ['key' => 'stage', 'label' => 'Stage']`).
  - `testLegacyShapeLoads` : `fromArray(['levels' => [['typeKey' => 'annee']], 'duration' => ['min' => 3, 'max' => 4], 'natures' => ['ec', 'stage']])` donne `duration->levelKey === 'annee'`, `duration->bound->min === 3`, `natureKeys() === ['ec', 'stage']`, `natures[1]->label === 'stage'`, `levels[0]->locked === false`, `levels[0]->ecLink === 'contains'`.
- [ ] **Step 2 :** `docker compose exec app php bin/phpunit tests/Diplome/Spec/VersionConfigTest.php` → FAIL (classe `DurationSpec` absente).
- [ ] **Step 3 :** implémenter les deux classes et les champs. Forme ancienne de `duration` = tableau sans clé `levelKey` → `levelKey` = premier niveau de la chaîne. `toArray()` de `LevelSpec` émet `locked` et `ecLink`.
- [ ] **Step 4 :** adapter les lecteurs : là où un `Bound` était attendu, lire `$structure->duration?->bound` ; là où une liste de clés était attendue, `natureKeys()`. Le formulaire garde, pour l'instant, son champ texte des natures (transformé en `EcType` avec libellé = clé) : l'écran est refait en Task 7.
- [ ] **Step 5 :** suite complète → PASS.
- [ ] **Step 6 : commit** « Structure : durée sur un niveau, types d'EC nommés, verrou et rattachement par niveau (modèle) ».

### Task 2 : Durée mesurée sur un niveau temporel choisi

**Files:**
- Create: `src/Diplome/Spec/DurationMath.php`
- Modify: `src/Diplome/StructureInstantiator.php`, `src/Diplome/Check/StructureChecker.php`, `src/Diplome/Check/StructureContextBuilder.php` (comptage des descendants par cursus), `src/Diplome/FormationCreator.php`, `src/Form/Diplome/StructureType.php` (validation), `src/Controller/DiplomeController.php` (libellé « 3 à 4 années » / « 6 semestres »)
- Test: `tests/Diplome/Spec/DurationMathTest.php`, `tests/Diplome/StructureInstantiatorTest.php`, `tests/Diplome/Check/StructureCheckerTest.php`, `tests/Controller/DiplomeWizardStructureTest.php`

**Interfaces:**
- Consumes: `DurationSpec`, `LevelSpec` (Task 1).
- Produces:
  - `final class DurationMath { static factor(StructureSpec $s): int; static firstLevelCount(StructureSpec $s, int $duration): int; /** @param list<string> $temporalKeys */ static problem(StructureSpec $s, array $temporalKeys): ?string }` — `$temporalKeys` = clés des types de nœud `isTemporel()`, fournies par l'appelant (`StructureType` via `NodeTypeRepository`).
    - `factor` = produit des `count->min` des niveaux strictement après le premier et jusqu'au niveau de durée inclus (1 si le niveau de durée est le premier).
    - `firstLevelCount` lève `\InvalidArgumentException` si `$duration % factor !== 0`.
    - `problem` renvoie une clé de traduction (`diplome.wizard.structure.error.duration_level_not_temporal`, `…duration_intermediate_not_exact`, `…duration_not_divisible`) ou `null`.
  - `StructureInstantiator::instantiate(MaquetteDoc $doc, TypeDiplomeVersion $v, int $duration, bool $avecParcours)` : signature inchangée ; `$duration` est en unités du niveau de durée.

- [ ] **Step 1 : tests qui échouent**
  - `DurationMathTest::testFactorAndFirstLevelCount` : chaîne `annee › semestre(exactement 2) › ue › ec`, durée `semestre` → `factor() === 2`, `firstLevelCount(…, 6) === 3`.
  - `DurationMathTest::testFirstLevelDuration` : durée sur `annee` → `factor() === 1`, `firstLevelCount(…, 3) === 3`.
  - `DurationMathTest::testProblems` (avec `$temporalKeys = ['annee', 'semestre']`) : semestre « entre 2 et 3 » → `…duration_intermediate_not_exact` ; durée « 5 semestres » avec 2 par année → `…duration_not_divisible` ; durée sur `ue` → `…duration_level_not_temporal`.
  - `StructureInstantiatorTest::testDurationOnSemesters` : BUT avec durée `semestre` exactement 6 → 3 nœuds `annee`, 6 nœuds `semestre`.
  - `StructureCheckerTest::testDurationCountsDescendants` : maquette avec 3 années × 2 semestres, durée « exactement 6 semestres » → aucune violation `duration:*` ; retirer un semestre → violation `duration:min`.
  - `DiplomeWizardStructureTest::testDurationNotDivisibleIsRefused` : soumission de l'étape avec durée `semestre` « exactement 5 » et semestre « exactement 2 » → réponse 422, message traduit « La durée (5) n'est pas un multiple de 2 semestres par année » visible, config inchangée.
- [ ] **Step 2 :** lancer ces tests → FAIL.
- [ ] **Step 3 :** implémenter `DurationMath` ; l'instanciation calcule le nombre du premier niveau via `firstLevelCount` ; `StructureChecker` contrôle `child_count` sur `childType = duration->levelKey` avec un contexte cursus qui compte les **descendants** (ajouter à `StructureContextBuilder` une collection `descendants` ou un paramètre de profondeur — au choix, sans casser `children`) ; `StructureType::validateConsistency` appelle `DurationMath::problem`.
- [ ] **Step 4 :** suite complète → PASS.
- [ ] **Step 5 : commit** « Structure : la durée se mesure sur un niveau temporel choisi ».

### Task 3 : EC, niveau élément obligatoire en fin de chaîne

**Files:**
- Modify: `src/Entity/NodeType.php` (colonne `elementLevel` bool, défaut `false`), formulaire et gabarit d'édition des types de nœud (`src/Controller/NodeTypeController.php` et son FormType), `src/DataFixtures/NodeTypeFixtures.php` (`ec` → `elementLevel = true`), `src/Repository/NodeTypeRepository.php`, `src/Form/Diplome/StructuralTypeKeyType.php` (variantes exclues), `src/Form/Diplome/StructureType.php`
- Test: `tests/Controller/NodeTypeElementLevelTest.php` (nouveau), `tests/Controller/DiplomeWizardStructureTest.php`

**Interfaces:**
- Produces: `NodeType::isElementLevel(): bool`, `NodeType::setElementLevel(bool): self`, `NodeTypeRepository::findElementLevel(): ?NodeType`.

- [ ] **Step 1 : tests qui échouent**
  - `NodeTypeElementLevelTest::testOnlyOneElementLevel` : cocher « niveau élément » sur un second type structurel → formulaire refusé, message « Le niveau élément est déjà porté par EC ».
  - `NodeTypeElementLevelTest::testVariantCannotBeElementLevel` : la case n'apparaît pas pour une variante ; une soumission forgée est refusée.
  - `DiplomeWizardStructureTest::testChainMustEndWithElementLevel` : chaîne `annee › semestre › ue` → refus « La chaîne doit se terminer par EC » ; chaîne `annee › ec › ue` → refus « EC doit être le dernier niveau ».
  - `DiplomeWizardStructureTest::testVariantsNotProposed` : aucune `<option>` du choix de niveau ne correspond à une variante de type de nœud.
  - `DiplomeWizardStructureTest::testEcLinkOnlyAboveElementLevel` : `ecLink = coefficient` sur `semestre` dans `annee › semestre › ue › ec` → refus « Seul le niveau juste au-dessus d'EC peut recevoir des coefficients » ; sur `annee` dans `annee › ec` → même refus ; sur `ue` → accepté.
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter (contrainte d'unicité dans le FormType du type de nœud ; validation de chaîne dans `StructureType::validateConsistency`).
- [ ] **Step 4 :** suite complète → PASS. Le schéma change : signaler à l'utilisateur que `make db-reset` sera nécessaire pour l'app locale, sans le lancer.
- [ ] **Step 5 : commit** « Structure : EC désigné comme niveau élément, toujours en fin de chaîne ».

### Task 4 : Verrou de niveau dans les formations

**Files:**
- Create: `src/Diplome/Check/LevelLockCheck.php`
- Modify: `src/Diplome/Check/StructureGuard.php`, `src/Form/Diplome/StructureType.php`, `templates/derogation/_deviation.html.twig`, `translations/derogation.fr.yaml`
- Test: `tests/Diplome/Check/LevelLockCheckTest.php`, `tests/Controller/StructureGuardTest.php`, `tests/Controller/DiplomeWizardStructureTest.php`

**Interfaces:**
- Consumes: `LevelSpec::$locked` (Task 1).
- Produces: `final class LevelLockCheck { /** @return list<StructureViolation> */ public function check(MaquetteDoc $before, MaquetteDoc $after, StructureSpec $s): array }` — une violation bloquante par (parent, niveau verrouillé) dont la suite ordonnée des `nid` diffère ; `ruleKey = 'lock:'.$typeKey`, `scopeNid` = `nid` du parent (`''` pour la racine), `actual` et `expected` à `null`. Couverte par une dérogation sans valeur (`DerogationRequest::covers` le fait déjà pour une clé sans `:min`/`:max`).

- [ ] **Step 1 : tests qui échouent**
  - `LevelLockCheckTest::testReorderIsRefused`, `testAddIsRefused`, `testDeleteIsRefused`, `testRecreatedNodeIsRefused` (même type, nouveau `nid`) → une violation `lock:semestre` sur l'année parente.
  - `LevelLockCheckTest::testRenameIsAllowed` (changer libellé et heures) → aucune violation ; `testUnlockedLevelIgnored` → aucune.
  - `StructureGuardTest::testLockedLevelBlocksAddUntilDerogation` : ajout d'un semestre via l'éditeur refusé (422) ; après dérogation accordée `lock:semestre` sur cette année, le même ajout passe.
  - `DiplomeWizardStructureTest::testLockedLevelRequiresExactCount` : niveau UE verrouillé avec « entre 3 et 6 » → refus « Un niveau verrouillé a un nombre exact ».
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter ; `StructureGuard::assertAllowed` ajoute les violations de `LevelLockCheck` quand `$before !== null` (annuler / rétablir / restaurer ne passent pas par le garde et restent permis).
- [ ] **Step 4 :** suite complète → PASS.
- [ ] **Step 5 : commit** « Structure : un niveau verrouillé fige le nombre et l'ordre de ses nœuds ».

### Task 5 : Portée positionnelle des règles et gabarit « Au moins N EC d'un type »

**Files:**
- Create: `src/Diplome/Check/ScopeSelector.php`, `src/Diplome/Rule/Template/MinElementTypeRule.php`
- Modify: `src/Diplome/Spec/RuleSpec.php` (`scopePosition`), `src/Form/Diplome/RuleSpecType.php`, `src/Diplome/Check/StructureChecker.php`, `src/Diplome/Wizard/StructureStep.php`, `src/Form/Diplome/StructureType.php`
- Test: `tests/Diplome/Check/ScopeSelectorTest.php`, `tests/Diplome/Rule/RuleTemplatesTest.php`, `tests/Diplome/Check/StructureCheckerTest.php`, `tests/Controller/DiplomeWizardStructureTest.php`

**Interfaces:**
- Produces:
  - `RuleSpec::$scopePosition : string` — `'all'` (défaut), `'first'`, `'last'` ou un numéro affiché (`'6'`).
  - `final class ScopeSelector { /** @param list<TreeNode> $nodes nœuds du niveau dans un cursus, ordre de l'arbre @return list<TreeNode> */ public function select(array $nodes, string $position, int $startAt): array }` — un numéro `n` désigne l'élément d'indice `n - $startAt` ; reprend la logique de `ImposedHostLocator::select`.
  - Gabarit `min_element_type` (libellé « Au moins N EC d'un type ») : paramètres `nature` (type `nature`), `min` (type `int`, défaut 1) ; compile `COUNT(leaves where nature == X) >= min`, bloquant, `part = 'min'`.

- [ ] **Step 1 : tests qui échouent**
  - `ScopeSelectorTest::testDisplayedNumber` : semestres d'une LP (`startAt = 5`), `select($s, '6', 5)` → le 2ᵉ semestre ; `'first'`, `'last'`, `'all'`, `'9'` (hors plage → `[]`).
  - `RuleTemplatesTest::testMinElementType` : compile pour `nature = stage`, `min = 1` ; contexte sans feuille `stage` → invalide ; avec une → valide.
  - `StructureCheckerTest::testRuleOnLastSemesterOnly` : règle `min_element_type` stage, portée `semestre` / `last` ; stage présent au S6 seulement → aucune violation ; retiré → une violation `rule:<uid>:min` sur le dernier semestre de chaque cursus.
  - `DiplomeWizardStructureTest::testRemovingUsedEcTypeIsRefused` : retirer le type `stage` cité par une règle → refus « Le type Stage est utilisé par une règle ».
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter ; dans `StructureChecker`, pour chaque règle, les portées = `ScopeSelector::select` appliqué cursus par cursus (`StructureContextBuilder::cursusScopes` / `cursusNodes`), `startAt` lu sur le `LevelSpec` du niveau de portée. Le refus de suppression d'un type couvre aussi les jeux MCCC (`VersionConfig::mccc` indexé par clé de type).
- [ ] **Step 4 :** suite complète → PASS.
- [ ] **Step 5 : commit** « Structure : règles positionnées (premier, dernier, n° affiché) et « Au moins N EC d'un type » ».

### Task 6 : Suppression des éléments imposés

**Files:**
- Delete: `src/Diplome/Spec/ImposedElement.php`, `src/Diplome/ImposedHostLocator.php`, `src/Diplome/ImposedPropagator.php`, `src/Diplome/PropagationResult.php` (si plus utilisé), `src/Form/Diplome/ImposedElementType.php`, `src/Diplome/Rule/Template/RequiredElementRule.php`, `tests/Diplome/ImposedPropagatorTest.php`, `tests/Diplome/Check/ImposedEveryHostTest.php`, `tests/Diplome/Check/ImposedPresenceTest.php`, `tests/Controller/ImposedEveryHostEditorTest.php`
- Modify: `StructureSpec` (plus de `imposed`, ignoré à la lecture), `StructureChecker` (blocs surcharges et imposés), `StructureInstantiator`, `StructureStep`, `StructureType`, `ConfigPresenter`, `NodeController` (`completeImposed`), `DiplomeEditController` (route `diplome_version_propagate` et son bouton), `DerogationRequest::covers` et `DerogationRequestRepository` (branche `imposed:`), `templates/derogation/_deviation.html.twig`, `templates/node/panel.html.twig` (badge imposé), `assets/styles/app.css` (`.imposed-badge`), `src/DataFixtures/TypeDiplomeFixtures.php`, `docs/diplomes.md`, traductions, et les tests qui citent `imposed` (liste : `grep -rln imposed tests`).
- Test: `tests/Diplome/DemoTypesTest.php`, `tests/Maquette/MaquetteDocTest.php`

**Interfaces:**
- Consumes: `min_element_type`, `RuleSpec::$scopePosition` (Task 5), `EcType` (Task 1).

- [ ] **Step 1 : tests qui échouent**
  - `DemoTypesTest::testLpObligations` : la LP déclare les types `projet_tutore` (« Projet tutoré ») et `stage` (« Stage ») et deux règles `min_element_type` portée `semestre` / `last` ; plus de clé `imposed` dans `toArray()`.
  - `DemoTypesTest::testButStructure` : le BUT a une durée `semestre` exactement 6 ; `ue` exactement 5, verrouillé, `ecLink = coefficient` ; types d'EC `ressource` (« Ressource »), `sae` (« SAÉ »), `stage` (« Stage »), `portfolio` (« Portfolio ») ; trois règles `min_element_type` `stage` portée `semestre` en positions `'2'`, `'4'`, `'6'` (valeurs de démonstration tirées de la spec §6.5) ; la règle de ratio SAÉ existante est conservée.
  - `MaquetteDocTest::testLegacyImposedNodesStayOrdinary` : un document dont un nœud porte `_imposed` et `locked = ['delete']` se charge, se vérifie (`StructureChecker::check` sans exception, aucune violation `imposed:*`) et ce nœud se supprime.
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** supprimer et adapter. Le marqueur `_imposed` est retiré au chargement (`MaquetteDoc`) ; les verrous de nœud existants (`TreeNode::$locked`) restent gérés par le code générique.
- [ ] **Step 4 :** `grep -rn -i "imposed\|imposé" src templates assets translations` → plus aucune occurrence hors commentaire historique de `docs/`. Suite complète → PASS.
- [ ] **Step 5 :** demander à l'utilisateur l'accord pour `make db-reset` (l'app locale en a besoin depuis Task 3) ; ne le lancer qu'après un oui.
- [ ] **Step 6 : commit** « Structure : suppression des éléments imposés au profit des règles sur les types d'EC ».

### Task 7 : Écran — arbre épuré et panneau de réglages

**Files:**
- Create: `assets/controllers/structure_tree_controller.js`, `src/Form/Diplome/EcTypeType.php`, `src/Form/Diplome/EctsModeType.php`, `templates/diplome/wizard/_structure_tree.html.twig`
- Delete: `assets/controllers/levels_controller.js`
- Modify: `templates/diplome/wizard/structure.html.twig` (réécrit), `templates/diplome/wizard/_structure_rows.html.twig` (ne garde que `boundWidget` et `rule`), `src/Form/Diplome/StructureType.php` (`duration` = sous-formulaire `levelKey` + `bound` ; `natures` = collection de `EcTypeType`), `src/Form/Diplome/LevelSpecType.php` (`locked`, `ecLink`, `ectsMode` + une seule borne ECTS), `src/Diplome/Wizard/StructureStep.php` (données ↔ formulaire), `translations/diplome.fr.yaml`
- Test: `tests/Controller/DiplomeWizardStructureTest.php`, `tests/Controller/DiplomeEditTest.php`

**Interfaces:**
- Consumes: tout ce qui précède.
- Produces: `LevelSpecType` champ `ectsMode` (`free` | `node` | `sum`) + `ectsBound` ; `StructureStep::apply` en déduit `ects` (mode `node`) ou `ectsSum` (mode `sum`), l'autre à `null`.

Rendu (maquette validée : `.superpowers/brainstorm/4930-1792662766/content/structure-arbre-v3.html`, schéma §5.1 de la spec) :
- ligne de durée « Dure [borne] [niveau ▾] », la liste ne contient que les niveaux temporels de la chaîne (attribut `data-temporal` posé côté serveur sur chaque ligne de niveau) ;
- une ligne par niveau, indentée, avec icône `ph:lock-simple` / `ph:pencil-simple`, libellé du type, résumé calculé en JS depuis les champs du panneau : « × 3 », « 3 à 6 », « au moins 2 », « au plus 4 », « 30 ECTS », « ≤ 10 ECTS », « N types » ; le niveau de durée porte `ph:timer` ; le premier niveau affiche son nombre déduit en lecture seule ;
- un niveau en `ecLink = coefficient` : la ligne EC est dessinée au même retrait que lui, avec la mention « coef. vers les <libellé> » ;
- EC en dernier, couleur distincte, sans bouton supprimer ni monter / descendre ;
- « + niveau » insère avant EC ; suppression et déplacement depuis le panneau ;
- panneau à droite (sous l'arbre en dessous de 900 px), un bloc par niveau dans le DOM, un seul visible ; aucun visible au chargement (ligne d'aide) ; réglages dans l'ordre du §5.3 de la spec ; « Plus de réglages » repliable (`startAt`, `parcoursAllowed` sur le premier niveau) ;
- panneau EC : étiquettes des types (ajout, renommage, suppression), chaque étiquette avec liens « fiche » et « MCCC » vers les étapes `canvas` et `mccc` de la même version (même construction d'URL que la barre latérale des étapes) ;
- règles en liste sous l'arbre (macro `rule` existante, plus le champ `scopePosition`) ; aperçu de la maquette minimale replié (`<details>`).

- [ ] **Step 1 : tests qui échouent** (`WebTestCase`, sélecteurs `data-*` stables)
  - `testTreeShowsLevelsInOrder` : page de l'étape pour la Licence de démo → `[data-tree-level]` dans l'ordre `annee, semestre, ue, ec` ; la ligne `semestre` contient « × 2 » et « 30 ECTS » ; la ligne `ue` contient « 3 à 6 » ; la ligne `ec` a `data-element-level`.
  - `testDurationListsOnlyTemporalLevels` : les `<option>` du niveau de durée sont exactement les niveaux temporels de la chaîne.
  - `testRoundTripKeepsStructure` : soumettre le formulaire tel que rendu (chaque type de démo : Licence, LP, Master, M2E, BUT) → `config()->structure->toArray()` inchangé.
  - `testLockAndEcLinkAreSaved` : passer `ue` en verrouillé « exactement 4 » et `ecLink = coefficient` → enregistrés ; la page rendue montre l'icône verrou sur `ue` et la mention « coef. vers les UE ».
  - `testEctsModeMapsToBounds` : `semestre` en mode `sum` exactement 30 → `ectsSum = {30,30}`, `ects = null`.
  - `DiplomeEditTest::testStructureTabRendersTree` : l'onglet d'édition d'une version affiche `[data-tree-level]`.
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter formulaire, gabarits, contrôleur Stimulus. Aucun gabarit ne teste une clé de type de nœud : `data-element-level` et `data-temporal` viennent de `NodeType`.
- [ ] **Step 4 :** suite complète → PASS. Vérifier à l'œil dans le navigateur (`http://localhost:8830/diplomes/2/versions/2/structure`, après `make db-reset` accordé) : sélection d'un niveau, résumés qui suivent les réglages, ajout d'un type d'EC, largeur réduite.
- [ ] **Step 5 : commit** « Structure : onglet en arbre épuré avec panneau de réglages ».

### Task 8 : Récapitulatif et documentation

**Files:**
- Modify: `src/Diplome/Wizard/ConfigPresenter.php` (lignes durée « 6 semestres », niveaux avec statut, rattachement, types d'EC par libellé, règles avec position), `docs/diplomes.md`, `docs/superpowers/specs/2026-10-20-creation-diplome-design.md` (§10 : renvoi vers la spec du 22/10 pour A6, A9, A18)
- Test: `tests/Controller/DiplomeWizardSummaryTest.php` (ou le test existant de l'étape Récapitulatif)

- [ ] **Step 1 : test qui échoue** `testSummaryDescribesTree` : le récapitulatif du BUT contient « Durée : exactement 6 Semestres », « UE : 5 par Semestre, verrouillé, EC coefficientés », « Types d'EC : Ressource, SAÉ, Stage, Portfolio ».
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter ; mettre à jour la doc.
- [ ] **Step 4 :** suite complète → PASS.
- [ ] **Step 5 : commit** « Structure : récapitulatif et documentation de l'arbre ».
