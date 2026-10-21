<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Formulaire\Preview\PreviewContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pendant un aperçu de canevas, la formation hôte est en lecture seule : toute requête qui
 * écrirait sur elle (méthode autre que GET / HEAD, attribut de route `id` ou `fid` = l'hôte)
 * est refusée, sauf la sortie de l'aperçu.
 */
final class PreviewGuardSubscriber
{
    private const FORMATION_ROUTES = ['formation_', 'parcours_', 'node_', 'bcc_'];

    public function __construct(private readonly PreviewContext $preview)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe() || 'diplome_canvas_preview_stop' === $request->attributes->get('_route')) {
            return;
        }
        $host = $this->preview->hostId();
        $target = $request->attributes->get('id') ?? $request->attributes->get('fid');
        if (null === $host || null === $target || (int) $target !== $host || !self::writesFormation((string) $request->attributes->get('_route'))) {
            return;
        }
        $message = 'Aperçu de la configuration en cours : cette formation est en lecture seule. Quittez l’aperçu pour la modifier.';
        $event->setResponse('json' === $request->getPreferredFormat()
            ? new JsonResponse(['error' => $message], Response::HTTP_FORBIDDEN)
            : new Response($message, Response::HTTP_FORBIDDEN));
    }

    /** Routes dont `id` / `fid` désigne une formation (et non un type de nœud ou de diplôme). */
    private static function writesFormation(string $route): bool
    {
        if (str_starts_with($route, 'node_type_')) {
            return false;
        }
        foreach (self::FORMATION_ROUTES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
