<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\DerogationRequest;
use App\Entity\Formation;
use App\Repository\DerogationRequestRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Suivi des dérogations dans l'interface (badge admin, en-tête de l'éditeur, panneau du nœud). */
final class DerogationExtension extends AbstractExtension
{
    /** @var array<int, list<DerogationRequest>> */
    private array $byFormation = [];

    public function __construct(private readonly DerogationRequestRepository $repo)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_derogations', fn (): int => $this->repo->countPending()),
            new TwigFunction('formation_derogations', $this->forFormation(...)),
            new TwigFunction('node_derogations', fn (Formation $f, string $nid): array => array_values(array_filter(
                $this->forFormation($f),
                static fn (DerogationRequest $d) => $d->getNodeId() === $nid,
            ))),
        ];
    }

    /** @return list<DerogationRequest> les plus récentes d'abord */
    public function forFormation(Formation $formation): array
    {
        return $this->byFormation[(int) $formation->getId()] ??= $this->repo->findByFormation($formation);
    }
}
