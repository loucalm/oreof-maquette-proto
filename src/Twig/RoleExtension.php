<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Rôle de consultation du prototype : « admin » (SES — vue actuelle, tous les
 * paramètres) ou « responsable » (responsable de formation — sans le métamodèle
 * ni la configuration de structure). Stocké en session, basculable depuis
 * l'en-tête. Pas d'authentification réelle dans le proto.
 */
final class RoleExtension extends AbstractExtension
{
    public const ROLES = ['admin' => 'Administrateur SES', 'responsable' => 'Responsable de formation'];

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_role', $this->role(...)),
            new TwigFunction('is_admin', fn () => $this->role() === 'admin'),
            new TwigFunction('role_labels', static fn () => self::ROLES),
        ];
    }

    public function role(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return 'admin';
        }

        return $request->getSession()->get('proto_role', 'admin') === 'responsable' ? 'responsable' : 'admin';
    }
}
