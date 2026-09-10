<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;

/**
 * Comptage des champs requis remplis / total, par nœud et remonté sur l'arbre.
 *
 * La progression n'est plus recalculée à chaque affichage de la liste des
 * formations : `Maquette::save()` appelle `docStats()` et range le résultat dans
 * `Formation::stats` (JSON). La liste lit ce cache directement.
 */
final class Completion
{
    public function __construct(private readonly AttributeCatalog $catalog)
    {
    }

    /**
     * Champs requis de CE nœud seul.
     *
     * @return array{req: int, filled: int}
     */
    public function node(TreeNode $node): array
    {
        $caps = $node->effectiveCapabilities();
        $req = 1; // libellé, requis partout
        $filled = trim($node->getLabel()) !== '' ? 1 : 0;

        if (($caps['code'] ?? false) && 'ec' === $node->getType()->getKey()) {
            ++$req;
            $filled += trim((string) $node->getCode()) !== '' ? 1 : 0;
        }

        foreach ($this->catalog->all() as $key => $def) {
            if (!($caps[$key] ?? false) || !($def['required'] ?? false)) {
                continue;
            }
            ++$req;
            $v = $node->getAttribute($key);
            $ok = $key === 'hours'
                ? AttributeCatalog::hoursProvided($v)
                : !($v === null || $v === '' || $v === []);
            $filled += $ok ? 1 : 0;
        }

        return ['req' => $req, 'filled' => $filled];
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

    /** @param array{req: int, filled: int} $s */
    public function pct(array $s): int
    {
        return $s['req'] === 0 ? 100 : (int) round($s['filled'] / $s['req'] * 100);
    }

    /**
     * Statistiques d'une maquette, rangées dans Formation::stats.
     *
     * @return array{progress: int, req: int, filled: int, parcours: list<array<string, mixed>>}
     */
    public function docStats(MaquetteDoc $doc): array
    {
        $overall = ['req' => 0, 'filled' => 0];
        foreach ($doc->pedagogicalRoots() as $root) {
            $s = $this->rollup($root);
            $overall['req'] += $s['req'];
            $overall['filled'] += $s['filled'];
        }

        $parcours = [];
        if ($doc->formation->isMultiParcours()) {
            foreach ($doc->parcoursNodes() as $p) {
                $ps = $this->rollup($p);
                $parcours[] = [
                    'nid' => $p->getId(),
                    'label' => $p->getDisplayLabel(),
                    'debut' => $p->getPeriodeDebut(),
                    'fin' => $p->getPeriodeFin(),
                    'parent' => $p->getParcoursParent()?->getDisplayLabel(),
                    'progress' => $this->pct($ps),
                    'bcc' => $this->bccStatus($p->getBccBlocs()),
                ];
            }
        }

        return [
            'progress' => $this->pct($overall),
            'req' => $overall['req'],
            'filled' => $overall['filled'],
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
