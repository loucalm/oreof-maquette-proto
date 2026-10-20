# Chantier 1 — Types de diplôme configurables : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** remplacer le couple « libellé de diplôme + template copié » par des types de diplôme versionnés par année, entièrement configurés dans une page « Diplômes » (assistant de création), dont les bornes, éléments imposés et règles sont vérifiés dans l'éditeur de maquette, avec dérogations structurées.

**Architecture :** un type (`TypeDiplome`) a des versions par année (`TypeDiplomeVersion`) qui portent toute la configuration en JSON, lue au travers de value objects (`StructureSpec`, `LevelSpec`, `Bound`, `ImposedElement`, `RuleSpec`). Un `StructureChecker` traduit bornes, éléments imposés et règles en gabarits de règles (`RuleTemplateInterface`, classes taguées) évalués par `RuleEvaluator`. Un `StructureGuard`, appelé par `Maquette::save()`, refuse une modification qui crée une nouvelle violation bloquante non couverte par une dérogation accordée. Le rendu des champs devient générique : chaque `FieldTypeInterface` fournit ses gabarits Twig.

**Tech Stack :** Symfony 8.1, PHP 8.5, Doctrine ORM + SQLite (schéma par `doctrine:schema:create`, pas de migrations dans le prototype), Twig + Stimulus + Turbo, PHPUnit (`WebTestCase` / `KernelTestCase`, base `var/test.db` rechargée par test).

**Spec :** `docs/superpowers/specs/2026-10-20-creation-diplome-design.md`

## Global Constraints

- Aucun code, gabarit ou condition ne teste le nom ou la clé d'un diplôme précis (`'BUT'`, `'Licence'`, `'licence_lmd'`…), ni une clé de champ ou de type d'ELP précise dans les gabarits de rendu (spec §2, §6.6).
- Seul l'admin (rôle simulé `is_admin()`) accède à la page Diplômes, à l'assistant et à la validation des dérogations ; contrôle côté serveur via `AdminAccessSubscriber` (spec §3, §6.1).
- Un type a une version par année universitaire, au format `AAAA-AAAA` (ex. `2026-2027`) ; une formation utilise la version de son année, à défaut la plus récente antérieure (spec §6.2, §9).
- Bornes « par parent » : minimum, maximum, ou exactement (min = max). Un bloc à choix compte pour 1 (spec §3, §6.4).
- Hors bornes : bloqué ; dérogation validée par l'admin et historisée ; elle couvre l'écart demandé, pas au-delà (spec §6.4).
- Le rattachement multiple, les exports, l'API, la reconduction et les règles de validation du diplôme sont hors périmètre (spec §4).
- Les données de démonstration recréent Licence, LP, Master, M2E et BUT (sans rattachement multiple) par configuration seulement (spec §4, §8).
- Tests lancés dans le conteneur : `docker compose exec -T app php bin/phpunit` (la base de test SQLite n'est pas accessible depuis le PHP de l'hôte). Base actuelle : 160 tests OK.
- Textes d'interface en français, dans `translations/*.fr.yaml` (nouveau domaine `diplome`), appelés via `|trans`.
- Conventions `AGENTS.md` : attributs PHP, autowiring, contrôleurs fins, Symfony Form pour les nouveaux formulaires, `readonly` sur les value objects.

## Review Focus

1. **Maquette existante déjà hors bornes** (formations de démo, données anciennes) : modifier un autre nœud ne doit pas être bloqué ; seule une violation *nouvelle* bloque. → test dans la tâche 8.
2. **Annuler / rétablir / restaurer une révision** ne doit jamais être bloqué par le contrôle des bornes. → test dans la tâche 8.
3. **Formation sans type de diplôme** (ex. « Certificat Data Analyst » des fixtures) : l'éditeur fonctionne sans aucun contrôle de bornes. → test dans la tâche 8.
4. **Année sans version** : une formation de 2027-2028 sur un type n'ayant qu'une version 2026-2027 utilise cette dernière ; une formation antérieure à toute version n'a pas de version (pas d'erreur). → test dans la tâche 3.
5. **Suppression d'un type utilisé** : refusée côté serveur même si le bouton est forgé (POST direct). → test dans la tâche 10.

---

## Structure des fichiers

**Rendu générique des champs**
- `src/FieldType/FieldTypeInterface.php` (modifié) : `widgetTemplate()`, `viewTemplate()`.
- `templates/field_type/*.html.twig` (créés) : un gabarit d'édition par type, `templates/field_type/view/*.html.twig` pour la lecture.
- `src/Twig/FieldTypeExtension.php` (créé) : fonctions `field_widget()` et `field_view()`.

**Type de diplôme**
- `src/Entity/TypeDiplome.php`, `src/Entity/TypeDiplomeVersion.php`, `src/Repository/TypeDiplome*Repository.php` (créés).
- `src/Diplome/Spec/Bound.php`, `LevelSpec.php`, `ImposedElement.php`, `RuleSpec.php`, `StructureSpec.php`, `VersionConfig.php` (créés) : lecture typée du JSON de version.
- `src/Diplome/TypeDiplomeResolver.php` (créé) : version d'une formation.
- `src/Diplome/StructureInstantiator.php` (créé) : maquette initiale depuis une version (remplace `TemplateApplier`).
- `src/DataFixtures/TypeDiplomeFixtures.php` (créé), `TemplateFixtures.php` (supprimé en tâche 13).

**Règles et contrôle**
- `src/Diplome/Rule/RuleTemplateInterface.php`, `RuleTemplateRegistry.php`, `CompiledRule.php` et un fichier par gabarit dans `src/Diplome/Rule/Template/` (créés).
- `src/Rules/RuleEvaluator.php` (modifié) : filtre `where` sur les agrégats, expression `ratio`.
- `src/Diplome/Check/StructureContextBuilder.php`, `StructureChecker.php`, `StructureViolation.php` (créés).
- `src/Diplome/Check/StructureGuard.php`, `StructureViolationException.php`, `src/EventSubscriber/StructureViolationListener.php` (créés) ; `src/Maquette/Maquette.php` (modifié).
- `src/Entity/DerogationRequest.php`, `src/Controller/DerogationController.php` (modifiés).

**Page Diplômes et assistant**
- `src/Controller/DiplomeController.php` (liste, archivage, suppression), `src/Controller/DiplomeWizardController.php` (assistant et édition par onglets) (créés).
- `src/Form/Diplome/*Type.php` (créés) : un formulaire par étape.
- `src/Diplome/WizardDraft.php` (créé) : brouillon de l'assistant en session.
- `templates/diplome/*.html.twig` (créés).

---

### Task 1 : Rendu générique des champs (gabarit par type)

**Files :**
- Modify : `src/FieldType/FieldTypeInterface.php`, `src/FieldType/AbstractFieldType.php`, chaque `src/FieldType/*FieldType.php`
- Create : `templates/field_type/{text,textarea,number,choice,checkbox,radio,competencies,hours,mccc,separator}.html.twig`, `templates/field_type/view/{default,textarea,competencies}.html.twig`, `src/Twig/FieldTypeExtension.php`
- Modify : `templates/node/panel.html.twig` (macro `field`, l.310-516), `templates/formation/param/_fields.html.twig` (macro `field`), `templates/formation/view.html.twig` (l.1-15, l.268), `src/Entity/FieldDef.php`, `src/Entity/NodeType.php`, `src/DataFixtures/FieldDefFixtures.php`, `src/DataFixtures/NodeTypeFixtures.php`
- Test : `tests/FieldType/FieldTypeTemplatesTest.php`, `tests/Controller/GenericFieldRenderingTest.php`

**Interfaces :**
- Produces : `FieldTypeInterface::widgetTemplate(): string` (chemin Twig), `FieldTypeInterface::viewTemplate(): string` (défaut `field_type/view/default.html.twig`). Fonctions Twig `field_widget(FieldDef|array $def, string $key, mixed $value, string $inputName, ?TreeNode $node = null, list<string> $errors = []): string` et `field_view(FieldDef|array $def, mixed $value, ?TreeNode $node = null): string`. Variables reçues par chaque gabarit : `def`, `key`, `value`, `name` (nom HTML de l'input : `attr_ects` côté nœud, `p[ects]` côté paramètres), `options` (référentiel résolu ou `def.options`), `node`, `errors`.
- Produces : `FieldDef::getPlaceholder(): ?string` (nouvelle colonne nullable), `NodeType::isCodeRequired(): bool` (nouvelle colonne, défaut `false`).

- [ ] **Step 1 : tests en échec**

```php
// tests/FieldType/FieldTypeTemplatesTest.php (KernelTestCase)
public function testEveryFieldTypeHasExistingTemplates(): void
{
    $twig = static::getContainer()->get('twig');
    foreach (static::getContainer()->get(FieldTypeRegistry::class)->all() as $type) {
        self::assertTrue($twig->getLoader()->exists($type->widgetTemplate()), $type->key());
        self::assertTrue($twig->getLoader()->exists($type->viewTemplate()), $type->key());
    }
}

public function testRenderingTemplatesNoLongerBranchOnFieldTypeOrKey(): void
{
    foreach (['templates/node/panel.html.twig', 'templates/formation/param/_fields.html.twig', 'templates/formation/view.html.twig'] as $file) {
        $src = file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/'.$file);
        self::assertDoesNotMatchRegularExpression('/def\.(field|type)\s*(==|in)\s/', $src, $file);
        self::assertDoesNotMatchRegularExpression("/key\s*==\s*'/", $src, $file);
        self::assertDoesNotMatchRegularExpression('/node\.type\.key\s*(==|in)/', $src, $file);
    }
}
```

```php
// tests/Controller/GenericFieldRenderingTest.php (WebTestCase + ResetsDatabase + FormationFactory::fromTemplate('licence_lmd'))
// panneau d'un EC : un input number "attr_ects", un select "attr_ueType" sur une UE avec l'option vide
// = FieldDef 'ueType' placeholder ("Choisir un type d'UE"), un select "attr_ficheMatiere",
// le bloc MCCC (input radio "attr_mccc[type]") et le bloc heures ("attr_hours[none]").
// page paramètre « presentation » de la formation : textarea "p[objectif]".
```

- [ ] **Step 2 : lancer et constater l'échec**
Run : `docker compose exec -T app php bin/phpunit tests/FieldType tests/Controller/GenericFieldRenderingTest.php`
Expected : FAIL (`widgetTemplate` indéfinie).

- [ ] **Step 3 : implémenter**
- Déplacer chaque branche des trois chaînes `if/elseif` dans le gabarit du type correspondant ; les macros `field` des deux gabarits appelants se réduisent à libellé + `field_widget(...)` + erreurs. `view.html.twig` appelle `field_view(...)`.
- Cas particuliers convertis en données (fixtures) : `ueType` et `ecType` reçoivent un `placeholder` ; le libellé et l'aide de `ects` contiennent `%type%`, remplacé par le libellé du type de nœud dans le gabarit `number` ; la note sur la nature devient l'aide du champ `nature` ; `ficheMatiere` devient un champ `choice` avec `referentielKey = 'fiches_matiere'` et `allowExtra = true` ; le type `ec` reçoit `codeRequired = true` (libellé et aide « code EC » choisis sur `node.type.codeRequired`).
- Les champs `hours` et `mccc` gardent leur libellé propre : la décision de ne pas afficher le libellé générique passe par `isSystem()` du type, pas par une liste de clés.

- [ ] **Step 4 : relancer**
Run : `docker compose exec -T app php bin/phpunit`
Expected : PASS, y compris les 160 tests existants.

- [ ] **Step 5 : commit**
`git commit -m "Champs : un gabarit de rendu par type de champ, plus de chaîne de conditions"`

---

### Task 2 : Entités type de diplôme, versions et value objects de configuration

**Files :**
- Create : `src/Entity/TypeDiplome.php`, `src/Entity/TypeDiplomeVersion.php`, `src/Repository/TypeDiplomeRepository.php`, `src/Repository/TypeDiplomeVersionRepository.php`, `src/Diplome/Spec/{Bound,LevelSpec,ImposedElement,RuleSpec,StructureSpec,VersionConfig}.php`
- Test : `tests/Diplome/Spec/VersionConfigTest.php`, `tests/Diplome/Spec/BoundTest.php`

**Interfaces :**
- Produces : `TypeDiplome` : `key` (string, unique, sigle normalisé), `label`, `sigle`, `codeApogee` (?string, 1 caractère), `description` (?string), `accredite` (bool, défaut true), `passageCfvu` (bool, défaut true), `archived` (bool), `versions` (OneToMany, triées par année). Méthodes : `getVersions(): list<TypeDiplomeVersion>`, `latestVersion(): ?TypeDiplomeVersion`.
- Produces : `TypeDiplomeVersion` : `typeDiplome` (ManyToOne), `anneeUniversitaire` (string `AAAA-AAAA`, unique avec le type), `config` (JSON), `basedOn` (?string, « clé@année » du type de départ). Méthodes : `config(): VersionConfig`, `setConfig(VersionConfig $c): self`, `startYear(): int` (première année de `anneeUniversitaire`).
- Produces : `final readonly class Bound(?int $min, ?int $max)` : `static exactly(int $n): self`, `static fromArray(?array $a): ?self`, `toArray(): array`, `isExact(): bool`.
- Produces : `final readonly class LevelSpec(string $typeKey, ?Bound $count, ?Bound $ects, ?Bound $ectsSum, int $startAt = 1)` — `count` = nombre de nœuds de ce niveau par parent ; `ects` = ECTS de chaque nœud ; `ectsSum` = somme des ECTS des enfants attendue sur chaque nœud de ce niveau ; `startAt` = premier indice de numérotation (LP : semestre `startAt = 5`).
- Produces : `final readonly class ImposedElement(string $uid, string $typeKey, string $label, string $parentLevel, string $position, ?string $nature, list<string> $locked, ?Bound $countOverride)` — `position` ∈ `first`, `last`, `all`, ou un indice `"n"` (1-based) parmi les nœuds de `parentLevel` ; `countOverride` surcharge la borne du niveau enfant à cette position.
- Produces : `final readonly class RuleSpec(string $uid, string $template, array $params, string $scopeLevel, string $severity)` — `severity` ∈ `error`, `warning`.
- Produces : `final readonly class StructureSpec(list<LevelSpec> $levels, bool $parcoursAllowed, ?Bound $duration, list<string> $natures, list<ImposedElement> $imposed, list<RuleSpec> $rules)` — `duration` borne le nombre de nœuds du premier niveau temporel ; `natures` = clés de nature d'élément proposées au niveau feuille.
- Produces : `final readonly class VersionConfig(StructureSpec $structure, array $forms, array $mccc, array $options, list<string> $bccLevels, int $bccLinkLevel)` avec `fromArray(array): self` / `toArray(): array`. `forms` : `array<string, string>` emplacement → clé de `NodeType` de famille `parameter` (emplacements `formation.organisation`, `formation.presentation`, `parcours.organisation`, `parcours.presentation`) ; les formulaires des niveaux de structure sont les `NodeType` de la chaîne eux-mêmes (`LevelSpec::typeKey`) ; `mccc` : `array<string, array{types: list<string>, profile: ?string}>` nature → clés `MccType` autorisées + profil ; `options` : `array{quitus: bool, assiduite: bool, sansNote: bool}` ; `bccLevels` : noms des niveaux (1 à 3) ; `bccLinkLevel` : indice 0-based du niveau relié aux éléments (défaut : dernier).

- [ ] **Step 1 : tests en échec**

```php
public function testRoundTripKeepsEveryValue(): void
{
    $raw = [ /* Licence : levels annee(count exactly 3? non : duration), semestre count exactly 2 startAt 1 ectsSum exactly 30,
               ue count {min:3,max:6} ects {max:10}, ec count {max:4}; natures ['ec','stage'];
               imposed [] ; rules [] ; forms {...}; mccc {'ec': {types:['CT','CC_CT','CC','CCI'], profile:'licence'}};
               options {quitus:true, assiduite:true, sansNote:false}; bccLevels ['Bloc','Compétence']; bccLinkLevel 1 */ ];
    self::assertSame($raw, VersionConfig::fromArray($raw)->toArray());
}

public function testExactBoundHasEqualMinAndMax(): void
{
    self::assertTrue(Bound::exactly(2)->isExact());
    self::assertNull(Bound::fromArray(null));
    self::assertEquals(new Bound(3, null), Bound::fromArray(['min' => 3]));
}

public function testMissingKeysFallBackToDefaults(): void
{
    $c = VersionConfig::fromArray([]);
    self::assertSame([], $c->structure->levels);
    self::assertSame(['quitus' => false, 'assiduite' => false, 'sansNote' => false], $c->options);
}
```

- [ ] **Step 2 : lancer — FAIL (classes absentes).**
Run : `docker compose exec -T app php bin/phpunit tests/Diplome/Spec`

- [ ] **Step 3 : implémenter entités, dépôts et value objects.** `TypeDiplomeVersionRepository::findForYear(TypeDiplome $t, string $annee): ?TypeDiplomeVersion` renvoie la version de l'année, sinon la plus récente dont `startYear()` est antérieure, sinon `null`.

- [ ] **Step 4 : relancer — PASS.** Run : `docker compose exec -T app php bin/phpunit`

- [ ] **Step 5 : commit** `git commit -m "Types de diplôme : entités, versions par année et lecture typée de la configuration"`

---

### Task 3 : Rattacher les formations à un type, résolveur, données de démonstration

**Files :**
- Modify : `src/Entity/Formation.php` (ajout `typeDiplome` ManyToOne nullable ; `getDiplome()` renvoie le libellé du type s'il existe, sinon l'ancienne valeur texte)
- Create : `src/Diplome/TypeDiplomeResolver.php`, `src/DataFixtures/TypeDiplomeFixtures.php`
- Modify : `src/DataFixtures/FormationFixtures.php` (rattacher les formations Licence au type `L`, garder « Certificat » sans type)
- Test : `tests/Diplome/TypeDiplomeResolverTest.php`, `tests/Diplome/DemoTypesTest.php`

**Interfaces :**
- Consumes : tâche 2.
- Produces : `TypeDiplomeResolver::versionFor(Formation $f): ?TypeDiplomeVersion` (type de la formation + `anneeUniversitaire`, défaut l'année courante `2026-2027` si vide) ; `TypeDiplomeResolver::configFor(Formation $f): ?VersionConfig`.
- Produces (fixtures, clés stables pour les tests) : types `L`, `LP`, `M`, `M2E`, `BUT`, chacun avec une version `2026-2027`. Valeurs (spec §3 et tableau de la publication métier) :
  - `L` : chaîne `annee › semestre › ue › ec` ; `duration` {min 3, max 4} ; semestre `count` exactly 2, `ectsSum` exactly 30 ; ue `count` {min 3, max 6} ; natures `ec`, `stage` ; MCCC `ec` → `CT`, `CC_CT`, `CC`, `CCI`, profil `licence` ; BCC `Bloc`, `Compétence`, lien niveau 1.
  - `LP` : basée sur `L` ; `duration` {min 1, max 2} ; semestre `startAt` 5 ; profil MCCC `master` (souple) ; imposé : UE « Projet tutoré et stage » dans le dernier semestre, verrous `delete`, `duplicate`.
  - `M` : basée sur `L` ; `duration` exactly 2 ; profil `master`.
  - `M2E` : chaîne `annee › bloc_formation › ec` (pas de semestre) ; `duration` exactly 2 ; `bloc_formation` `count` {min 1, max 4} par année ; BCC `Bloc`, `Objectif`, `Attendu`, lien niveau 2. Créer le `NodeType` `bloc_formation` (structurel, kind `structurel`, capacités `ects`, `competencies`) dans `NodeTypeFixtures`.
  - `BUT` : chaîne `annee › semestre › ue › ec` ; `duration` exactly 3 ; natures `ressource`, `sae` ; MCCC `ressource` et `sae` → `CC` ; imposé : EC de nature `stage` dans le dernier semestre ; règle `ratio` : ECTS des `sae` ≥ 40 % par semestre (valeur de démonstration, à confirmer métier).

- [ ] **Step 1 : tests en échec**

```php
public function testFormationUsesVersionOfItsYearOrLatestEarlier(): void
{
    // type L avec versions 2026-2027 et 2028-2029 (la seconde créée dans le test)
    // formation 2027-2028 → version 2026-2027 ; 2029-2030 → 2028-2029 ; 2025-2026 → null
}

public function testFormationWithoutTypeHasNoVersion(): void { /* « Certificat Data Analyst » → null */ }

public function testDemoTypesExistWithTheirDistinctiveSettings(): void
{
    // L : duration {3,4}, ue count {3,6} ; LP : startAt 5 et 1 élément imposé ; M2E : aucun niveau 'semestre',
    // bccLevels ['Bloc','Objectif','Attendu'] ; BUT : natures ['ressource','sae'] et 1 règle template 'ratio'
}
```

- [ ] **Step 2 : lancer — FAIL.** Run : `docker compose exec -T app php bin/phpunit tests/Diplome`
- [ ] **Step 3 : implémenter résolveur, relation et fixtures.**
- [ ] **Step 4 : relancer toute la suite — PASS.**
- [ ] **Step 5 : commit** `git commit -m "Formations rattachées à un type de diplôme ; types de démonstration L, LP, M, M2E, BUT"`

---

### Task 4 : Maquette initiale générée depuis une version

**Files :**
- Create : `src/Diplome/StructureInstantiator.php`
- Modify : `src/Maquette/Numbering.php` (`apply(array $roots, array $startAt = [])` : `typeKey => premier indice`)
- Test : `tests/Diplome/StructureInstantiatorTest.php`

**Interfaces :**
- Consumes : `VersionConfig`, `StructureSpec`, `ImposedElement` (tâche 2) ; `MaquetteDoc::addNode()`.
- Produces : `StructureInstantiator::instantiate(MaquetteDoc $doc, TypeDiplomeVersion $v, int $duration, bool $avecParcours): void` — vide la maquette, fixe `Formation::structure` (chaîne des `typeKey`, sans `parcours`) et `avecParcours`, crée `duration` nœuds du premier niveau, puis pour chaque niveau suivant le minimum de sa borne `count` (1 si pas de minimum) sous chaque parent, s'arrête avant le niveau feuille, puis ajoute les éléments imposés avec leurs verrous et l'attribut `_imposed = uid`. Lève `\InvalidArgumentException` si `duration` sort de `StructureSpec::duration`.
- Produces : `Numbering::apply()` honore `LevelSpec::startAt` transmis par l'appelant (`Maquette`/`MaquetteBuilder` passent `TypeDiplomeResolver::configFor()`).

- [ ] **Step 1 : tests en échec**

```php
public function testLicenceThreeYearsGivesSixSemestersAndThreeUeEach(): void { /* 3 annee, 6 semestre, 18 ue, 0 ec */ }
public function testLicenceAcceptsFourYearsButNotFive(): void { /* 4 OK ; 5 → InvalidArgumentException */ }
public function testLpNumbersSemestersFromFive(): void { /* refs des semestres = 'S5','S6' (via Numbering) */ }
public function testM2eHasNoSemesterLevel(): void { /* aucun nœud 'semestre' ; chaîne = ['annee','bloc_formation','ec'] */ }
public function testImposedElementIsCreatedLockedInLastSemester(): void
{ /* LP : une UE « Projet tutoré et stage » sous le dernier semestre, isLocked('delete') true, attribut _imposed */ }
```

- [ ] **Step 2 : lancer — FAIL.**
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : relancer toute la suite — PASS.**
- [ ] **Step 5 : commit** `git commit -m "Maquette initiale générée depuis les bornes et éléments imposés d'une version"`

---

### Task 5 : Gabarits de règles et extensions du moteur

**Files :**
- Modify : `src/Rules/RuleEvaluator.php` (agrégat : clé optionnelle `where: {field, op, value}` ; nouvelle expression `{kind: 'ratio', numerator: <expr>, denominator: <expr>}` → `numerator / denominator`, 0 si dénominateur nul)
- Create : `src/Diplome/Rule/RuleTemplateInterface.php`, `RuleTemplateRegistry.php`, `CompiledRule.php`, `RuleParam.php`, `src/Diplome/Rule/Template/{ChildCountRule,AttributeSumRule,AttributeValueRule,RatioRule,SameChildValueRule,RequiredElementRule}.php`, `src/Diplome/Check/StructureContextBuilder.php`
- Test : `tests/Rules/RuleEvaluatorTest.php` (ajouts), `tests/Diplome/Rule/RuleTemplatesTest.php`

**Interfaces :**
- Produces : `#[AutoconfigureTag('app.rule_template')] interface RuleTemplateInterface { key(): string; label(): string; /** @return list<RuleParam> */ params(): array; /** @return list<CompiledRule> */ compile(array $params): array; }`. `final readonly class RuleParam(string $name, string $label, string $type /* int|string|typeKey|nature|attribute|op */, bool $required = true)`. `final readonly class CompiledRule(array $node /* AST RuleEvaluator */, bool $blocking /* true pour un dépassement de maximum ou un élément imposé absent */, string $message)`.
- Produces : clés de gabarits : `child_count` (params `childType`, `min`, `max`), `attribute_sum` (`attribute`, `min`, `max`, `childType?`), `attribute_value` (`attribute`, `min`, `max`), `ratio` (`attribute`, `numeratorNature`, `op`, `percent`), `same_child_value` (`attribute`), `required_element` (`typeKey`, `nature?`). Un gabarit à min et max compile en **deux** règles : la partie minimum (`blocking: false`), la partie maximum (`blocking: true`) ; un gabarit sans borne (`same_child_value`, `required_element`) en une seule (`blocking: true`).
- Produces : `RuleTemplateRegistry::get(string $key): RuleTemplateInterface`, `all(): array<string, RuleTemplateInterface>`.
- Produces : `StructureContextBuilder::rootScopes(MaquetteDoc $doc): list<?TreeNode>` — parents du premier niveau de la chaîne : chaque nœud parcours en multi-parcours, `null` (racine de la maquette) en mono-parcours ; c'est la portée de la borne `duration`.
- Produces : `StructureContextBuilder::contextFor(MaquetteDoc $doc, ?TreeNode $scope): array<string, list<array<string, mixed>>>` (`null` = racine) — collections `children` (enfants directs ; un bloc à choix compte pour 1, avec ses `ects`) et `leaves` (feuilles descendantes ; dans un bloc à choix, un seul enfant compté) ; chaque ligne : `nid`, `typeKey`, `nature`, `ects`, plus les attributs du nœud.

- [ ] **Step 1 : tests en échec**

```php
// RuleEvaluatorTest
public function testAggregateWhereFiltersRows(): void { /* SUM ects WHERE nature == 'sae' sur 3 lignes → 12.0 */ }
public function testRatioExpressionDividesAndToleratesZero(): void { /* 12/30 → 0.4 ; x/0 → 0 */ }

// RuleTemplatesTest
public function testChildCountCompilesToNonBlockingMinAndBlockingMax(): void
{ /* child_count {childType:'ue', min:3, max:6} → 2 CompiledRule ; sur 7 enfants : seule la max invalide et blocking */ }
public function testChoiceBlocCountsAsOneChild(): void { /* semestre avec 2 UE + 1 bloc de choix de 4 UE → 3 */ }
public function testRatioOfSaeEcts(): void { /* ratio {attribute:'ects', numeratorNature:'sae', op:'>=', percent:40} : 10/30 invalide, 12/30 valide */ }
public function testSameChildValueOnChoiceBloc(): void { /* enfants 3,3,2 → invalide */ }
public function testEveryTemplateIsDiscoveredByTheRegistry(): void { /* 6 clés */ }
```

- [ ] **Step 2 : lancer — FAIL.**
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : relancer toute la suite — PASS.**
- [ ] **Step 5 : commit** `git commit -m "Règles : gabarits paramétrables découverts par registre, filtre et ratio dans le moteur"`

---

### Task 6 : Vérification d'une maquette contre sa version

**Files :**
- Create : `src/Diplome/Check/StructureChecker.php`, `src/Diplome/Check/StructureViolation.php`
- Modify : `src/Controller/FormationController.php` (`check`, route `formation_check`), `templates/formation/check.html.twig`, `src/Maquette/Completion.php` (une violation non bloquante compte comme élément incomplet ; une violation bloquante comme erreur)
- Test : `tests/Diplome/Check/StructureCheckerTest.php`, `tests/Controller/CheckPageTest.php`

**Interfaces :**
- Consumes : tâches 2, 3, 5.
- Produces : `final readonly class StructureViolation(string $ruleKey, string $scopeNid, string $message, bool $blocking, float|int|null $actual, float|int|null $expected)` ; `ruleKey` stable : `level:<typeKey>:count:min|max`, `level:<typeKey>:ects:min|max`, `level:<typeKey>:ectsSum:min|max`, `duration:min|max`, `imposed:<uid>`, `rule:<uid>:min|max`.
- Produces : `StructureChecker::check(MaquetteDoc $doc, VersionConfig $config): list<StructureViolation>` — compile bornes (via `child_count`, `attribute_value`, `attribute_sum`), éléments imposés (via `required_element`, surcharges `countOverride`) et `RuleSpec` (via le registre), les évalue sur chaque nœud du niveau de portée.

- [ ] **Step 1 : tests en échec**

```php
public function testSeventhUeInSemesterIsBlocking(): void { /* Licence instanciée + 4 UE ajoutées dans S1 → violation level:ue:count:max blocking, actual 7, expected 6 */ }
public function testTwoUeIsOnlyIncomplete(): void { /* S1 réduit à 2 UE → level:ue:count:min non bloquante */ }
public function testSemesterEctsSumMustBeThirty(): void { /* S1 à 32 ECTS → level:semestre:ectsSum:max */ }
public function testMissingImposedElementIsBlocking(): void { /* LP : UE imposée retirée de force → imposed:<uid> blocking */ }
public function testBlocChoiceWithUnevenChildrenFailsRule(): void { /* règle same_child_value ajoutée à la version */ }
// CheckPageTest : GET /formations/{id}/verifier affiche le message de chaque violation et le libellé du nœud concerné
```

- [ ] **Step 2 : lancer — FAIL.**
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : relancer toute la suite — PASS.**
- [ ] **Step 5 : commit** `git commit -m "Vérification d'une maquette contre les bornes, éléments imposés et règles de sa version"`

---

### Task 7 : Dérogations structurées

**Files :**
- Modify : `src/Entity/DerogationRequest.php` (colonnes nullables `ruleKey`, `scopeNid`, `allowedValue` float), `src/Repository/DerogationRequestRepository.php`, `src/Controller/DerogationController.php`, `templates/derogation/index.html.twig`, `templates/formation/editor.html.twig` (modale de dérogation pré-remplie)
- Test : `tests/Controller/StructuredDerogationTest.php`

**Interfaces :**
- Produces : `DerogationRequestRepository::grantedCovering(Formation $f, string $ruleKey, string $scopeNid): ?DerogationRequest` (statut accordé, même règle et même nœud) ; une dérogation couvre une violation si `actual <= allowedValue` (règle de maximum) ou `actual >= allowedValue` (règle de minimum, suffixe `:min`) ; `imposed:*` est couverte sans comparaison.
- Produces : `POST /formations/{fid}/derogations` accepte en plus `ruleKey`, `scopeNid`, `allowedValue` ; l'admin accorde ou refuse depuis `/administration/derogations` (existant), chaque décision horodatée (`resolvedAt` existant) et visible dans l'historique de la formation.

- [ ] **Step 1 : tests en échec**

```php
public function testRequestCarriesTheExactDeviation(): void { /* POST ruleKey level:ue:count:max, scopeNid S1, allowedValue 7 → entité avec ces valeurs, statut en attente */ }
public function testGrantedDerogationCoversUpToAllowedValueOnly(): void { /* accordée à 7 : couvre actual 7, pas 8 */ }
public function testOnlyAdminCanResolve(): void { /* rôle responsable → redirection, statut inchangé */ }
```

- [ ] **Step 2 : lancer — FAIL.**
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : relancer toute la suite — PASS.**
- [ ] **Step 5 : commit** `git commit -m "Dérogations : écart structuré (règle, nœud, valeur accordée)"`

---

### Task 8 : Blocage des modifications hors bornes dans l'éditeur

**Files :**
- Create : `src/Diplome/Check/StructureGuard.php`, `src/Diplome/Check/StructureViolationException.php`, `src/EventSubscriber/StructureViolationListener.php`
- Modify : `src/Maquette/Maquette.php` (`save()` appelle le garde avant d'écrire l'arbre ; `restore()`, `undo()`, `redo()` le contournent), `translations/diplome.fr.yaml`
- Test : `tests/Controller/StructureGuardTest.php`

**Interfaces :**
- Consumes : `StructureChecker` (tâche 6), `DerogationRequestRepository::grantedCovering` (tâche 7), `TypeDiplomeResolver` (tâche 3).
- Produces : `StructureGuard::assertAllowed(Formation $f, ?MaquetteDoc $before, MaquetteDoc $after): void` — lève `StructureViolationException` (porte `list<StructureViolation> $violations`) si `$after` contient une violation **bloquante** dont la paire (`ruleKey`, `scopeNid`) est absente de `$before` ou dont l'écart a augmenté, et qui n'est couverte par aucune dérogation accordée. Aucune version → aucun contrôle. `Maquette::save()` reconstruit `$before` depuis l'instantané qu'il garde déjà (`$this->before[$id]`).
- Produces : `StructureViolationListener` (`#[AsEventListener]` sur `KernelEvents::EXCEPTION`) : requête JSON/AJAX → réponse 422 `{error, violations: [{ruleKey, scopeNid, message, actual, expected}]}` ; sinon flash `warning` avec le message et un lien « Demander une dérogation » (modale pré-remplie : `ruleKey`, `scopeNid`, `allowedValue = actual`), puis redirection vers l'éditeur sur le nœud concerné.

- [ ] **Step 1 : tests en échec**

```php
public function testAddingSeventhUeIsRefusedAndTreeUnchanged(): void { /* POST node_add ×4 sur S1 d'une Licence : le 4e (7e UE) → redirection + flash ; 6 UE en base */ }
public function testMoveOverTheBoundAnswers422(): void { /* POST node_move (AJAX) d'une UE vers un semestre plein → 422 + ruleKey level:ue:count:max */ }
public function testGrantedDerogationLetsTheActionThrough(): void { /* dérogation accordée allowedValue 7 → l'ajout passe ; le 8e est refusé */ }
public function testExistingViolationDoesNotBlockUnrelatedEdit(): void { /* maquette déjà à 7 UE en S1 (écrite sans garde), renommer une UE de S2 → OK */ }
public function testUndoRedoAndRestoreAreNeverBlocked(): void { /* état hors bornes en révision → undo / restore → OK */ }
public function testFormationWithoutTypeIsNotChecked(): void { /* « Certificat Data Analyst » : ajouts libres */ }
```

- [ ] **Step 2 : lancer — FAIL.**
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : relancer toute la suite — PASS** (vérifier `QuickGridTest` et `EditorActionsTest` : les formations de démo doivent rester dans leurs bornes, sinon ajuster les fixtures, pas le garde).
- [ ] **Step 5 : commit** `git commit -m "Éditeur : refus des modifications qui sortent des bornes, avec lien de dérogation"`

---

### Task 9 : Page Diplômes (liste, archivage, suppression protégée)

**Files :**
- Create : `src/Controller/DiplomeController.php`, `templates/diplome/index.html.twig`, `translations/diplome.fr.yaml` (compléments)
- Modify : `src/EventSubscriber/AdminAccessSubscriber.php` (préfixe `diplome_`), `templates/base.html.twig` (entrée de navigation « Diplômes », visible pour l'admin, séparée d'« Administration »), `src/Repository/FormationRepository.php` (`countByTypeDiplome(TypeDiplome $t): int`)
- Test : `tests/Controller/DiplomePageTest.php`

**Interfaces :**
- Produces : routes `diplome_index` `GET /diplomes`, `diplome_archive` `POST /diplomes/{id}/archiver`, `diplome_unarchive` `POST /diplomes/{id}/desarchiver`, `diplome_delete` `POST /diplomes/{id}/supprimer`. Colonnes de la liste : libellé, sigle, type de départ (`basedOn` de la dernière version), durée (borne `duration`), format BCC (`bccLevels` joints par « › »), nombre de formations, dernière version. Actions : Modifier (tâche 12), Dupliquer (tâche 10, `?depuis=<versionId>`), Archiver, Supprimer (affiché seulement si 0 formation).

- [ ] **Step 1 : tests en échec**

```php
public function testListShowsDemoTypesWithFormationCount(): void { /* 5 types ; « Licence » affiche 2 formations */ }
public function testDeleteRefusedWhenTypeIsUsed(): void { /* POST direct diplome_delete sur L → flash erreur, type toujours là */ }
public function testDeleteUnusedType(): void { /* M2E sans formation → supprimé avec ses versions */ }
public function testArchivedTypeIsHiddenFromFormationCreation(): void { /* archiver L → absent du select de création */ }
public function testResponsableIsRedirected(): void { /* rôle responsable → GET /diplomes redirige */ }
```

- [ ] **Step 2 : lancer — FAIL.**
- [ ] **Step 3 : implémenter** (confirmation intégrée à la page via le contrôleur Stimulus `confirm-dialog` existant).
- [ ] **Step 4 : relancer toute la suite — PASS.**
- [ ] **Step 5 : commit** `git commit -m "Page Diplômes : liste, archivage et suppression protégée"`

---

### Task 10 : Assistant — informations, type de départ, formulaires, récapitulatif

**Files :**
- Create : `src/Controller/DiplomeWizardController.php`, `src/Diplome/WizardDraft.php`, `src/Form/Diplome/{InformationsType,BasedOnType,FormsType}.php`, `templates/diplome/wizard/{_layout,informations,based_on,forms,summary}.html.twig`
- Test : `tests/Controller/DiplomeWizardTest.php`

**Interfaces :**
- Produces : `WizardDraft` (stocké en session sous `diplome_wizard`) : `array $informations`, `?int $basedOnVersionId`, `VersionConfig $config`, `string $anneeUniversitaire` ; `WizardDraft::fromVersion(TypeDiplomeVersion $v): self` (copie la configuration).
- Produces : routes `diplome_wizard_start` `GET /diplomes/nouveau` (option `?depuis=<versionId>`), `diplome_wizard_step` `GET|POST /diplomes/nouveau/{step}` avec `step` ∈ `informations`, `depart`, `formulaires`, `structure`, `mccc`, `bcc`, `recapitulatif` (ordre fixe ; les étapes `structure`, `mccc`, `bcc` viennent des tâches 11 et 12 et affichent ici « à venir »), `diplome_wizard_save` `POST /diplomes/nouveau/enregistrer` → crée `TypeDiplome` + `TypeDiplomeVersion`, vide le brouillon, redirige vers `diplome_index`.
- Étape `formulaires` : pour chaque emplacement de `VersionConfig::forms`, un select des `NodeType` de famille `parameter`.
- Étape `recapitulatif` : liste lisible de la configuration ; si `basedOn`, chaque différence avec la version de départ est signalée.

- [ ] **Step 1 : tests en échec**

```php
public function testCreateDeustFromLicenceChangingOnlyInformations(): void
{ /* start ?depuis=<version L> → POST informations (Deust, DEUST) → recapitulatif → save :
     type DEUST, version 2026-2027, config.structure identique à L, basedOn 'L@2026-2027' */ }
public function testInformationsRequireLabelAndUniqueSigle(): void { /* sigle 'L' déjà pris → erreur de formulaire */ }
public function testSummaryHighlightsDifferencesWithBaseType(): void { /* forms.formation.presentation changé → mention dans le récapitulatif */ }
```

- [ ] **Step 2 : lancer — FAIL.**
- [ ] **Step 3 : implémenter** (navigation latérale des 7 étapes, Suivant / Précédent, étape courante marquée).
- [ ] **Step 4 : relancer toute la suite — PASS.**
- [ ] **Step 5 : commit** `git commit -m "Assistant Nouveau diplôme : informations, type de départ, formulaires, récapitulatif"`

---

### Task 11 : Assistant — étape Structure (niveaux, bornes, éléments imposés, règles)

**Files :**
- Create : `src/Form/Diplome/{StructureType,LevelSpecType,BoundType,ImposedElementType,RuleSpecType}.php`, `templates/diplome/wizard/structure.html.twig`, `assets/controllers/rule_params_controller.js` (affiche les paramètres du gabarit choisi)
- Test : `tests/Controller/DiplomeWizardStructureTest.php`

**Interfaces :**
- Consumes : `RuleTemplateRegistry` (tâche 5) pour la liste des gabarits et `params()` pour générer leurs champs ; `StructureInstantiator` (tâche 4) pour l'aperçu.
- Produces : `BoundType` : un choix « aucune / au moins / au plus / entre / exactement » + un ou deux nombres → `Bound`. `StructureType` : chaîne ordonnée de niveaux (`NodeType` structurels), par niveau `count`, `ects`, `ectsSum`, `startAt` ; `parcoursAllowed` ; `duration` ; `natures` ; collections `imposed` et `rules`.
- Aperçu : sous le formulaire, la maquette minimale produite par `StructureInstantiator` avec la plus petite durée autorisée (arbre en lecture seule).

- [ ] **Step 1 : tests en échec**

```php
public function testExactlyTwoSemestersIsStoredAsExactBound(): void { /* choix « exactement » 2 → {min:2,max:2} */ }
public function testRemovingSemesterLevelLikeM2e(): void { /* chaîne annee › bloc_formation › ec enregistrée, aperçu sans semestre */ }
public function testAddingRatioRuleStoresTemplateAndParams(): void { /* gabarit ratio, attribute ects, nature sae, >=, 40, portée semestre */ }
public function testUnknownTemplateIsRejected(): void { /* template 'eval' → erreur de formulaire */ }
public function testPreviewShowsMinimalTree(): void { /* Licence : 3 années, 6 semestres dans l'aperçu */ }
```

- [ ] **Step 2 : lancer — FAIL.**
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : relancer toute la suite — PASS.**
- [ ] **Step 5 : commit** `git commit -m "Assistant : étape Structure avec bornes, éléments imposés et règles par gabarit"`

---

### Task 12 : Assistant — étapes MCCC et BCC ; édition d'un diplôme et versions

**Files :**
- Create : `src/Form/Diplome/{MccConfigType,BccConfigType}.php`, `templates/diplome/wizard/{mccc,bcc}.html.twig`, `templates/diplome/edit.html.twig`, `src/Diplome/ImposedPropagator.php`
- Modify : `src/Controller/DiplomeWizardController.php` (routes d'édition), `src/Maquette/McccValidator.php` et `src/Twig/MaquetteExtension.php` (`mccc_types_for` : types et profil lus dans `VersionConfig::mccc` selon la nature du nœud au lieu de `StructureTemplate::mcccProfiles`)
- Test : `tests/Controller/DiplomeEditTest.php`, `tests/Diplome/ImposedPropagatorTest.php`

**Interfaces :**
- Produces : étape `mccc` : par nature déclarée, cases des `MccType` autorisés + select de profil (profils du `MccType`) ; options quitus / assiduité / sans note. Étape `bcc` : 1 à 3 noms de niveaux, niveau de rattachement (défaut : dernier).
- Produces : routes `diplome_edit` `GET /diplomes/{id}/versions/{versionId}/{step}` (mêmes formulaires, en onglets), `diplome_edit_save` `POST` même chemin, `diplome_version_new` `POST /diplomes/{id}/versions` (crée l'année suivante de la dernière version, par copie).
- Produces : enregistrer une version utilisée par des formations exige le champ `confirmImpact` (case cochée) ; la page affiche avant la liste des formations concernées. Les bornes et règles s'appliquent aussitôt (lecture en direct) ; `ImposedPropagator::propagate(Formation $f, VersionConfig $c): int` ajoute les éléments imposés manquants et réapplique leurs verrous, sur demande explicite (bouton « Propager aux formations »), et renvoie le nombre de nœuds touchés.

- [ ] **Step 1 : tests en échec**

```php
public function testButSaeAllowsOnlyContinuousAssessment(): void { /* nature sae → types ['CC'] enregistrés ; panneau d'un EC sae ne propose que CC */ }
public function testBccLevelsAndLinkLevelAreStored(): void { /* ['Bloc','Objectif','Attendu'], lien 2 */ }
public function testSavingUsedVersionRequiresImpactConfirmation(): void { /* L utilisée : POST sans confirmImpact → erreur, config inchangée ; avec → enregistrée */ }
public function testNewYearVersionCopiesPrevious(): void { /* POST diplome_version_new sur L → version 2027-2028 = copie de 2026-2027 */ }
public function testPropagationAddsMissingImposedElement(): void { /* élément imposé ajouté à LP → propagate → 1 nœud créé et verrouillé */ }
```

- [ ] **Step 2 : lancer — FAIL.**
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : relancer toute la suite — PASS.**
- [ ] **Step 5 : commit** `git commit -m "Diplômes : étapes MCCC et BCC, édition par version, confirmation d'impact et propagation"`

---

### Task 13 : Brancher la création de formation et retirer les templates

**Files :**
- Modify : `src/Controller/FormationController.php` (`create` : select des types non archivés + année + durée dans les bornes → `StructureInstantiator` ; `settings` : verrous lus depuis la version ; `param` / `parcoursParam` : `NodeType` lu dans `VersionConfig::forms` à l'emplacement `formation.<key>` / `parcours.<key>`, à défaut la convention `param_<key>_formation` actuelle), `templates/formation/index.html.twig` (modale de création), `src/Maquette/TemplateDiff.php` → remplacé par `ImposedPropagator`, `src/Twig/MaquetteExtension.php` (`template_for_diplome` retiré), `src/Maquette/MaquetteExporter.php`, `src/Maquette/Completion.php`, `src/Maquette/Maquette.php` (libellé du diplôme via `Formation::getDiplome()`), `tests/Support/FormationFactory.php` (`fromType(string $typeKey, int $duration, ...)`)
- Delete : `src/Entity/StructureTemplate.php`, `src/Repository/StructureTemplateRepository.php`, `src/Controller/TemplateController.php`, `src/Maquette/{TemplateApplier,TemplateSummary,TemplateTree,TemplateDiff}.php`, `templates/template/`, `src/DataFixtures/TemplateFixtures.php`, entrée `templates` de `AdminController::SECTIONS`, le référentiel `diplomes` dans `ReferentielFixtures`
- Test : adapter `tests/Maquette/TemplateApplierTest.php` (supprimé, couvert par la tâche 4), `tests/Controller/TemplateAndBccFormatTest.php` (réécrit sur les types), `tests/Controller/FormationCreationTest.php` (créé)

**Interfaces :**
- Consumes : tâches 3, 4, 12.
- Produces : `FormationFactory::fromType(string $typeKey, int $duration, string $name = 'Formation de test', bool $avecParcours = false): Formation` ; les tests existants qui utilisaient `fromTemplate('licence_lmd'|'master_parcours'|'but')` passent par `fromType('L', 3)`, `fromType('M', 2, avecParcours: true)`, `fromType('BUT', 3)`.

- [ ] **Step 1 : tests en échec**

```php
public function testCreatingLpInTwoYearsGivesS5ToS8(): void { /* POST formation_create type LP, durée 2 → 4 semestres numérotés S5 à S8, élément imposé présent */ }
public function testDurationOutsideBoundsIsRefused(): void { /* LP durée 3 → flash erreur, aucune formation créée */ }
public function testArchivedTypeCannotBeChosen(): void { /* POST forgé avec type archivé → refusé */ }
public function testNoReferenceToStructureTemplateRemains(): void
{ /* grep src/ et templates/ : aucune occurrence de 'StructureTemplate', 'TemplateApplier', 'template_for_diplome' */ }
```

- [ ] **Step 2 : lancer — FAIL.**
- [ ] **Step 3 : implémenter, supprimer le code mort, adapter les tests existants.**
- [ ] **Step 4 : relancer toute la suite — PASS ; recréer la base de dev** : `make db-reset`, puis ouvrir `http://localhost:8830/diplomes` et créer une formation LP de 2 ans pour vérification à l'œil.
- [ ] **Step 5 : commit** `git commit -m "Création de formation pilotée par le type de diplôme ; suppression des templates"`
