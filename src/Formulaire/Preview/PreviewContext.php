<?php

declare(strict_types=1);

namespace App\Formulaire\Preview;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Aperçu d'un canevas sur une vraie formation (l'« hôte ») : tant qu'il est actif, la session
 * substitue ce canevas à celui de la version de l'hôte, et l'hôte est en lecture seule
 * (cf. PreviewGuardSubscriber). Les autres formations ne sont pas touchées.
 */
final class PreviewContext
{
    private const SESSION_KEY = 'canvas_preview';

    public function __construct(private readonly RequestStack $requests)
    {
    }

    public function start(Formation $host, CanvasSpec $canvas, string $returnUrl): void
    {
        $this->requests->getSession()->set(self::SESSION_KEY, ['formationId' => $host->getId(), 'canvas' => $canvas->toArray(), 'returnUrl' => $returnUrl]);
    }

    public function canvasFor(Formation $formation): ?CanvasSpec
    {
        $state = $this->state();

        return null !== $state && $state['formationId'] === $formation->getId() ? CanvasSpec::fromArray($state['canvas']) : null;
    }

    public function isActive(Formation $formation): bool
    {
        return $this->hostId() === $formation->getId() && null !== $formation->getId();
    }

    public function hostId(): ?int
    {
        return $this->state()['formationId'] ?? null;
    }

    public function returnUrl(): ?string
    {
        return $this->state()['returnUrl'] ?? null;
    }

    public function stop(): void
    {
        $this->requests->getSession()->remove(self::SESSION_KEY);
    }

    /** @return array{formationId: int, canvas: array<string, mixed>, returnUrl: string}|null */
    private function state(): ?array
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return null;
        }
        $state = $request->getSession()->get(self::SESSION_KEY);

        return \is_array($state) ? $state : null;
    }
}
