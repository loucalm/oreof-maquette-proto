# Formulaires : canevas, bibliothèque et variantes — plan du lot 1

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer les quatre emplacements fixes de l'onglet « Formulaires » par un canevas des fiches formation et parcours (entrées choisies, masquées, renommées, réordonnées, ajoutées), avec une bibliothèque de formulaires à variantes héritées, des barres latérales générées depuis le canevas, la fiche parcours appliquée aux formations sans parcours, et un aperçu sur la vraie page.

**Architecture:** Un formulaire reste un `NodeType` ; il gagne un parent (variante), des réglages de champ propres (`fieldSettings`), une portée et une position dans le canevas par défaut. `FormResolver` calcule les champs résolus d'un formulaire en remontant sa lignée. Le canevas (`CanvasSpec`) est stocké dans la configuration de version ; `CanvasResolver` le lit (ou construit le canevas par défaut depuis les données) et produit la navigation des éditeurs. Les écrans intégrés sont des classes taguées. Une abstraction `FicheTarget` unifie la lecture et l'écriture des données d'une fiche (formation, parcours, parcours unique). L'aperçu est un contexte de session qui substitue un canevas pour une formation hôte, en lecture seule.

**Tech Stack:** PHP 8 / Symfony (attributs, autowiring), Doctrine ORM (SQLite, sans migrations), Twig, Stimulus, PHPUnit (`WebTestCase`, `KernelTestCase`).

**Spec:** `docs/superpowers/specs/2026-10-21-formulaires-canevas-design.md`. Ce plan couvre le lot 1 (§8 « Ordre de réalisation ») : tout sauf les fiches des éléments (§6.1 bloc « Fiches des éléments », §6.6 dernier point, §6.4 « Fiches d'élément »), qui auront leur propre plan.

## Global Constraints

- Aucun code, gabarit ou condition ne teste la clé d'un diplôme, d'une entrée ou d'un formulaire. Seules exceptions : la déclaration de chaque écran intégré (sa classe) et les gabarits de champs liés à l'entité, choisis par convention de nom (`formation/param/bound/<fiche>/<clé de champ>.html.twig`).
- Toute nouvelle route d'administration est nommée `diplome_*` ou `node_type_*` (garde `AdminAccessSubscriber` par préfixe) et les POST du préfixe `diplome_` portent `#[IsCsrfTokenValid(DiplomeController::CSRF_ID, tokenKey: DiplomeController::CSRF_FIELD)]`, comme les routes voisines.
- Pas de migrations : après un changement de schéma, `make db-reset` (efface les données locales). Les tests recréent le schéma eux-mêmes (`ResetsDatabase`).
- Tests uniquement dans le conteneur : `docker compose exec -T app php bin/phpunit`. Fichier ciblé : `docker compose exec -T app php bin/phpunit tests/<chemin>`.
- Textes d'interface en français, dans `translations/*.fr.yaml` (domaines existants : `diplome`, `admin`, `formation`).
- Conventions `AGENTS.md` : attributs PHP, autowiring, contrôleurs minces, `readonly` pour les objets valeur, Form pour les formulaires serveur.
- Messages de commit en français, terminés par la ligne `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Une entrée masquée, un formulaire changé ou un champ retiré ne supprime jamais de données saisies.

## Review Focus

1. **Champ à choix multiple entièrement décoché** dans une entrée : la valeur doit être effacée, alors que les champs absents du formulaire courant doivent être conservés (fusion limitée aux champs du formulaire). Test : Task 3, `testUncheckedMultiChoiceIsCleared`.
2. **Version existante sans canevas, ou avec l'ancienne clé `forms`** (données locales antérieures) : la fiche doit s'afficher comme aujourd'hui, avec les formulaires choisis. Test : Task 2, `testLegacyFormsAreMappedOntoDefaultEntries`.
3. **Formulaire supprimé ou renommé alors qu'un canevas le désigne** : l'entrée disparaît de la navigation sans erreur 500. Test : Task 2, `testEntryWithMissingFormIsSkipped`.
4. **Aperçu laissé ouvert puis navigation vers une autre formation** : seule la formation hôte est affectée, et aucune écriture n'est possible sur l'hôte tant que l'aperçu est actif. Tests : Task 9, `testPreviewOnlyAffectsHostFormation`, `testPreviewIsReadOnly`.
5. **Passage avec ↔ sans parcours** d'une formation qui a déjà saisi des informations de parcours : rien n'est perdu dans les deux sens. Test : Task 5, `testSwitchingParcoursModeKeepsParcoursData`.

---

## File Structure

| Fichier | Rôle |
|---|---|
| `src/Enum/FormScope.php` | Portée d'un formulaire : `formation`, `parcours`, `element`. |
| `src/Entity/NodeType.php` | + `parent`, `fieldSettings`, `scope`, `defaultEntryKey`, `defaultEntryPosition`, `knownParentFields`. |
| `src/Formulaire/ResolvedField.php` | Champ résolu d'un formulaire (définition + réglages effectifs + origines). |
| `src/Formulaire/FormResolver.php` | Lignée, capacités, champs et règles résolus d'un formulaire. |
| `src/Formulaire/VariantFactory.php` | Création d'une variante. |
| `src/Formulaire/FormUsage.php` | Versions qui utilisent un formulaire (directement ou via une variante). |
| `src/Diplome/Spec/CanvasEntry.php`, `CanvasSpec.php` | Canevas typé, stocké dans la configuration de version. |
| `src/Formulaire/Builtin/*` | Interface, registre et cinq écrans intégrés. |
| `src/Formulaire/CanvasDefaults.php` | Canevas par défaut construit depuis les données. |
| `src/Formulaire/CanvasResolver.php`, `NavItem.php` | Canevas effectif d'une version / formation, navigation des éditeurs, paramètres visibles. |
| `src/Formulaire/Fiche/*` | `FicheTarget` et ses trois implémentations, fabrique, transfert parcours ↔ parcours unique. |
| `src/Formulaire/EntryCompletion.php` | Statut et anomalies d'une entrée (remplace la partie « paramètres » de `Completion`). |
| `src/Controller/FicheEntryController.php` | Routes génériques d'affichage / enregistrement d'une entrée. |
| `templates/formation/param/entry.html.twig`, `bound/**` | Rendu générique d'une entrée et des champs liés à l'entité. |
| `src/Diplome/Wizard/CanvasStep.php`, `src/Form/Diplome/CanvasType.php`, `CanvasEntryType.php`, `templates/diplome/wizard/forms.html.twig` | Étape / onglet « Formulaires ». |
| `src/Controller/CanvasController.php` | « Adapter » et « Aperçu » depuis le canevas. |
| `src/Formulaire/Preview/PreviewContext.php`, `PreviewHostFinder.php`, `src/EventSubscriber/PreviewGuardSubscriber.php` | Aperçu. |

Supprimés au fil des tâches : `src/Diplome/ParamTypeResolver.php`, `src/Diplome/Wizard/FormsStep.php`, `src/Form/Diplome/FormsType.php`, `templates/formation/param/{organisation,presentation,parcours_organisation,parcours_presentation}.html.twig`, les constantes `FormationController::PARAM_SECTIONS` / `PARCOURS_PARAM_SECTIONS` et `NodeTypeController::EDITABLE_PARAM_KEYS`, les quatre pseudo-types `param_structure_formation`, `param_structure_sans_parcours_formation`, `param_bcc_sans_parcours_formation`, `param_bcc_parcours` des fixtures.

---

### Task 1: Formulaires héritables et réglages de champ par formulaire

**Files:**
- Create: `src/Enum/FormScope.php`, `src/Formulaire/ResolvedField.php`, `src/Formulaire/FormResolver.php`
- Modify: `src/Entity/NodeType.php`, `src/Twig/FieldTypeExtension.php:89-128`, `src/Validation/TabValidator.php:42-90`
- Test: `tests/Formulaire/FormResolverTest.php`

**Interfaces:**
- Produces:
  - `enum FormScope: string { case Formation = 'formation'; case Parcours = 'parcours'; case Element = 'element'; }`
  - `NodeType::getParent(): ?NodeType`, `setParent(?NodeType $parent): self` (lève `\DomainException` si cycle, ou si famille ou portée différente du parent), `isVariant(): bool`
  - `NodeType::getFieldSettings(): array<string, array{label?: string, help?: string, required?: bool, tab?: string, position?: int}>`, `setFieldSetting(string $fieldKey, array $settings): self` (fusionne ; une valeur `null` retire la propriété ; un tableau vide retire la clé)
  - `NodeType::getScope(): ?FormScope`, `setScope(?FormScope): self` ; `getDefaultEntryKey(): ?string`, `setDefaultEntryKey(?string): self` ; `getDefaultEntryPosition(): ?int`, `setDefaultEntryPosition(?int): self` ; `getKnownParentFields(): list<string>`, `setKnownParentFields(list<string>): self`
  - `final readonly class ResolvedField(FieldDef $def, string $label, ?string $help, bool $required, string $tab, int $position, bool $hidden, list<string> $origins)` avec `getKey()`, `getType()`, `isLocked()`, `getMin()`, `getMax()`, `getPlaceholder()`, `getOptions()` délégués à `$def`. Constantes d'origine : `INHERITED='inherited'`, `ADDED='added'`, `HIDDEN='hidden'`, `LABEL='label'`, `HELP='help'`, `REQUIRED='required'`, `OPTIONAL='optional'`, `MOVED='moved'`.
  - `FormResolver::lineage(NodeType $form): list<NodeType>` (racine d'abord) ; `capabilities(NodeType $form): array<string, bool>` ; `fields(NodeType $form, bool $withHidden = false): list<ResolvedField>` ; `validationRules(NodeType $form): list<ValidationRule>` ; `newParentFields(NodeType $variant): list<ResolvedField>` ; `acknowledgeParentFields(NodeType $variant): void`
  - `TabValidator::check(NodeType $type, callable $value, array $activeKeys, ?array $requiredKeys = null)` : `$requiredKeys` remplace `FieldDef::isRequired()` quand il est fourni ; les règles viennent de `FormResolver::validationRules($type)`.

- [ ] **Step 1: Write the failing tests** — `tests/Formulaire/FormResolverTest.php`, extends `App\Tests\Support\DatabaseTestCase`, formulaires chargés par clé via `NodeTypeRepository::findOneByKey()`.

```php
public function testSocleFieldsFollowCapabilitiesAndFieldDefDefaults(): void
{
    $fields = $this->resolver->fields($this->form('param_presentation_formation'));
    self::assertSame(['objectif', 'resultats', 'contenu', 'sep_pres_rythme', 'rythme', 'rythmePrecision'], array_map(fn ($f) => $f->getKey(), $fields));
    self::assertSame($this->fieldDef('objectif')->getLabel(), $fields[0]->label);
}

public function testSocleSettingOverridesOnlyThatForm(): void
{
    $this->form('param_presentation_formation')->setFieldSetting('objectif', ['label' => 'But']);
    self::assertSame('But', $this->field('param_presentation_formation', 'objectif')->label);
    self::assertSame($this->fieldDef('objectif')->getLabel(), $this->field('param_presentation_parcours', 'objectif')->label);
}

public function testVariantInheritsAndStoresOnlyDifferences(): void
{
    $v = $this->variantOf('param_presentation_formation'); // parent posé, rien d'autre
    $v->setCapabilities(['contenu' => false, 'motsCles' => true])->setFieldSetting('rythme', ['required' => true]);
    $keys = array_map(fn ($f) => $f->getKey(), $this->resolver->fields($v));
    self::assertNotContains('contenu', $keys);
    self::assertContains('motsCles', $keys);
    self::assertTrue($this->find($v, 'rythme')->required);
    self::assertSame([ResolvedField::INHERITED], $this->find($v, 'objectif')->origins);
    self::assertSame([ResolvedField::ADDED], $this->find($v, 'motsCles')->origins);
    self::assertContains(ResolvedField::REQUIRED, $this->find($v, 'rythme')->origins);
    self::assertTrue($this->find($v, 'contenu', withHidden: true)->hidden);
}

public function testFieldAddedToParentAppearsInVariant(): void { /* parent.capabilities['motsCles']=true après création → motsCles visible dans la variante ; newParentFields = ['motsCles'] ; après acknowledgeParentFields → [] */ }
public function testFieldRemovedFromParentDisappearsAndOrphanSettingIgnored(): void { /* réglage 'contenu' sur la variante, capacité 'contenu' retirée du parent → absent, aucune exception */ }
public function testLockedFieldCannotBeHiddenOrMadeOptional(): void { /* variante de param_organisation_formation : capabilities ['name' => false], setting respMention required=false → 'name' visible, respMention required === true */ }
public function testVariantOfVariantResolvesRecursively(): void { /* label posé sur v1, champ masqué sur v2 (parent v1) → v2 a le label de v1 et le champ masqué */ }
public function testCycleIsRefused(): void { /* $a->setParent($b) puis $b->setParent($a) → expectException(\DomainException) */ }
public function testVariantValidationRulesAddToParents(): void { /* règle ajoutée sur la variante → validationRules() = règles du parent + la sienne, parent d'abord */ }
public function testRequiredKeysOverrideFieldDefInTabValidator(): void { /* check(..., requiredKeys: ['codeRncp']) : la règle REQUIRED_IF sur codeRncp est ignorée (champ déjà obligatoire) */ }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T app php bin/phpunit tests/Formulaire/FormResolverTest.php`
Expected: FAIL (classe `App\Formulaire\FormResolver` introuvable).

- [ ] **Step 3: Add the `NodeType` columns and `FormScope`** — `parent` en `ManyToOne(targetEntity: self::class)` avec `JoinColumn(onDelete: 'RESTRICT')` ; `fieldSettings` et `knownParentFields` en `Types::JSON` (défaut `[]`) ; `scope` en `enumType: FormScope::class`, nullable ; `defaultEntryKey` `length: 64` nullable ; `defaultEntryPosition` nullable.

- [ ] **Step 4: Implement `ResolvedField` and `FormResolver`** — Résolution : catalogue `FieldDefRepository::allOrdered()` ; un champ appartient au formulaire si une forme de sa lignée a la clé dans ses capacités ; actif si la capacité fusionnée (racine → formulaire, le plus proche gagne) vaut `true`. Réglages fusionnés propriété par propriété dans le même ordre, par-dessus les valeurs de `FieldDef`. Un champ `locked` ignore `capabilities = false` et `required = false`. Tri par `position` résolue, puis par id de `FieldDef`. Pas de mémo : la résolution relit l'état courant des entités à chaque appel.

- [ ] **Step 5: Accept `ResolvedField` in `FieldTypeExtension::normalize()`** (libellé, aide, obligatoire résolus ; le reste depuis `$def`) et ajouter `$requiredKeys` à `TabValidator::check()`.

- [ ] **Step 6: Run tests to verify they pass** — même commande ; Expected: PASS. Puis la suite complète : `docker compose exec -T app php bin/phpunit` → PASS (aucun appelant ne change encore).

- [ ] **Step 7: Commit**

```bash
git add src/Enum/FormScope.php src/Entity/NodeType.php src/Formulaire src/Twig/FieldTypeExtension.php src/Validation/TabValidator.php tests/Formulaire
git commit -m "Formulaires : variantes héritées et réglages de champ par formulaire"
```

---

### Task 2: Canevas, écrans intégrés et canevas par défaut

**Files:**
- Create: `src/Diplome/Spec/CanvasEntry.php`, `src/Diplome/Spec/CanvasSpec.php`, `src/Formulaire/Builtin/BuiltinScreenInterface.php`, `src/Formulaire/Builtin/BuiltinScreenRegistry.php`, `src/Formulaire/Builtin/{FormationBccScreen,RamificationScreen,StructureScreen,ParcoursBccScreen,ParcoursPositionScreen}.php`, `src/Formulaire/CanvasDefaults.php`, `src/Formulaire/CanvasResolver.php`, `src/Formulaire/NavItem.php`
- Modify: `src/Diplome/Spec/VersionConfig.php`, `src/DataFixtures/NodeTypeFixtures.php:81-119`, `src/DataFixtures/TypeDiplomeFixtures.php:55-63,80,144,183`, `src/Controller/FormationController.php` (route GET `formation_structure`)
- Test: `tests/Formulaire/CanvasResolverTest.php`, `tests/Diplome/Spec/VersionConfigTest.php`

**Interfaces:**
- Consumes: `FormResolver` (Task 1), `NodeType::getScope()/getDefaultEntryKey()/getDefaultEntryPosition()`.
- Produces:
  - `final readonly class CanvasEntry(string $key, ?string $form, ?string $builtin, ?string $label, bool $hidden)` ; `isBuiltin(): bool` ; `fromArray(array): self` ; `toArray(): array{key, form, builtin, label, hidden}`.
  - `final readonly class CanvasSpec(list<CanvasEntry> $formation, list<CanvasEntry> $parcours)` ; constantes `FORMATION = 'formation'`, `PARCOURS = 'parcours'` ; `entries(string $fiche): list<CanvasEntry>` ; `entry(string $fiche, string $key): ?CanvasEntry` ; `withEntries(string $fiche, list<CanvasEntry> $entries): self` ; `fromArray` / `toArray(): array{formation: list<array>, parcours: list<array>}`.
  - `VersionConfig` : la propriété `forms` est remplacée par `public ?CanvasSpec $canvas` et `public array $legacyForms` (lu depuis l'ancienne clé `forms` seulement si `canvas` est absent). `toArray()` écrit `canvas` (ou `null`) et jamais `forms`.
  - `#[AutoconfigureTag('app.builtin_screen')] interface BuiltinScreenInterface` : `key(): string`, `label(): string` (déjà traduit), `fiche(): string`, `hideable(): bool`, `defaultPosition(): int`, `appliesTo(Formation $formation): bool`, `url(Formation $formation, ?TreeNode $parcours): string`, `status(Formation $formation, ?TreeNode $parcours): ?string`, `isCurrent(array $query): bool`.
  - `BuiltinScreenRegistry::get(string $fiche, string $key): ?BuiltinScreenInterface`, `forFiche(string $fiche): list<BuiltinScreenInterface>`.
  - `CanvasDefaults::canvas(): CanvasSpec`.
  - `final readonly class NavItem(string $fiche, string $key, string $label, int $number, string $url, ?string $status, ?string $group, bool $builtin)`.
  - `CanvasResolver::forConfig(?VersionConfig $config): CanvasSpec` ; `forFormation(Formation $formation): CanvasSpec` ; `label(string $fiche, CanvasEntry $entry): string` ; `form(CanvasEntry $entry): ?NodeType` ; `formEntry(Formation $formation, string $fiche, string $key): ?CanvasEntry` (entrée à formulaire, non masquée, dont le formulaire existe) ; `navigation(Formation $formation, ?TreeNode $parcours = null): list<NavItem>`.

Valeurs fixées :

| Écran intégré | `key` | `fiche` | masquable | position par défaut | s'applique | URL |
|---|---|---|---|---|---|---|
| `FormationBccScreen` | `bcc` | formation | oui | 30 | formation sans parcours | `bcc_editor` |
| `RamificationScreen` | `ramification` | formation | non | 31 | formation avec parcours | `formation_parcours_graph` |
| `StructureScreen` | `structure` | formation | non | 40 | toujours | `formation_structure` (nouvelle route GET `/formations/{id}/structure`, qui rend l'actuel `formation/param/structure.html.twig` avec les variables de l'actuel `FormationController::param('structure')`) |
| `ParcoursBccScreen` | `bcc` | parcours | oui | 30 | toujours | `bcc_parcours_editor` |
| `ParcoursPositionScreen` | `positionnement` | parcours | non | 40 | toujours | panneau du nœud parcours (même URL que `MaquetteExtension::nodeUrl($parcours)`) |

Fixtures : `param_organisation_formation` et `param_organisation_parcours` → `defaultEntryKey='organisation'`, position 10 ; `param_presentation_*` → `'presentation'`, position 20 ; portée `formation` / `parcours` selon le suffixe. Les quatre pseudo-types sans champs sont retirés. `TypeDiplomeFixtures` ne pose plus `forms`.

Règles de `CanvasResolver` :
- `forConfig` : `canvas` de la configuration, sinon `CanvasDefaults::canvas()` sur lequel on applique `legacyForms` (`formation.organisation` → entrée `organisation` de la fiche formation, etc.) ; dans tous les cas, les écrans intégrés absents sont ajoutés en fin de fiche, visibles.
- `navigation` : fiche parcours si `$parcours` est donné, sinon fiche formation ; entrées masquées, écrans qui ne s'appliquent pas et entrées dont le formulaire n'existe plus sont sautés ; numérotation continue à partir de 1 ; `group = null`. Statut : écran intégré → `status()` ; entrée à formulaire → `null` ici (posé en Task 4). Le groupe « Parcours » d'une formation sans parcours est ajouté en Task 5.

- [ ] **Step 1: Write the failing tests** — `tests/Formulaire/CanvasResolverTest.php` (DatabaseTestCase, formations via `tests/Support/FormationFactory`).

```php
public function testDefaultCanvasReproducesCurrentFiche(): void
{
    $c = $this->resolver->forConfig(null);
    self::assertSame(['organisation', 'presentation', 'bcc', 'ramification', 'structure'], array_map(fn ($e) => $e->key, $c->formation));
    self::assertSame(['organisation', 'presentation', 'bcc', 'positionnement'], array_map(fn ($e) => $e->key, $c->parcours));
    self::assertSame('param_organisation_formation', $c->entry('formation', 'organisation')->form);
}

public function testLegacyFormsAreMappedOntoDefaultEntries(): void
{
    $c = $this->resolver->forConfig(VersionConfig::fromArray(['forms' => ['formation.presentation' => 'param_organisation_formation']]));
    self::assertSame('param_organisation_formation', $c->entry('formation', 'presentation')->form);
}

public function testDefaultCanvasFollowsLibraryData(): void { /* param_presentation_formation->setDefaultEntryKey(null) → 'presentation' absent de forConfig(null)->formation */ }
public function testBuiltinsMissingFromStoredCanvasAreAppended(): void { /* canvas stocké sans 'structure' → présent en dernier, visible */ }
public function testEntryWithMissingFormIsSkipped(): void { /* entrée form 'inexistant' → absente de navigation(), pas d'exception */ }
public function testNavigationSkipsHiddenEntriesAndNumbersTheRest(): void
{
    // formation sans parcours, version dont le canevas masque 'presentation'
    $labels = array_map(fn ($i) => $i->number.' '.$i->key, $this->resolver->navigation($formation));
    self::assertSame(['1 organisation', '2 bcc', '3 structure'], $labels);
}
public function testNavigationOfMultiParcoursFormationShowsRamificationNotBcc(): void { /* clés ['organisation','presentation','ramification','structure'], aucun group 'parcours' */ }
public function testParcoursNavigationListsParcoursFiche(): void { /* navigation($f, $parcours) clés ['organisation','presentation','bcc','positionnement'] */ }
```

Dans `VersionConfigTest`, remplacer la donnée `forms` par un `canvas` et vérifier que `toArray()` n'a plus de clé `forms` et restitue le canevas.

- [ ] **Step 2: Run tests to verify they fail** — `docker compose exec -T app php bin/phpunit tests/Formulaire/CanvasResolverTest.php tests/Diplome/Spec/VersionConfigTest.php` ; Expected: FAIL.

- [ ] **Step 3: Implement the spec objects, `VersionConfig` change, builtins, registry, `CanvasDefaults`, `CanvasResolver`, fixtures and the `formation_structure` GET route.** En cas d'égalité de position dans le canevas par défaut, l'ordre est : entrées à formulaire, puis écrans intégrés par `key`. Libellés des écrans intégrés dans `translations/formation.fr.yaml` (reprendre les libellés actuels de la barre latérale). Les lecteurs de `VersionConfig::$forms` (`ParamTypeResolver`, `ConfigPresenter::formsRows/pathLabel`, `FormsStep`) passent temporairement par `CanvasResolver::forConfig()` (entrée `organisation`/`presentation` → formulaire) pour que la suite reste verte ; ils sont remplacés en Tasks 3 et 6.

- [ ] **Step 4: Run tests to verify they pass** — même commande, puis la suite complète ; Expected: PASS (adapter `DiplomeWizardTest` lignes 160-166 si elles lisent `config()->forms` : lire `legacyForms` ou le canevas).

- [ ] **Step 5: Commit** — `git commit -m "Formulaires : canevas de version, écrans intégrés et canevas par défaut"`

---

### Task 3: Entrées génériques des fiches formation et parcours

**Files:**
- Create: `src/Formulaire/Fiche/FicheTarget.php`, `FormationFiche.php`, `ParcoursFiche.php`, `MonoParcoursFiche.php`, `FicheFactory.php` ; `src/Formulaire/EntryCompletion.php` ; `src/Controller/FicheEntryController.php` ; `templates/formation/param/entry.html.twig` ; `templates/formation/param/bound/formation/{name,diplome,domaine,composante}.html.twig` ; `templates/formation/param/bound/parcours/{nom,ectsTotal}.html.twig`
- Modify: `src/Controller/FormationController.php:246-336,624-688` (retrait de `param`, `paramSave`, `parcoursParam`, `parcoursParamSave`, des deux constantes de sections), `src/Maquette/Completion.php:221-345` (retrait de la partie paramètres), `src/Twig/MaquetteExtension.php:72-86` (fonctions `param_*` réécrites sur les nouveaux services, en attendant Task 4), `src/Twig/MaquetteExtension.php` (+ `template_exists(string $name): bool`)
- Delete: `src/Diplome/ParamTypeResolver.php`, `templates/formation/param/{organisation,presentation,parcours_organisation,parcours_presentation}.html.twig`
- Test: `tests/Controller/FicheEntryTest.php` ; mettre à jour `tests/Controller/AutosaveParamTest.php`, `tests/Controller/GenericFieldRenderingTest.php` si leurs POST utilisent `name`, `nom`, `ects` (devenus `b[name]`, `b[nom]`, `b[ectsTotal]`).

**Interfaces:**
- Consumes: `CanvasResolver::formEntry()`, `CanvasResolver::form()` (Task 2) ; `FormResolver::fields()`, `TabValidator::check(..., $requiredKeys)` (Task 1).
- Produces:
  - `interface FicheTarget` : `fiche(): string` (`CanvasSpec::FORMATION` ou `CanvasSpec::PARCOURS`) ; `formation(): Formation` ; `parcours(): ?TreeNode` (nœud, ou `null` pour le parcours unique) ; `data(string $entryKey): array` ; `setData(string $entryKey, array $data): void` ; `value(string $fieldKey, array $data): mixed` (champ lié à l'entité si `bound()` le déclare, sinon `$data[$fieldKey] ?? null`) ; `bound(): array<string, mixed>` ; `writeBound(array $input): void` ; `save(): void` (via `Maquette::save()`).
  - `FormationFiche` — liés : `name`, `diplome` (lecture seule), `domaine`, `composante` ; données : `MaquetteDoc::formationParam($entryKey)`.
  - `ParcoursFiche` — liés : `nom` (libellé du nœud), `ectsTotal` (attribut `ects` du nœud) ; données : `TreeNode::getParametre($entryKey)`.
  - `MonoParcoursFiche` — lié : `ectsTotal` (`Formation::ectsTotal`) ; `nom` reste un champ de données ; données : `MaquetteDoc::formationParam('parcours.'.$entryKey)`.
  - `FicheFactory::formation(Formation): FormationFiche`, `parcours(Formation, string $nid): ?ParcoursFiche` (`null` si le nœud n'est pas un parcours), `mono(Formation): MonoParcoursFiche`.
  - `EntryCompletion::fields(FicheTarget $fiche, string $entryKey): list<ResolvedField>` ; `status(FicheTarget $fiche, string $entryKey): string` (`empty` / `incomplete` / `ok` / `error`, même calcul que l'actuel `Completion::paramStatus`, avec les champs et l'obligatoire résolus) ; `issues(FicheTarget $fiche, string $entryKey): array<string, list<string>>`.
  - Routes (noms et chemins conservés pour les deux premiers) : `formation_param` GET / `formation_param_save` POST `/formations/{id}/parametre/{key}` ; `parcours_param` GET / `parcours_param_save` POST `/formations/{fid}/parcours/{nid}/parametre/{key}` ; `parcours_unique_param` GET / `parcours_unique_param_save` POST `/formations/{id}/parcours-unique/parametre/{key}`. 404 si la clé n'est pas une entrée à formulaire visible de la fiche.
  - Gabarit `formation/param/entry.html.twig`, variables : `fiche`, `entryKey`, `label`, `breadcrumb` (list<string>), `fields` (list<ResolvedField>), `data`, `issues`, `hints`, `saveUrl`. Un champ verrouillé dont le gabarit `formation/param/bound/<fiche>/<clé>.html.twig` existe est rendu par ce gabarit (champs postés sous `b[<clé>]`) ; sinon rendu générique.

Règle d'enregistrement : pour chaque champ **du formulaire résolu de l'entrée**, la valeur postée `p[<clé>]` (absente → `null`) remplace la valeur stockée ; les autres clés stockées sont conservées ; les valeurs vides sont retirées. Réponse JSON `{status, issues}` inchangée pour l'enregistrement en direct.

Le récapitulatif en lecture seule « durée / positionnement / spécialisations » de l'actuel `parcours_organisation.html.twig` est retiré : ces informations sont dans l'écran « Positionnement & ramification ».

- [ ] **Step 1: Write the failing tests** — `tests/Controller/FicheEntryTest.php` (WebTestCase + `ResetsDatabase`, formations via `FormationFactory`).

```php
public function testFormationEntryRendersResolvedFields(): void
{
    $this->form('param_presentation_formation')->setFieldSetting('objectif', ['label' => 'But de la formation']);
    $this->em->flush();
    $crawler = $this->client->request('GET', "/formations/{$id}/parametre/presentation");
    self::assertResponseIsSuccessful();
    self::assertStringContainsString('But de la formation', $crawler->filter('.fld-label')->text());
}
public function testSavingKeepsValuesOfFieldsAbsentFromTheForm(): void { /* data presentation {contenu: 'x'} ; canevas de la version : presentation → variante qui masque contenu ; POST p[objectif]='y' → data == {contenu: 'x', objectif: 'y'} */ }
public function testUncheckedMultiChoiceIsCleared(): void { /* parcours organisation regimes=['FI'] ; POST sans p[regimes] → clé 'regimes' absente */ }
public function testBoundFieldsWriteToEntity(): void { /* POST b[name] sur formation organisation → Formation::getName() ; POST b[nom], b[ectsTotal] sur parcours organisation → libellé et attribut ects du nœud */ }
public function testAddedEntryStoresUnderItsKey(): void { /* canevas + entrée {key:'alternance', form:'param_presentation_formation'} ; POST → getParametre('alternance')['objectif'] */ }
public function testHiddenEntryIsNotFound(): void { /* presentation masquée → GET 404 */ }
public function testRequiredOverrideDrivesStatus(): void { /* tous les champs de param_presentation_formation rendus facultatifs par setFieldSetting → status de l'entrée vide == 'ok' ; puis setFieldSetting('objectif', ['required' => true]) → 'empty' */ }
```

- [ ] **Step 2: Run tests to verify they fail** — `docker compose exec -T app php bin/phpunit tests/Controller/FicheEntryTest.php` ; Expected: FAIL.

- [ ] **Step 3: Implement the fiche targets, factory, `EntryCompletion`, controller, entry and bound templates**, retirer l'ancien code (contrôleur, `Completion`, `ParamTypeResolver`, quatre gabarits), réécrire les fonctions Twig `param_status`, `param_issues`, `parcours_param_status`, `parcours_param_issues`, `param_active_fields`, `parcours_param_active_fields`, `param_field_value`, `parcours_field_value` sur `FicheFactory` / `EntryCompletion` (elles disparaissent en Task 4). `field_rule_hints` reçoit le `NodeType` du formulaire de l'entrée.

- [ ] **Step 4: Run tests to verify they pass** — fichier ciblé puis suite complète ; Expected: PASS.

- [ ] **Step 5: Commit** — `git commit -m "Fiches : rendu et enregistrement génériques des entrées"`

---

### Task 4: Navigation, Vérifier, Voir et export enrichi depuis le canevas

**Files:**
- Modify: `templates/formation/editor.html.twig:5-12,139-163`, `templates/formation/parcours_editor.html.twig:82-101`, `src/Controller/FormationController.php:353-410` (`check`), `templates/formation/view.html.twig:86-107,204-260,389`, `src/Maquette/MaquetteExporter.php:52-66` (et la vue des nœuds parcours dans `view()`), `src/Twig/MaquetteExtension.php:72-86`, `src/Formulaire/CanvasResolver.php`
- Test: `tests/Controller/FicheNavigationTest.php` ; mettre à jour `tests/Controller/CheckPageTest.php` et `tests/Controller/PrototypeFeedbackTest.php` s'ils supposent la barre latérale codée en dur.

**Interfaces:**
- Consumes: `CanvasResolver::navigation()` (Task 2), `EntryCompletion`, `FicheFactory` (Task 3).
- Produces:
  - `NavItem::$status` rempli pour les entrées à formulaire (`EntryCompletion::status`).
  - `CanvasResolver::visibleParametres(FicheTarget $fiche, array $stored): array` — retire les clés des entrées masquées, réduit chaque entrée visible aux champs de son formulaire résolu, laisse intactes les clés qui ne sont pas des entrées (`bccReferentiel`, `structure`…).
  - Fonctions Twig : `fiche_nav(Formation $formation, ?TreeNode $parcours = null): list<NavItem>` ; `fiche_entries(FicheTarget $fiche): list<array{key: string, label: string, fields: list<ResolvedField>, data: array}>` (entrées à formulaire visibles, pour la page Voir) ; `fiche_formation(Formation)`, `fiche_parcours(TreeNode)`, `fiche_mono(Formation)` ; `entry_status(FicheTarget, string)`, `entry_issues(FicheTarget, string)`. Les anciennes `param_sections`, `param_status`, `param_issues`, `parcours_param_*`, `param_type_key`, `param_active_fields`, `param_field_value`, `parcours_field_value`, `node_type_param_editable` sont supprimées.

Règles :
- Barres latérales : un lien par `NavItem`, numéro `NavItem::$number`, titre de groupe « Parcours » avant le premier élément de `group = 'parcours'` ; `data-param-status-key="{{ item.key }}"` conservé (rafraîchissement du statut par `autosave_controller.js`). Panneau initial de l'éditeur : l'élément dont `key` vaut le paramètre de requête `param`, sinon l'élément courant d'un écran intégré (`isCurrent`), sinon le comportement actuel.
- Page Vérifier : une ligne par élément de navigation de statut différent de `ok` (formation, puis chaque parcours), libellé = libellé de l'entrée, contexte = libellé du parcours.
- Page Voir : sections de la formation et blocs des nœuds parcours construits par `fiche_entries`. Le bloc codé en dur des informations de parcours d'une formation sans parcours reste en place jusqu'à la Task 5.
- Export : `enriched()` passe les paramètres par `visibleParametres()` (formation, parcours unique, chaque nœud parcours) ; `raw()` est inchangé.

- [ ] **Step 1: Write the failing tests** — `tests/Controller/FicheNavigationTest.php`.

```php
public function testEditorSidebarFollowsCanvas(): void
{
    // canevas : presentation masquée, organisation renommée « Organisation », entrée ajoutée « Alternance » en 2e position
    $crawler = $this->client->request('GET', "/formations/{$id}");
    $items = $crawler->filter('.nav-section')->each(fn ($a) => trim(preg_replace('/\s+/', ' ', $a->text())));
    self::assertSame('1 | Organisation', $items[0]);
    self::assertSame('2 | Alternance', $items[1]);
    self::assertCount(0, $crawler->filter('a[href$="/parametre/presentation"]'));
}
public function testParcoursEditorSidebarFollowsCanvas(): void { /* parcours : bcc masqué → pas de lien bcc_parcours_editor */ }
public function testCheckPageListsOnlyVisibleEntries(): void { /* entrée masquée incomplète absente de /verifier ; entrée visible incomplète présente */ }
public function testViewPageOmitsHiddenEntry(): void { /* données dans presentation masquée → texte absent de /voir */ }
public function testEnrichedExportOmitsHiddenEntryButRawKeepsIt(): void { /* export.json?view=enriched sans 'presentation' ; vue brute avec */ }
```

- [ ] **Step 2: Run tests to verify they fail** — `docker compose exec -T app php bin/phpunit tests/Controller/FicheNavigationTest.php` ; Expected: FAIL.
- [ ] **Step 3: Implement** les gabarits, `check()`, l'export, `visibleParametres()` et le remplacement des fonctions Twig.
- [ ] **Step 4: Run tests to verify they pass** — fichier ciblé puis suite complète ; `docker compose exec -T app php bin/console lint:twig templates/` → OK.
- [ ] **Step 5: Commit** — `git commit -m "Fiches : barres latérales, Vérifier, Voir et export générés depuis le canevas"`

---

### Task 5: Formation sans parcours

**Files:**
- Create: `src/Formulaire/Fiche/ParcoursFicheTransfer.php`
- Modify: `templates/formation/param/structure.html.twig` (retrait des champs de parcours : `intituleMono`, `ectsTotal`, `composante`, `regimes`, `modalitesAlternance`, `langue`, `poursuiteEtudes`, `debouches`, `codesRome`), `src/Controller/FormationController.php` (`settings` : retrait du bloc « propriétés mono-parcours rangées dans parametres.structure » ; `reshapeParcoursLevel` : appel du transfert), `src/Formulaire/CanvasResolver.php` (`navigation()` : groupe parcours), `templates/formation/view.html.twig:236-247,376-378` (bloc codé en dur remplacé par les entrées de `fiche_mono`), `src/DataFixtures/FormationFixtures.php:245-250` (données de parcours unique déplacées sous `parcours.organisation` / `parcours.presentation`)
- Test: `tests/Controller/MonoParcoursFicheTest.php`

**Interfaces:**
- Consumes: `MonoParcoursFiche`, `ParcoursFiche`, `FicheFactory` (Task 3) ; `CanvasResolver::forFormation()`.
- Produces:
  - `CanvasResolver::navigation($formation)` d'une formation sans parcours : à la suite des entrées de la fiche formation, les entrées **à formulaire** visibles de la fiche parcours, `group = 'parcours'`, numérotation continue, URL `parcours_unique_param`.
  - `ParcoursFicheTransfer::toNode(Formation $formation, MaquetteDoc $doc, TreeNode $parcours): void` (copie chaque entrée à formulaire de la fiche parcours de `parcours.<clé>` vers le nœud, `Formation::ectsTotal` → attribut `ects`, champ `nom` trouvé dans une entrée → libellé du nœud, puis vide la source) ; `fromNode(...)` (sens inverse).

- [ ] **Step 1: Write the failing tests** — `tests/Controller/MonoParcoursFicheTest.php`.

```php
public function testStructureScreenNoLongerShowsParcoursFields(): void { /* GET /formations/{id}/structure : aucun input name p[intituleMono], regimes[], ectsTotal */ }
public function testMonoFormationSidebarShowsParcoursGroup(): void { /* navigation() : ['1 organisation -', '2 presentation -', '3 bcc -', '4 structure -', '5 organisation parcours', '6 presentation parcours'] ; éditeur : titre de groupe « Parcours » puis liens vers /parcours-unique/parametre/organisation et /presentation */ }
public function testViewPageShowsMonoParcoursEntries(): void { /* donnée sous parcours.presentation → visible sur /voir */ }
public function testMonoParcoursEntrySavesOnFormation(): void
{
    $this->client->request('POST', "/formations/{$id}/parcours-unique/parametre/organisation", ['p' => ['regimes' => ['FI']]]);
    self::assertSame(['FI'], $this->reload($formation)->getParametre('parcours.organisation')['regimes']);
}
public function testMonoEctsTotalWritesFormation(): void { /* b[ectsTotal]=180 → Formation::getEctsTotal() === 180 */ }
public function testSwitchingParcoursModeKeepsParcoursData(): void { /* données parcours unique + ectsTotal ; POST settings avecParcours=1 (admin) → nœud parcours porte les données et ects ; retour avecParcours=0 → données revenues sous parcours.<clé> */ }
```

- [ ] **Step 2: Run tests to verify they fail** — `docker compose exec -T app php bin/phpunit tests/Controller/MonoParcoursFicheTest.php` ; Expected: FAIL.
- [ ] **Step 3: Implement** le transfert, le nettoyage de l'écran Structure et de `settings`, l'URL du groupe, les fixtures.
- [ ] **Step 4: Run tests to verify they pass** — fichier ciblé puis suite complète.
- [ ] **Step 5: Commit** — `git commit -m "Formation sans parcours : fiche parcours du canevas, plus de champs en dur"`

---

### Task 6: Étape et onglet « Formulaires » : édition du canevas

**Files:**
- Create: `src/Diplome/Wizard/CanvasStep.php`, `src/Form/Diplome/CanvasType.php`, `src/Form/Diplome/CanvasEntryType.php`
- Modify: `templates/diplome/wizard/forms.html.twig` (réécrit), `src/Diplome/Wizard/ConfigPresenter.php:45,56-66,146-155`, `translations/diplome.fr.yaml` (clés `diplome.wizard.formulaires.*`, `diplome.wizard.path.canvas|formation|parcours|form|label|hidden`)
- Delete: `src/Diplome/Wizard/FormsStep.php`, `src/Form/Diplome/FormsType.php`
- Test: `tests/Controller/DiplomeCanvasStepTest.php` ; mettre à jour `tests/Controller/DiplomeWizardTest.php` (lignes 93-105, 160-166, 192-225 : champs `canvas[formation][<i>][form]` au lieu de `forms[...]`, ordre des étapes).

**Interfaces:**
- Consumes: `CanvasResolver::forConfig()`, `CanvasSpec`, `BuiltinScreenRegistry`, `FormResolver::fields()` (Tasks 1-2).
- Produces:
  - `CanvasStep` : `key() = 'formulaires'`, `position() = 45` (après `structure` 40, avant `mccc` 50), `editable() = true`, `formType() = CanvasType::class`, `template() = 'forms'`. `initialData(WizardDraft)` : `['formation' => rows, 'parcours' => rows]`, une ligne par entrée de `forConfig($draft->config())` : `{key, builtin, label, form, hidden}`. `apply()` : construit un `CanvasSpec` dans l'ordre soumis ; une ligne sans `key` reçoit le slug de son libellé, rendu unique dans la fiche (`-2`, `-3`…) ; les écrans intégrés manquants sont rajoutés ; écrit `configData['canvas']` et retire `configData['forms']`. `viewData()` : bibliothèque (formulaires des portées formation et parcours, variantes sous leur parent, nombre de versions utilisatrices via `FormUsage` de Task 7 — d'ici là, sans compteur).
  - `CanvasType` : deux `CollectionType` `formation` et `parcours` d'`CanvasEntryType` (`allow_add`, `allow_delete`, `prototype`, option d'entrée `fiche`). `CanvasEntryType` : `key` (Hidden), `builtin` (Hidden), `label` (Text, facultatif ; obligatoire pour une ligne nouvelle), `form` (Choice parmi les `NodeType` de famille `Parameter` dont `getScope()` vaut la fiche ; absent pour un écran intégré), `hidden` (Checkbox).
  - Contrainte `Callback` de `CanvasType`, messages dans le domaine `diplome` : ligne à formulaire sans formulaire → `diplome.wizard.formulaires.error.no_form` ; écran intégré non masquable masqué → `…error.not_hideable` (`%label%`) ; **champs verrouillés** : pour chaque fiche, l'ensemble des `FieldDef` verrouillés actifs dans au moins un formulaire de la portée doit être couvert par les formulaires résolus des entrées visibles, sinon `…error.locked_missing` (`%field%` = libellé du champ).
  - `ConfigPresenter` : section « Formulaires » = une ligne par entrée et par fiche : `« <n> | <libellé> »` → clé du formulaire ou « Écran intégré », suffixe « (masquée) » ; `pathLabel('canvas.formation.1.form')` → « Formulaires › Fiche formation › #2 › Formulaire ».

Gabarit `forms.html.twig` : maquette validée `.superpowers/brainstorm/63540-1792570480/content/canevas.html`, bloc « Fiches des éléments » exclu. Chaque bloc de fiche est une section `data-controller="collection"` (contrôleur existant : `add`, `up`, `down`, `remove`) ; ligne `data-entry="<clé>"` ; « Retirer » absent sur les écrans intégrés ; « Masquer » absent sur les écrans non masquables ; colonne de droite : la bibliothèque. Les boutons « Adapter », « Modifier » et « Aperçu » sont ajoutés en Tasks 8 et 9.

- [ ] **Step 1: Write the failing tests** — `tests/Controller/DiplomeCanvasStepTest.php` (même socle que `DiplomeWizardTest` : `ResetsDatabase`, `SameOriginCsrf`, `submitInformations`).

```php
public function testFormulairesStepComesAfterStructure(): void
{
    $keys = static::getContainer()->get(WizardStepRegistry::class)->keys();
    self::assertSame(array_search('structure', $keys) + 1, array_search('formulaires', $keys));
}
public function testCanvasRowsReproduceDefault(): void { /* GET /diplomes/nouveau/formulaires : [data-entry] de la fiche formation = organisation, presentation, bcc, ramification, structure */ }
public function testHideRenameReorderAndAddEntry(): void
{
    // soumission : presentation masquée, organisation renommée « Organisation », ligne ajoutée « Alternance » (param_presentation_formation) placée en 2e
    $canvas = $this->draft()->config()->canvas;
    self::assertSame(['organisation', 'alternance', 'presentation', 'bcc', 'ramification', 'structure'], array_map(fn ($e) => $e->key, $canvas->formation));
    self::assertTrue($canvas->entry('formation', 'presentation')->hidden);
    self::assertSame('Organisation', $canvas->entry('formation', 'organisation')->label);
}
public function testBuiltinsCannotBeRemoved(): void { /* soumission sans ligne structure → canevas enregistré la contient */ }
public function testStructureCannotBeHidden(): void { /* structure hidden → 422 + message not_hideable */ }
public function testLockedFieldsMustStayVisible(): void { /* organisation masquée → 422 + message locked_missing citant le libellé de 'name' */ }
public function testFormChoicesFilteredByScope(): void { /* options des lignes formation : uniquement des formulaires de portée formation */ }
public function testEditTabRequiresImpactConfirmation(): void { /* onglet d'une version utilisée, sans confirmImpact → 422 ; avec → redirection et canevas enregistré */ }
public function testSummaryShowsCanvasAndDifferences(): void { /* depuis Licence, presentation masquée → récapitulatif [data-diff] [data-path="canvas.formation.1.hidden"] */ }
```

- [ ] **Step 2: Run tests to verify they fail** — `docker compose exec -T app php bin/phpunit tests/Controller/DiplomeCanvasStepTest.php` ; Expected: FAIL.
- [ ] **Step 3: Implement** l'étape, les types de formulaire, la contrainte, le gabarit, le récapitulatif, les traductions ; supprimer `FormsStep` et `FormsType`.
- [ ] **Step 4: Run tests to verify they pass** — fichier ciblé, `tests/Controller/DiplomeWizardTest.php` mis à jour, puis suite complète.
- [ ] **Step 5: Commit** — `git commit -m "Diplômes : l'onglet Formulaires édite le canevas des fiches"`

---

### Task 7: Bibliothèque : variantes, réglages par formulaire et confirmation d'impact

**Files:**
- Create: `src/Formulaire/VariantFactory.php`, `src/Formulaire/FormUsage.php`, `assets/controllers/impact_controller.js`
- Modify: `src/Controller/NodeTypeController.php` (index, edit, fieldAdd, fieldEdit, separatorAdd, fieldsReorder, fieldRemove, ruleAdd, ruleRemove, delete ; retrait de `EDITABLE_PARAM_KEYS` et de `assertEditable` pour la famille `Parameter`), `templates/node_type/index.html.twig`, `templates/node_type/edit.html.twig`, `src/Diplome/Wizard/CanvasStep.php` (compteur d'usage dans `viewData`), `translations/admin.fr.yaml`
- Test: `tests/Controller/FormLibraryTest.php` ; mettre à jour les tests qui visent les pseudo-types ou `EDITABLE_PARAM_KEYS` (`grep -rn "EDITABLE_PARAM_KEYS\|param_bcc\|param_structure" tests`).

**Interfaces:**
- Consumes: `FormResolver` (Task 1), `CanvasResolver::forConfig()` (Task 2).
- Produces:
  - `VariantFactory::create(NodeType $parent, ?string $label = null): NodeType` — persisté (sans flush) ; clé `<clé du parent>_<slug du libellé>` rendue unique ; libellé par défaut « <libellé du parent> · variante » ; famille, portée et icône du parent ; `knownParentFields` = clés visibles du parent.
  - `FormUsage::versionsUsing(NodeType $form): list<TypeDiplomeVersion>` (une version utilise un formulaire si une entrée de son canevas résolu, masquée ou non, désigne ce formulaire ou une de ses variantes descendantes) ; `isUsed(NodeType $form): bool` ; `hasVariants(NodeType $form): bool`.
  - Routes : `node_type_variant_new` POST `/administration/node-types/{id}/variante` (champ `label`) ; `node_type_field_visibility` POST `/administration/node-types/{id}/champs/{fieldId}/affichage` (champ `visible` 0/1) ; `node_type_variant_ack` POST `/administration/node-types/{id}/nouveautes-vues`.

Règles :
- **Réglages par formulaire.** `fieldEdit` écrit libellé, aide, obligatoire et onglet dans `NodeType::setFieldSetting()` du formulaire édité (socle ou variante) ; seuls le type, les options, le référentiel, les bornes et le texte d'invite restent sur `FieldDef` (`applyFieldRequest` sans libellé, aide ni obligatoire). `fieldAdd` d'un nouveau champ initialise les valeurs par défaut de `FieldDef` avec la saisie. `fieldsReorder` écrit les positions dans les réglages du formulaire. `separatorAdd` crée le `FieldDef` séparateur et active sa capacité sur ce formulaire.
- **Variantes.** `fieldRemove` sur une variante : champ hérité → capacité `false` (masqué) ; champ ajouté par la variante → capacité retirée. `node_type_field_visibility` bascule la capacité de la variante ; refusé (message flash) pour un champ verrouillé.
- **Impact.** Si `FormUsage::versionsUsing()` n'est pas vide, la page d'édition liste les diplômes et années concernés, et chaque POST de modification exige `confirmImpact=1` ; sinon message `admin.node_type.impact_required` et redirection sans rien changer (requête AJAX de réordonnancement : réponse 409). `impact_controller.js` recopie la case « Je confirme… » de la page dans chaque formulaire et dans les requêtes de réordonnancement.
- **Suppression** refusée si `isUsed()` ou `hasVariants()`.
- **Liste** (`node_type_index`) : section « Formulaires de fiche » ; socles, puis leurs variantes en retrait, avec le nombre de versions utilisatrices et le bouton « Créer une variante ».
- **Écran « Adapter »** (édition d'une variante, maquette `adapter.html`) : en-tête « variante de <parent> » et diplômes utilisateurs ; lignes issues de `FormResolver::fields($form, withHidden: true)` avec badges d'origine, interrupteurs « Affiché » (route visibilité) et « Obligatoire » (via `fieldEdit`) ; bandeau des `newParentFields()` avec le bouton « Vu » (`node_type_variant_ack`). Lien « Retour au canevas » si la requête porte `retour` (URL relative commençant par `/diplomes/`).

- [ ] **Step 1: Write the failing tests** — `tests/Controller/FormLibraryTest.php`.

```php
public function testCreateVariantFromSocle(): void
{
    $this->client->request('POST', "/administration/node-types/{$socleId}/variante", ['label' => 'Présentation allégée']);
    $variant = $this->repo->findOneBy(['label' => 'Présentation allégée']);
    self::assertSame('param_presentation_formation', $variant->getParent()->getKey());
    self::assertSame(FormScope::Formation, $variant->getScope());
    self::assertResponseRedirects("/node-types/{$variant->getId()}/edit");
}
public function testEditingFieldLabelOnSocleStaysOnThatForm(): void { /* POST fieldEdit label 'But' sur presentation_formation → FieldDef inchangé ; libellé résolu de presentation_parcours inchangé */ }
public function testHidingInheritedFieldInVariant(): void { /* visibilité 0 sur contenu → capabilities()['contenu'] === false côté variante */ }
public function testLockedFieldCannotBeHidden(): void { /* visibilité 0 sur 'name' (variante d'organisation) → capacité inchangée */ }
public function testModificationOfUsedFormRequiresImpactConfirmation(): void { /* canevas BUT → variante ; fieldEdit sans confirmImpact → inchangé ; avec → modifié */ }
public function testUsedFormCannotBeDeleted(): void {}
public function testFormWithVariantCannotBeDeleted(): void {}
public function testNewParentFieldsBannerUntilAcknowledged(): void { /* capacité ajoutée au parent → bandeau [data-new-parent-fields] ; après « Vu » → absent */ }
public function testIndexShowsVariantsUnderTheirParentWithUsage(): void {}
```

- [ ] **Step 2: Run tests to verify they fail** — `docker compose exec -T app php bin/phpunit tests/Controller/FormLibraryTest.php` ; Expected: FAIL.
- [ ] **Step 3: Implement** fabrique, usage, routes, règles du constructeur, gabarits, contrôleur Stimulus, traductions.
- [ ] **Step 4: Run tests to verify they pass** — fichier ciblé puis suite complète.
- [ ] **Step 5: Commit** — `git commit -m "Bibliothèque de formulaires : variantes, réglages par formulaire, confirmation d'impact"`

---

### Task 8: « Adapter » et « Modifier » depuis le canevas

**Files:**
- Create: `src/Controller/CanvasController.php`
- Modify: `templates/diplome/wizard/forms.html.twig`, `translations/diplome.fr.yaml`
- Test: `tests/Controller/CanvasAdaptTest.php`

**Interfaces:**
- Consumes: `VariantFactory::create()` (Task 7), `CanvasStep`/`CanvasSpec::withEntries()` (Tasks 2, 6), `WizardDraft`.
- Produces: routes `diplome_canvas_adapt` POST `/diplomes/{id}/versions/{versionId}/formulaires/adapter` et `diplome_wizard_canvas_adapt` POST `/diplomes/nouveau/formulaires/adapter` ; champs `fiche`, `entry` (clé d'entrée). Effet : variante du formulaire de l'entrée, libellé « <libellé du formulaire> · <sigle du diplôme> » (sigle du brouillon dans l'assistant), entrée pointée sur la variante (version enregistrée sans confirmation d'impact : la variante neuve est identique à son parent ; dans l'assistant, brouillon en session), redirection vers `node_type_edit` avec `retour` = URL du canevas.

Chaque ligne à formulaire du canevas porte un petit formulaire « Adapter » (POST, jeton CSRF) et un lien « Modifier » vers `node_type_edit` du formulaire choisi avec `retour`. Le formulaire principal porte `data-controller="dirty-form"` (contrôleur existant) pour prévenir de la perte d'une saisie non enregistrée.

- [ ] **Step 1: Write the failing tests** — `tests/Controller/CanvasAdaptTest.php`.

```php
public function testAdaptCreatesVariantAndSelectsItInVersion(): void
{
    $this->client->request('POST', $this->editPath($but, 'formulaires').'/adapter', ['fiche' => 'formation', 'entry' => 'presentation', '_token' => $this->csrfToken()]);
    $variant = $this->variantOf('param_presentation_formation');
    self::assertSame('Présentation · BUT', $variant->getLabel());
    self::assertSame($variant->getKey(), $this->reloadVersion($but)->config()->canvas->entry('formation', 'presentation')->form);
    self::assertResponseRedirects(sprintf('/node-types/%d/edit?retour=%s', $variant->getId(), rawurlencode($this->editPath($but, 'formulaires'))));
}
public function testAdaptInWizardSelectsVariantInDraft(): void {}
public function testModifyLinkPointsToSelectedForm(): void {}
```

- [ ] **Step 2: Run tests to verify they fail** — `docker compose exec -T app php bin/phpunit tests/Controller/CanvasAdaptTest.php` ; Expected: FAIL.
- [ ] **Step 3: Implement** les deux routes et les boutons du canevas.
- [ ] **Step 4: Run tests to verify they pass** — fichier ciblé puis suite complète.
- [ ] **Step 5: Commit** — `git commit -m "Canevas : adapter un formulaire crée et choisit une variante"`

---

### Task 9: Aperçu sur la vraie page

**Files:**
- Create: `src/Formulaire/Preview/PreviewContext.php`, `src/Formulaire/Preview/PreviewHostFinder.php`, `src/EventSubscriber/PreviewGuardSubscriber.php`, `templates/formation/_preview_banner.html.twig`
- Modify: `src/Controller/CanvasController.php`, `src/Controller/NodeTypeController.php`, `src/Formulaire/CanvasResolver.php` (`forFormation` consulte d'abord `PreviewContext`), `templates/formation/editor.html.twig`, `templates/formation/parcours_editor.html.twig`, `templates/formation/param/entry.html.twig` (champs dans un `<fieldset disabled>` pendant l'aperçu), `templates/diplome/wizard/forms.html.twig`, `templates/node_type/edit.html.twig`, `src/Twig/MaquetteExtension.php` (+ `preview_active(Formation): bool`)
- Test: `tests/Controller/CanvasPreviewTest.php`

**Interfaces:**
- Consumes: `CanvasType` (Task 6) pour lire le canevas soumis sans l'enregistrer ; `VersionUsage::formationsUsing()`, `formationsOfType()` ; `FormResolver::lineage()`.
- Produces:
  - `PreviewContext::start(Formation $host, CanvasSpec $canvas, string $returnUrl): void` ; `canvasFor(Formation $formation): ?CanvasSpec` (seulement pour l'hôte) ; `isActive(Formation $formation): bool` ; `returnUrl(): ?string` ; `stop(): void`. Stockage : session, clé `canvas_preview` = `{formationId, canvas, returnUrl}`.
  - `PreviewHostFinder::find(?TypeDiplomeVersion $version, ?TypeDiplome $type, bool $needsParcours): ?Formation` — dans l'ordre : formations de la version, du type, puis toutes ; avec `$needsParcours`, une formation avec parcours est préférée à chaque niveau.
  - Routes : `diplome_canvas_preview` POST `/diplomes/{id}/versions/{versionId}/formulaires/apercu` et `diplome_wizard_canvas_preview` POST `/diplomes/nouveau/formulaires/apercu` (formulaire du canevas + champ `fiche`) ; `node_type_preview` POST `/administration/node-types/{id}/apercu` ; `diplome_canvas_preview_stop` POST `/diplomes/apercu/quitter`.

Règles :
- Le canevas soumis est lu par `CanvasType` sans être enregistré ; s'il est invalide, la page du canevas est rendue avec ses erreurs (422).
- Redirection : fiche formation → `formation_editor` de l'hôte ; fiche parcours → `parcours_editor` de son premier parcours, ou éditeur de formation si l'hôte n'a pas de parcours.
- Depuis la bibliothèque : hôte = formation d'une version qui utilise le formulaire, sinon `PreviewHostFinder` ; canevas = celui de l'hôte où la première entrée dont le formulaire est dans la lignée du formulaire prévisualisé (ou l'inverse) pointe sur lui ; à défaut, une entrée `apercu` ajoutée en fin de fiche. Les modifications du constructeur étant enregistrées une à une, l'aperçu montre toujours l'état enregistré.
- Pendant l'aperçu, un bandeau « Aperçu de la configuration — rien n'est enregistré » avec « Quitter l'aperçu » s'affiche dans les éditeurs de l'hôte. `PreviewGuardSubscriber` (`kernel.request`) refuse toute requête non GET dont l'attribut de route `id` ou `fid` vaut l'id de l'hôte (403 ; JSON `{error}` pour une requête qui accepte JSON), sauf `diplome_canvas_preview_stop`.

- [ ] **Step 1: Write the failing tests** — `tests/Controller/CanvasPreviewTest.php`.

```php
public function testPreviewShowsUnsavedCanvasOnRealEditor(): void
{
    $values = $this->canvasFormValues($but);              // GET onglet, getPhpValues()
    $values['canvas']['formation'][1]['hidden'] = '1';     // presentation masquée, non enregistré
    $this->client->request('POST', $this->editPath($but, 'formulaires').'/apercu', $values + ['fiche' => 'formation']);
    self::assertResponseRedirects("/formations/{$host->getId()}");
    $crawler = $this->client->followRedirect();
    self::assertCount(0, $crawler->filter('a[href$="/parametre/presentation"]'));
    self::assertFalse($this->reloadVersion($but)->config()->canvas?->entry('formation', 'presentation')?->hidden ?? false);
}
public function testPreviewIsReadOnly(): void { /* aperçu actif → POST formation_param_save de l'hôte → 403, données inchangées */ }
public function testQuitPreviewRestoresRealCanvas(): void { /* POST /diplomes/apercu/quitter → redirection vers le canevas ; éditeur de l'hôte montre de nouveau presentation */ }
public function testPreviewOnlyAffectsHostFormation(): void { /* autre formation : barre latérale complète, enregistrement possible */ }
public function testPreviewWithoutFormationOfVersionUsesAnotherFormation(): void { /* version neuve sans formation → hôte d'une autre version du type */ }
public function testPreviewFromLibraryShowsVariant(): void { /* variante qui renomme objectif → éditeur de l'hôte, entrée presentation : nouveau libellé */ }
```

- [ ] **Step 2: Run tests to verify they fail** — `docker compose exec -T app php bin/phpunit tests/Controller/CanvasPreviewTest.php` ; Expected: FAIL.
- [ ] **Step 3: Implement** le contexte, la recherche d'hôte, les routes, le bandeau, le garde et les boutons « Aperçu » (un par bloc de fiche du canevas, via `formaction` sur le formulaire principal ; un dans l'en-tête de l'édition d'un formulaire).
- [ ] **Step 4: Run tests to verify they pass** — fichier ciblé puis suite complète.
- [ ] **Step 5: Commit** — `git commit -m "Canevas : aperçu en lecture seule sur la vraie page de formation"`

---

### Task 10: Documentation et nettoyage final

**Files:**
- Modify: `docs/diplomes.md` (En bref ; Vocabulaire : canevas, entrée, écran intégré, socle, variante, bibliothèque ; Assistant : ordre des étapes et nouvelle étape « Formulaires » ; Modifier une version ; Côté formation : barre latérale générée, formation sans parcours ; Où est quoi : lignes `src/Formulaire/…` à la place de `ParamTypeResolver` ; Étendre : « Nouvel écran intégré » = une classe qui implémente `BuiltinScreenInterface` ; Limites connues : fiches des éléments au lot 2)
- Test: suite complète

- [ ] **Step 1: Check that no obsolete reference remains**

Run: `grep -rn "ParamTypeResolver\|PARAM_SECTIONS\|EDITABLE_PARAM_KEYS\|FormsStep\|FormsType\|param_sections\|param_type_key\|->forms\b\|intituleMono" src templates tests assets docs/diplomes.md`
Expected: aucune ligne.

- [ ] **Step 2: Update `docs/diplomes.md`.**
- [ ] **Step 3: Run the full suite and the linters** — `docker compose exec -T app php bin/phpunit` → PASS ; `docker compose exec -T app php bin/console lint:twig templates/` et `lint:container` → OK.
- [ ] **Step 4: Reset the local database and check by hand** — `make db-reset`, puis ouvrir `/diplomes/5/versions/5/formulaires` : masquer, renommer, ajouter une entrée, Aperçu, Adapter.
- [ ] **Step 5: Commit** — `git commit -m "Doc : canevas des formulaires, bibliothèque et variantes"`
