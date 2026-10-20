<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Twig\RoleExtension;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Restreint les pages du métamodèle / configuration (Administration SES) à la
 * vue « admin ». En vue « responsable de formation », y accéder redirige vers
 * la liste des formations avec un message.
 */
#[AsEventListener(event: ControllerEvent::class)]
final class AdminAccessSubscriber
{
    /** @var list<string> */
    private const ADMIN_PREFIXES = ['admin_', 'node_type_', 'field_', 'role_', 'referentiel_', 'mcctype_', 'diplome_'];

    /** Routes hors prefixe mais réservées à l'admin (édition du métamodèle par nœud). */
    private const ADMIN_ROUTES = [
        'node_params',
        'node_params_form',
        'formation_create',
        'formation_structure_save',
        'formation_reset',
    ];

    /** Routes à prefixe admin mais accessibles au responsable (action côté formation). */
    private const ALLOWED = ['role_switch', 'referentiel_quick_add'];

    public function __construct(private readonly UrlGeneratorInterface $router)
    {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if (\in_array($route, self::ALLOWED, true)) {
            return;
        }

        $isAdminRoute = \in_array($route, self::ADMIN_ROUTES, true);
        foreach (self::ADMIN_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                $isAdminRoute = true;
                break;
            }
        }
        if (!$isAdminRoute || !$request->hasSession()) {
            return;
        }

        if (!\in_array($request->getSession()->get('proto_role', 'admin'), RoleExtension::ADMIN_ROLES, true)) {
            $request->getSession()->getFlashBag()->add('warning', "Page réservée à l'administrateur SES. Repassez en vue « Administrateur SES » pour y accéder.");
            $event->setController(fn () => new RedirectResponse($this->router->generate('formation_index')));
        }
    }
}
