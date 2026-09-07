<?php

declare(strict_types=1);

namespace App\Twig;

use App\Controller\FormationController;
use App\Entity\Formation;
use App\Entity\Node;
use App\Entity\NodeType;
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
            new TwigFunction('node_path', $this->nodePath(...)),
            new TwigFunction('capability_labels', $this->capabilityLabels(...)),
            new TwigFunction('param_sections', static fn () => FormationController::PARAM_SECTIONS),
            new TwigFunction('param_status', static fn (Formation $f, string $k) => FormationController::paramStatus($f, $k)),
        ];
    }

    /**
     * Types ajoutables sous ce nœud (d'après allowedChildKeys du type).
     *
     * @return list<NodeType>
     */
    public function typesAllowedFor(Node $node): array
    {
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
