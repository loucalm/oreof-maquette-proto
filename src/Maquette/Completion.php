<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\FieldDef;
use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Repository\FieldDefRepository;
use App\Repository\NodeTypeRepository;

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
        private readonly NodeTypeRepository $types,
        private readonly FieldDefRepository $fields,
    ) {
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

        $type = $this->types->findOneByKey('param_'.$key.'_formation');
        if (null === $type) {
            return 'empty';
        }

        $required = array_values(array_filter($this->fields->activeOrderedFor($type), static fn (FieldDef $f) => $f->isRequired()));
        if ([] === $required) {
            return 'ok';
        }

        $data = $formation->getParametre($key);
        $filled = 0;
        foreach ($required as $f) {
            $filled += $this->isFilled($this->formationFieldValue($formation, $f->getKey(), $data)) ? 1 : 0;
        }

        return match (true) {
            0 === $filled => 'empty',
            $filled === \count($required) => 'ok',
            default => 'incomplete',
        };
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
        $type = $this->types->findOneByKey('param_'.$key.'_parcours');
        if (null === $type) {
            return 'empty';
        }

        $required = array_values(array_filter($this->fields->activeOrderedFor($type), static fn (FieldDef $f) => $f->isRequired()));
        if ([] === $required) {
            return 'ok';
        }

        $data = $parcours->getParametre($key);
        $filled = 0;
        foreach ($required as $f) {
            $filled += $this->isFilled($this->parcoursFieldValue($parcours, $f->getKey(), $data)) ? 1 : 0;
        }

        return match (true) {
            0 === $filled => 'empty',
            $filled === \count($required) => 'ok',
            default => 'incomplete',
        };
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
