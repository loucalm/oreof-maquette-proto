<?php

declare(strict_types=1);

namespace App\Twig;

use App\Controller\FormationController;
use App\Entity\Formation;
use App\Entity\Node;
use App\Entity\NodeType;
use App\Enum\NodeFamily;
use App\Maquette\AttributeCatalog;
use App\Repository\NodeRepository;
use App\Repository\NodeTypeRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MaquetteExtension extends AbstractExtension
{
    public function __construct(
        private readonly NodeTypeRepository $types,
        private readonly NodeRepository $nodes,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('types_allowed_for', $this->typesAllowedFor(...)),
            new TwigFunction('valid_parents', $this->validParents(...)),
            new TwigFunction('parcours_candidates', $this->parcoursCandidates(...)),
            new TwigFunction('structure_rows', $this->structureRows(...)),
            new TwigFunction('structure_addable', $this->structureAddable(...)),
            new TwigFunction('root_type', $this->rootType(...)),
            new TwigFunction('type_meta', $this->typeMeta(...)),
            new TwigFunction('node_path', $this->nodePath(...)),
            new TwigFunction('capability_labels', $this->capabilityLabels(...)),
            new TwigFunction('param_sections', static fn () => FormationController::PARAM_SECTIONS),
            new TwigFunction('param_status', static fn (Formation $f, string $k) => FormationController::paramStatus($f, $k)),
        ];
    }

    /**
     * Types ajoutables sous ce nœud. Si le squelette de la formation impose un
     * enfant à ce niveau, c'est lui (plus un « bloc de choix » si le type
     * l'accepte). Sinon (nœud hors squelette), on retombe sur allowedChildKeys.
     *
     * @return list<NodeType>
     */
    public function typesAllowedFor(Node $node): array
    {
        $byKey = $this->types->findAllIndexed();
        $chainNext = $node->getFormation()?->getChildTypeKey($node->getType()->getKey());

        if ($chainNext !== null) {
            $keys = [$chainNext];
            if ($chainNext !== 'bloc_choix' && $node->getType()->allowsChild('bloc_choix') && isset($byKey['bloc_choix'])) {
                $keys[] = 'bloc_choix';
            }

            return array_values(array_filter(array_map(static fn (string $k) => $byKey[$k] ?? null, $keys)));
        }

        $allowed = $node->getType()->getAllowedChildKeys();
        if ($allowed === []) {
            return [];
        }
        $any = \in_array('*', $allowed, true);

        return array_values(array_filter(
            $this->types->findAllOrdered(),
            static fn (NodeType $t) => $any || \in_array($t->getKey(), $allowed, true),
        ));
    }

    /**
     * Lignes du tableau « Configuration de la structure » : le squelette de la
     * formation, niveau parcours inclus (fixe) quand elle est multi-parcours.
     *
     * @return list<array{key: string, type: ?NodeType, fixed: bool, ok: bool}>
     */
    public function structureRows(Formation $formation): array
    {
        $byKey = $this->types->findAllIndexed();
        $rows = [];
        $prev = null;

        foreach ($formation->getEffectiveStructure() as $i => $key) {
            $type = $byKey[$key] ?? null;
            $rows[] = [
                'key' => $key,
                'type' => $type,
                'fixed' => 0 === $i && $formation->isMultiParcours() && 'parcours' === $key,
                'ok' => null === $prev || $prev->allowsChild($key),
            ];
            $prev = $type;
        }

        return $rows;
    }

    /**
     * Types que l'on peut ajouter au bout du squelette : enfants autorisés du
     * dernier maillon, non déjà présents, hors « parcours ».
     *
     * @return list<NodeType>
     */
    public function structureAddable(Formation $formation): array
    {
        $chain = $formation->getEffectiveStructure();
        $inChain = array_flip($chain);
        $byKey = $this->types->findAllIndexed();
        $lastKey = [] !== $chain ? $chain[array_key_last($chain)] : null;
        $lastType = null !== $lastKey ? ($byKey[$lastKey] ?? null) : null;

        return array_values(array_filter($this->types->findAllOrdered(), static function (NodeType $t) use ($inChain, $lastType) {
            if ('parcours' === $t->getKey() || isset($inChain[$t->getKey()])) {
                return false;
            }

            return null === $lastType
                ? NodeFamily::Structural === $t->getFamily()
                : $lastType->allowsChild($t->getKey());
        }));
    }

    /** Type des nœuds racine d'après le squelette, ou null si non défini. */
    public function rootType(Formation $formation): ?NodeType
    {
        $key = $formation->getRootTypeKey();

        return null !== $key ? ($this->types->findAllIndexed()[$key] ?? null) : null;
    }

    /**
     * Libellé + icône de chaque type, indexés par clé — pour l'étiquetage
     * côté JS (bouton « Ajouter » de l'arbre).
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public function typeMeta(): array
    {
        return array_map(
            static fn (NodeType $t) => ['label' => $t->getLabel(), 'icon' => $t->getIcon() ?? ''],
            $this->types->findAllIndexed(),
        );
    }

    /**
     * Parents valides pour ce nœud : autres nœuds de la formation dont le type
     * accepte celui du nœud, hors lui-même et sa descendance.
     *
     * @return list<Node>
     */
    public function validParents(Node $node): array
    {
        $descendants = [];
        $collect = static function (Node $n) use (&$collect, &$descendants): void {
            $descendants[$n->getId()] = true;
            foreach ($n->getChildren() as $c) {
                $collect($c);
            }
        };
        $collect($node);

        $key = $node->getType()->getKey();

        return array_values(array_filter(
            $this->nodes->findForFormation($node->getFormation()),
            static fn (Node $cand) => !isset($descendants[$cand->getId()])
                && $cand->getType()->allowsChild($key),
        ));
    }

    /**
     * Parcours pouvant servir de parent (ramification) : autres parcours de la
     * formation, hors lui-même et hors sa propre descendance parcours (anti-cycle).
     *
     * @return list<Node>
     */
    public function parcoursCandidates(Node $node): array
    {
        $forbidden = [$node->getId() => true];
        // descendance parcours de $node
        $all = array_filter($this->nodes->findForFormation($node->getFormation()), static fn (Node $n) => $n->isParcours());
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($all as $p) {
                $pp = $p->getParcoursParent()?->getId();
                if ($pp !== null && isset($forbidden[$pp]) && !isset($forbidden[$p->getId()])) {
                    $forbidden[$p->getId()] = true;
                    $changed = true;
                }
            }
        }

        return array_values(array_filter(
            $all,
            fn (Node $p) => !isset($forbidden[$p->getId()])
                && Node::parcoursYearsAllowChild($p->getAnneeDebut(), $p->getAnneeFin(), $node->getAnneeDebut(), $node->getAnneeFin()),
        ));
    }

    /** @return list<Node> du racine jusqu'au nœud. */
    public function nodePath(Node $node): array
    {
        $path = [];
        $cursor = $node;
        while ($cursor !== null) {
            array_unshift($path, $cursor);
            $cursor = $cursor->getParent();
        }

        return $path;
    }

    /** @return array<string, string> */
    public function capabilityLabels(): array
    {
        $out = [];
        foreach (AttributeCatalog::all() as $key => $def) {
            $out[$key] = $def['label'];
        }
        foreach (AttributeCatalog::FLAGS as $key => $label) {
            $out[$key] = $label;
        }

        return $out;
    }
}
