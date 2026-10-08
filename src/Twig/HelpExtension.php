<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Aide contextuelle de l'écran courant (domaine de traduction « help », clé = nom de route). */
final class HelpExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('page_help', $this->pageHelp(...))];
    }

    /** @return array{route: string, title: ?string, body: ?string} */
    public function pageHelp(): array
    {
        $route = (string) ($this->requestStack->getMainRequest()?->attributes->get('_route') ?? '');

        return [
            'route' => $route,
            'title' => $this->text($route.'.title'),
            'body' => $this->text($route.'.body'),
        ];
    }

    private function text(string $key): ?string
    {
        $value = $this->translator->trans($key, [], 'help');

        return $value !== $key && '' !== trim($value) ? $value : null;
    }
}
