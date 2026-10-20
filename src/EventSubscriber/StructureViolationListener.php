<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Diplome\Check\StructureViolationException;
use App\Entity\Formation;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Transforme le refus d'une modification hors bornes en réponse utilisable :
 * 422 JSON pour les appels AJAX, sinon message + lien de demande de dérogation
 * (modale pré-remplie) et retour à l'éditeur sur le nœud concerné.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class StructureViolationListener
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        if (!$e instanceof StructureViolationException) {
            return;
        }

        $request = $event->getRequest();
        $first = $e->violations[0];
        $target = $this->editorRoute($e->formation, $first->scopeNid);
        $derogationUrl = $this->router->generate($target[0], $target[1] + [
            'derogation' => $first->ruleKey,
            'scope' => $first->scopeNid,
            'value' => $first->actual,
        ]);
        $refused = $this->translator->trans('diplome.guard.refused', ['%message%' => $first->message], 'diplome');

        if ($this->wantsJson($request)) {
            // de quoi afficher côté client le même toast que le flash serveur (message + lien de dérogation)
            $event->setResponse(new JsonResponse([
                'ok' => false,
                'error' => $this->translator->trans('diplome.guard.error', [], 'diplome'),
                'message' => $refused,
                'derogationUrl' => $derogationUrl,
                'derogationLabel' => $this->translator->trans('diplome.guard.request_derogation', [], 'diplome'),
                'closeLabel' => $this->translator->trans('layout.close', [], 'layout'),
                'violations' => array_map(static fn ($v): array => [
                    'ruleKey' => $v->ruleKey,
                    'scopeNid' => $v->scopeNid,
                    'message' => $v->message,
                    'actual' => $v->actual,
                    'expected' => $v->expected,
                ], $e->violations),
            ], 422));

            return;
        }

        if ($request->hasSession()) {
            $flashes = $request->getSession()->getFlashBag();
            $flashes->add('warning', $refused);
            $flashes->add('derogation_link', $derogationUrl);
        }
        $event->setResponse(new RedirectResponse($this->router->generate($target[0], $target[1])));
    }

    private function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || 'json' === $request->getPreferredFormat()
            || 'json' === $request->getContentTypeFormat();
    }

    /**
     * Éditeur à rouvrir : celui du parcours contenant le nœud concerné, sinon l'éditeur de la formation.
     * Lu dans l'arbre enregistré (la modification refusée n'y est pas écrite).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function editorRoute(Formation $formation, string $scopeNid): array
    {
        $fallback = ['formation_editor', ['id' => $formation->getId()]];
        if ('' === $scopeNid) {
            return $fallback;
        }
        $path = $this->pathTo($formation->getArbre(), $scopeNid);
        if (null === $path) {
            return $fallback;
        }
        foreach ($path as $raw) {
            if ('parcours' === ($raw['typeKey'] ?? null)) {
                return ['parcours_editor', ['fid' => $formation->getId(), 'nid' => $raw['nid'], 'focus' => $scopeNid]];
            }
        }

        return ['formation_editor', ['id' => $formation->getId(), 'focus' => $scopeNid]];
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>|null chemin racine → nœud
     */
    private function pathTo(array $nodes, string $nid): ?array
    {
        foreach ($nodes as $raw) {
            if (($raw['nid'] ?? null) === $nid) {
                return [$raw];
            }
            $sub = $this->pathTo((array) ($raw['children'] ?? []), $nid);
            if (null !== $sub) {
                return [$raw, ...$sub];
            }
        }

        return null;
    }
}
