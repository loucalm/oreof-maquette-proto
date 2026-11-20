<?php

namespace App\Twig\Components\UI;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Bouton du design system ORéOF v2 (repris de oreofv2/src/Twig/Components/UI/Button.php).
 *
 *   <twig:Button label="Ajouter" variant="success" icon="ph:plus" href="{{ path('…') }}" />
 *   <twig:Button label="Supprimer" variant="danger" :soft="false" :outline="true" icon="ph:trash" type="submit" />
 */
#[AsTwigComponent('Button', template: 'components/_ui/button.html.twig')]
final class Button
{
    public string $variant = 'primary';
    public string $size = 'sm';
    public bool $outline = false;
    public bool $soft = true;
    public string $icon = '';
    public bool $iconEnd = false;
    public string $label = '';
    public string $href = '';
    public string $target = '';
    public string $rel = '';
    public string $form = '';
    public string $type = 'button';
    /** Infobulle native (attribut title) : le proto n'a pas le contrôleur tooltip de v2. */
    public string $tooltip = '';
    public bool $disabled = false;
    public string $extraClass = '';
    public string $dataAction = '';
    public bool $fullWidth = false;
    public bool $centered = true;

    public function getSizeClass(): string
    {
        return match ($this->size) {
            'md' => 'px-4 py-2 text-sm h-10',
            'lg' => 'px-5 py-2.5 text-base h-11',
            'xs' => 'px-2 py-1 text-xs h-7',
            default => 'px-2.5 py-2 text-sm h-8',
        };
    }

    public function getColorClass(): string
    {
        if ($this->soft) {
            return match ($this->variant) {
                'primary' => 'border border-primary-300 bg-primary-50 text-primary-700 hover:bg-primary-100 hover:text-primary-800',
                'success' => 'border border-success-300 bg-success-50 text-success-700 hover:bg-success-100 hover:text-success-800',
                'warning' => 'border border-warning-300 bg-warning-50 text-warning-700 hover:bg-warning-100 hover:text-warning-800',
                'danger' => 'border border-danger-300 bg-danger-50 text-danger-700 hover:bg-danger-100 hover:text-danger-800',
                'info' => 'border border-info-300 bg-info-50 text-info-700 hover:bg-info-100 hover:text-info-800',
                default => 'border border-secondary-300 bg-secondary-50 text-secondary-600 hover:bg-secondary-100 hover:text-secondary-800',
            };
        }

        if ($this->outline) {
            return match ($this->variant) {
                'primary' => 'border border-primary-600 text-primary-700 hover:bg-primary-600 hover:text-white focus:ring-primary-500',
                'success' => 'border border-success-600 text-success-700 hover:bg-success-600 hover:text-white focus:ring-success-500',
                'warning' => 'border border-warning-500 text-warning-700 hover:bg-warning-500 hover:text-secondary-900 focus:ring-warning-400',
                'danger' => 'border border-danger-600 text-danger-700 hover:bg-danger-600 hover:text-white focus:ring-danger-500',
                'info' => 'border border-info-600 text-info-700 hover:bg-info-600 hover:text-white focus:ring-info-500',
                default => 'border border-secondary-400 text-secondary-600 hover:bg-secondary-500 hover:text-white focus:ring-secondary-400',
            };
        }

        return match ($this->variant) {
            'primary' => 'bg-primary-600 text-white hover:bg-primary-700 focus:ring-primary-500 border border-transparent',
            'success' => 'bg-success-600 text-white hover:bg-success-700 focus:ring-success-500 border border-transparent',
            'warning' => 'bg-warning-500 text-secondary-900 hover:bg-warning-600 focus:ring-warning-400 border border-transparent',
            'danger' => 'bg-danger-600 text-white hover:bg-danger-700 focus:ring-danger-500 border border-transparent',
            'info' => 'bg-info-600 text-white hover:bg-info-700 focus:ring-info-500 border border-transparent',
            default => 'bg-secondary-500 text-white hover:bg-secondary-600 focus:ring-secondary-400 border border-transparent',
        };
    }

    public function getAllClasses(): string
    {
        return implode(' ', array_filter([
            'inline-flex items-center gap-1.5 rounded-md font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap',
            $this->centered ? 'justify-center' : '',
            $this->fullWidth ? 'w-full' : '',
            $this->getSizeClass(),
            $this->getColorClass(),
            $this->disabled ? 'pointer-events-none opacity-50' : '',
            $this->extraClass,
        ]));
    }
}
