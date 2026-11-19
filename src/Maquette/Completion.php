<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Diplome\Check\StructureChecker;
use App\Diplome\Check\StructureViolation;
use App\Diplome\TypeDiplomeResolver;
use App\Diplome\VersionMccc;
use App\Entity\Formation;
use App\FicheMatiere\EcValueResolver;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
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
        private readonly TabValidator $validator,
        private readonly StructureChecker $structureChecker,
        private readonly TypeDiplomeResolver $resolver,
        private readonly EcValueResolver $values,
        private readonly VersionMccc $versionMccc,
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
            if ('ficheMatiere' === $key && $node->getType()->isElementLevel()) {
                $this->checkFiche($node, $def, $out);
                continue;
            }
            $v = $this->values->value($node, $key);
            $provided = $key === 'hours'
                ? AttributeCatalog::hoursProvided($v)
                : !($v === null || $v === '' || $v === []);
            if (!$provided) {
                $out[] = ['key' => $key, 'label' => mb_strtolower($def['label']), 'tab' => $def['domain']];
            } elseif ($key === 'mccc' && !$this->mcccTypeAllowed($node, $v)) {
                $out[] = ['key' => $key, 'label' => 'type de MCCC non autorisé par le diplôme', 'tab' => $def['domain']];
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

    /**
     * Fiche matière d'un EC : un ancien libellé ou une absence compte comme non renseignée (R2), un id qui
     * ne mène à aucune fiche comme « introuvable ».
     *
     * @param array<string, mixed>                                                        $def
     * @param list<array{key: string, label: string, tab: string, detail?: string, invalid?: bool}> $out
     */
    private function checkFiche(TreeNode $node, array $def, array &$out): void
    {
        if (null === $this->values->ficheId($node)) {
            $out[] = ['key' => 'ficheMatiere', 'label' => mb_strtolower($def['label']), 'tab' => $def['domain']];
        } elseif (null === $this->values->fiche($node)) {
            $out[] = ['key' => 'ficheMatiere', 'label' => 'fiche matière introuvable', 'tab' => $def['domain']];
        }
    }

    /** D5 : le type de MCCC résolu doit figurer parmi ceux de la version du diplôme (null : pas de restriction). */
    private function mcccTypeAllowed(TreeNode $node, mixed $mccc): bool
    {
        $type = \is_array($mccc) ? (string) ($mccc['type'] ?? '') : '';
        $allowed = $this->versionMccc->typesFor($node);
        if ('' === $type || null === $allowed) {
            return true;
        }

        return \in_array($type, array_map(static fn ($t): string => $t->getKey(), $allowed), true);
    }

    /** @return array{units: int, issues: list<array{key: string, label: string, tab: string, kind: string}>} */
    private function ruleCheck(TreeNode $node): array
    {
        return $this->validator->check(
            $node->getType(),
            fn (string $key) => $this->values->value($node, $key),
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
