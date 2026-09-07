<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Node;
use App\Maquette\AttributeCatalog;
use App\Repository\NodeTypeRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MaquetteExtension extends AbstractExtension
{
    public function __construct(private readonly NodeTypeRepository $types)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('types_allowed_for', $this->typesAllowedFor(...)),
            new TwigFunction('node_path', $this->nodePath(...)),
            new TwigFunction('capability_labels', $this->capabilityLabels(...)),
        ];
    }

    /**
     * Types que l'on peut ajouter sous ce nœud, d'après allowedChildKeys du type.
     *
     * @return list<\App\Entity\NodeType>
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
            static fn ($t) => $any || \in_array($t->getKey(), $allowed, true),
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
