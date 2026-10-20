<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * CSRF sans état (config/packages/csrf.yaml) dans les tests HTTP : le jeton rendu est une valeur
 * fixe, validée par l'origine de la requête. Le client se présente donc comme un navigateur sur la
 * même origine (en-tête Sec-Fetch-Site) et les POST portent le jeton attendu.
 */
trait SameOriginCsrf
{
    private function sameOrigin(KernelBrowser $client): void
    {
        $client->setServerParameter('HTTP_SEC_FETCH_SITE', 'same-origin');
    }

    /** Jeton des actions POST hors formulaire de la section Diplômes (champ `_csrf_token`). */
    private function csrfToken(string $tokenId = 'diplome'): string
    {
        return static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($tokenId)->getValue();
    }

    /** Jeton d'un formulaire Symfony (champ `<nom>[_token]`). */
    private function formToken(): string
    {
        return $this->csrfToken('submit');
    }
}
