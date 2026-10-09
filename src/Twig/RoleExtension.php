<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Rôle de consultation du prototype : « superadmin » (admin SES + identifiants
 * techniques, ex. clés des types d'ELP), « admin » (SES — tous les paramètres)
 * ou « responsable » (responsable de formation — sans le métamodèle ni la
 * configuration de structure). Stocké en session, basculable depuis l'en-tête.
 * Pas d'authentification réelle dans le proto.
 */
final class RoleExtension extends AbstractExtension
{
    public const ROLES = ['superadmin' => 'Super-administrateur', 'admin' => 'Administrateur SES', 'responsable' => 'Responsable de formation'];

    /** Rôles qui voient l'administration SES. */
    public const ADMIN_ROLES = ['superadmin', 'admin'];

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_role', $this->role(...)),
            new TwigFunction('is_admin', $this->isAdmin(...)),
            new TwigFunction('is_super_admin', $this->isSuperAdmin(...)),
            new TwigFunction('role_labels', static fn () => self::ROLES),
        ];
    }

    public function role(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return 'admin';
        }

        $role = $request->getSession()->get('proto_role', 'admin');

        return isset(self::ROLES[$role]) ? $role : 'admin';
    }

    public function isSuperAdmin(): bool
    {
        return 'superadmin' === $this->role();
    }

    public function isAdmin(): bool
    {
        return \in_array($this->role(), self::ADMIN_ROLES, true);
    }
}
