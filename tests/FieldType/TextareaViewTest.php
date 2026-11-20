<?php

declare(strict_types=1);

namespace App\Tests\FieldType;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/** Affichage en lecture d'un texte long : paragraphes et listes à puces, largeur lisible. */
final class TextareaViewTest extends KernelTestCase
{
    public function testLinesBecomeParagraphsAndDashLinesAList(): void
    {
        self::bootKernel();
        $html = static::getContainer()->get(Environment::class)->render('field_type/view/textarea.html.twig', [
            'value' => "Premier paragraphe.\nDeux spécialités :\n- géographie\n- urbanisme\n\nDernier paragraphe.",
        ]);

        self::assertSame(3, substr_count($html, '<p>'));
        self::assertSame(1, substr_count($html, '<ul'));
        self::assertSame(2, substr_count($html, '<li>'));
        self::assertStringContainsString('<li>géographie</li>', $html);
        self::assertStringContainsString('max-w-[75ch]', $html);
    }

    public function testMarkupInTheTextIsEscaped(): void
    {
        self::bootKernel();
        $html = static::getContainer()->get(Environment::class)->render('field_type/view/textarea.html.twig', ['value' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $html);
    }
}
