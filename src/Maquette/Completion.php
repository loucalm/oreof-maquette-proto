<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Diplome\Check\StructureChecker;
use App\Diplome\Check\StructureViolation;
use App\Diplome\ParamTypeResolver;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\FieldDef;
use App\Entity\Formation;
use App\Entity\NodeType;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Repository\FieldDefRepository;
use App\Repository\NodeTypeRepository;
use App\Validation\TabValidator;

/**
 * Comptage des champs requis remplis / total, par nœud et remonté sur l'arbre.
 *
 * La progression n'est plus recalculée à chaque affichage de la liste des
 * formations : `Maquette::save()` appelle `docStats()` et range le résultat dans
 * `Formation::stats` (JSON). La liste lit ce cache directement.
 */
final class Completion
{
    public function __construct(
        private readonly AttributeCatalog $catalog,
        private readonly McccValidator $mcccValidator,
        private readonly NodeTypeRepository $types,
        private readonly FieldDefRepository $fields,
        private readonly TabValidator $validator,
        private readonly StructureChecker $structureChecker,
        private readonly TypeDiplomeResolver $resolver,
        private readonly ParamTypeResolver $paramTypes,
    ) {
    }

    /**
     * Champs requis de CE nœud seul.
     *
     * @return array{req: int, filled: int}
     */
    public function node(TreeNode $node): array
    {
        $missing = $this->missing($node);
        $req = $this->requiredCount($node) + $this->ruleCheck($node)['units'];

        return ['req' => $req, 'filled' => $req - \count($missing)];
    }

    /**
     * Champs requis manquants (ou invalides) de CE nœud seul, dans l'ordre
     * d'affichage : source unique pour le statut, « Vérifier la saisie » et la
     * liste « À compléter » du panneau.
     *
     * @return list<array{key: string, label: string, tab: string, detail?: string, invalid?: bool}>
     */
    public function missing(TreeNode $node): array
    {
        $caps = $node->effectiveCapabilities();
        $out = [];

        // libellé requis partout, sauf types temporels (nom calculé, jamais saisi)
        if (!$node->getType()->isTemporel() && trim($node->getLabel()) === '') {
            $out[] = ['key' => 'label', 'label' => 'libellé', 'tab' => 'props'];
        }
        if (($caps['code'] ?? false) && 'ec' === $node->getType()->getKey() && trim((string) $node->getCode()) === '') {
            $out[] = ['key' => 'code', 'label' => "code de l'EC", 'tab' => 'props'];
        }

        foreach ($this->catalog->all() as $key => $def) {
            if (!($caps[$key] ?? false) || !($def['required'] ?? false)) {
                continue;
            }
            $v = $node->getAttribute($key);
            $provided = $key === 'hours'
                ? AttributeCatalog::hoursProvided($v)
                : !($v === null || $v === '' || $v === []);
            if (!$provided) {
                $out[] = ['key' => $key, 'label' => mb_strtolower($def['label']), 'tab' => $def['domain']];
            } elseif ($key === 'mccc' && $this->mcccValidator->hasError($node)) {
                $out[] = [
                    'key' => $key,
                    'label' => 'MCCC non conformes',
                    'tab' => $def['domain'],
                    'detail' => implode(' · ', $this->mcccValidator->errorMessages($node)),
                ];
            }
        }

        // règles des onglets (obligatoire sous condition, au moins un parmi) et bornes des champs
        foreach ($this->ruleCheck($node)['issues'] as $issue) {
            $out[] = ['key' => $issue['key'], 'label' => $issue['label'], 'tab' => $issue['tab'], 'invalid' => 'invalid' === $issue['kind']];
        }

        return $out;
    }

    /** @return array{units: int, issues: list<array{key: string, label: string, tab: string, kind: string}>} */
    private function ruleCheck(TreeNode $node): array
    {
        return $this->validator->check(
            $node->getType(),
            static fn (string $key) => $node->getAttribute($key),
            array_keys(array_filter($node->effectiveCapabilities())),
        );
    }

    /** Nombre de champs requis de CE nœud seul. */
    private function requiredCount(TreeNode $node): int
    {
        $caps = $node->effectiveCapabilities();
        $req = $node->getType()->isTemporel() ? 0 : 1;
        if (($caps['code'] ?? false) && 'ec' === $node->getType()->getKey()) {
            ++$req;
        }
        foreach ($this->catalog->all() as $key => $def) {
            if (($caps[$key] ?? false) && ($def['required'] ?? false)) {
                ++$req;
            }
        }

        return $req;
    }

    /**
     * Ce nœud + toute sa descendance pédagogique (hors famille compétence).
     *
     * @return array{req: int, filled: int}
     */
    public function rollup(TreeNode $node): array
    {
        $acc = $this->node($node);
        foreach ($node->getChildren() as $child) {
            if ($child->isCompetenceNode()) {
                continue;
            }
            $sub = $this->rollup($child);
            $acc['req'] += $sub['req'];
            $acc['filled'] += $sub['filled'];
        }

        return $acc;
    }

    /**
     * Écarts de la maquette à la configuration de sa version de type de diplôme
     * (vide pour une formation sans version). Une violation non bloquante compte
     * comme élément incomplet, une violation bloquante comme erreur.
     *
     * @return list<StructureViolation>
     */
    public function structureViolations(MaquetteDoc $doc): array
    {
        $config = $this->resolver->configFor($doc->formation);

        return null === $config ? [] : $this->structureChecker->check($doc, $config);
    }

    /** @param array{req: int, filled: int} $s */
    public function pct(array $s): int
    {
        return $s['req'] === 0 ? 100 : (int) round($s['filled'] / $s['req'] * 100);
    }

    /**
     * Statistiques d'une maquette, rangées dans Formation::stats.
     *
     * Choix de formule : chaque violation de structure (bloquante ou non) ajoute UN
     * champ requis non rempli à `req` (jamais à `filled`) : la progression baisse
     * donc tant que la maquette s'écarte de sa version, sans pondération par gravité.
     *
     * `structure` : violations de la version du type de diplôme, `incomplete`
     * (non bloquantes, comptées parmi les éléments à compléter) et `blocking` (erreurs).
     *
     * @return array{progress: int, req: int, filled: int, structure: array{incomplete: int, blocking: int}, parcours: list<array<string, mixed>>}
     */
    public function docStats(MaquetteDoc $doc): array
    {
        $overall = ['req' => 0, 'filled' => 0];
        foreach ($doc->pedagogicalRoots() as $root) {
            $s = $this->rollup($root);
            $overall['req'] += $s['req'];
            $overall['filled'] += $s['filled'];
        }

        $violations = $this->structureViolations($doc);
        $blocking = \count(array_filter($violations, static fn (StructureViolation $v): bool => $v->blocking));
        $overall['req'] += \count($violations);

        $parcours = [];
        // référentiel national porté par la formation : rien à saisir — même règle que MaquetteExtension::bccStatus()
        $but = $doc->formation->hasBccReferentiel();
        if ($doc->formation->isAvecParcours()) {
            foreach ($doc->parcoursNodes() as $p) {
                $ps = $this->rollup($p);
                $parcours[] = [
                    'nid' => $p->getId(),
                    'label' => $p->getDisplayLabel(),
                    'debut' => $p->getPeriodeDebut(),
                    'fin' => $p->getPeriodeFin(),
                    'parent' => $p->getParcoursParent()?->getDisplayLabel(),
                    'progress' => $this->pct($ps),
                    'bcc' => $but ? 'ok' : $this->bccStatus($p->getBccBlocs()),
                ];
            }
        }

        return [
            'progress' => $this->pct($overall),
            'req' => $overall['req'],
            'filled' => $overall['filled'],
            'structure' => ['incomplete' => \count($violations) - $blocking, 'blocking' => $blocking],
            'parcours' => $parcours,
        ];
    }

    /**
     * Statut d'une section « Paramètre de la formation » (pastille de l'arbre) :
     * « empty » si aucun champ requis n'est rempli, « ok » si tous le sont,
     * « incomplete » sinon. Pilotée par les FieldDef.required actifs sur le
     * NodeType `param_{key}_formation` — plus de liste de clés codée en dur.
     */
    public function formationParamStatus(Formation $formation, string $key): string
    {
        if ('structure' === $key) {
            return [] === $formation->getStructure() ? 'empty' : 'ok';
        }

        $type = $this->paramTypes->typeFor($formation, ParamTypeResolver::SCOPE_FORMATION, $key);
        $data = $formation->getParametre($key);

        return null === $type ? 'empty' : $this->paramStatus($type, fn (string $k) => $this->formationFieldValue($formation, $k, $data));
    }

    /**
     * Problèmes d'une section « Paramètre de la formation », par champ, pour les
     * afficher sous chaque champ (obligatoire sous condition, bornes).
     *
     * @return array<string, list<string>>
     */
    public function formationParamIssues(Formation $formation, string $key): array
    {
        $type = $this->paramTypes->typeFor($formation, ParamTypeResolver::SCOPE_FORMATION, $key);
        $data = $formation->getParametre($key);

        return null === $type ? [] : $this->paramIssues($type, fn (string $k) => $this->formationFieldValue($formation, $k, $data));
    }

    /**
     * Statut commun aux sections Paramètre : « empty » si rien de ce qui est attendu
     * n'est fait, « ok » si tout l'est, « error » si une valeur saisie est invalide
     * (bornes), « incomplete » sinon. Attendu = champs obligatoires actifs + règles
     * de l'onglet déclenchées.
     *
     * @param callable(string): mixed $value
     */
    private function paramStatus(NodeType $type, callable $value): string
    {
        $fields = $this->fields->activeOrderedFor($type);
        $required = array_values(array_filter($fields, static fn (FieldDef $f) => $f->isRequired()));
        $check = $this->validator->check($type, $value, array_map(static fn (FieldDef $f) => $f->getKey(), $fields));

        $expected = \count($required) + $check['units'];
        $done = $check['units'] - \count($check['issues']);
        foreach ($required as $f) {
            $done += $this->isFilled($value($f->getKey())) ? 1 : 0;
        }

        return match (true) {
            [] !== array_filter($check['issues'], static fn (array $i) => 'invalid' === $i['kind']) => 'error',
            $done === $expected => 'ok',
            0 === $done => 'empty',
            default => 'incomplete',
        };
    }

    /**
     * @param callable(string): mixed $value
     *
     * @return array<string, list<string>>
     */
    private function paramIssues(NodeType $type, callable $value): array
    {
        $keys = array_map(static fn (FieldDef $f) => $f->getKey(), $this->fields->activeOrderedFor($type));
        $out = [];
        foreach ($this->validator->check($type, $value, $keys)['issues'] as $issue) {
            $out[$issue['key']][] = $issue['label'];
        }

        return $out;
    }

    /**
     * Valeur d'un champ « Paramètre de la formation » : lue sur l'entité pour les
     * quelques champs verrouillés qui lui sont propres (cf. FieldDef::isLocked()),
     * sinon dans le blob générique `parametres[section]`. Réutilisé à la fois pour
     * le calcul de complétion et pour le rendu lecture seule (formation/view.html.twig).
     *
     * @param array<string, mixed> $data
     */
    public function formationFieldValue(Formation $formation, string $key, array $data): mixed
    {
        return match ($key) {
            'name' => $formation->getName(),
            'diplome' => $formation->getDiplome(),
            'domaine' => $formation->getDomaine(),
            'composante' => $formation->getComposante(),
            default => $data[$key] ?? null,
        };
    }

    /** Équivalent de formationParamStatus() pour une section « Paramètre du parcours ». */
    public function parcoursParamStatus(TreeNode $parcours, string $key): string
    {
        $type = $this->paramTypes->typeFor($parcours->getFormation(), ParamTypeResolver::SCOPE_PARCOURS, $key);
        $data = $parcours->getParametre($key);

        return null === $type ? 'empty' : $this->paramStatus($type, fn (string $k) => $this->parcoursFieldValue($parcours, $k, $data));
    }

    /**
     * Équivalent de formationParamIssues() pour une section « Paramètre du parcours ».
     *
     * @return array<string, list<string>>
     */
    public function parcoursParamIssues(TreeNode $parcours, string $key): array
    {
        $type = $this->paramTypes->typeFor($parcours->getFormation(), ParamTypeResolver::SCOPE_PARCOURS, $key);
        $data = $parcours->getParametre($key);

        return null === $type ? [] : $this->paramIssues($type, fn (string $k) => $this->parcoursFieldValue($parcours, $k, $data));
    }

    /** Équivalent de formationFieldValue() pour une section « Paramètre du parcours ». @param array<string, mixed> $data */
    public function parcoursFieldValue(TreeNode $parcours, string $key, array $data): mixed
    {
        return match ($key) {
            'nom' => $parcours->getLabel(),
            'ectsTotal' => $parcours->getAttribute('ects'),
            default => $data[$key] ?? null,
        };
    }

    private function isFilled(mixed $v): bool
    {
        return !(null === $v || '' === $v || [] === $v);
    }

    /**
     * Statut d'un référentiel BCC : « empty » si aucun bloc, « incomplete » si un
     * bloc n'a pas de compétence, « ok » sinon.
     *
     * @param list<TreeNode> $blocs
     */
    public function bccStatus(array $blocs): string
    {
        if ($blocs === []) {
            return 'empty';
        }
        foreach ($blocs as $bloc) {
            $hasCompetence = false;
            foreach ($bloc->getChildren() as $child) {
                if ($child->getType()->getKey() === 'competence') {
                    $hasCompetence = true;
                    break;
                }
            }
            if (!$hasCompetence) {
                return 'incomplete';
            }
        }

        return 'ok';
    }
}
