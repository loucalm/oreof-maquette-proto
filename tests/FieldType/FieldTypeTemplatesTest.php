<?php

declare(strict_types=1);

namespace App\Tests\FieldType;

use App\FieldType\FieldTypeRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FieldTypeTemplatesTest extends KernelTestCase
{
    public function testEveryFieldTypeHasExistingTemplates(): void
    {
        $twig = static::getContainer()->get('twig');
        foreach (static::getContainer()->get(FieldTypeRegistry::class)->all() as $type) {
            self::assertTrue($twig->getLoader()->exists($type->widgetTemplate()), $type->key());
            self::assertTrue($twig->getLoader()->exists($type->viewTemplate()), $type->key());
        }
    }

    public function testRenderingTemplatesNoLongerBranchOnFieldTypeOrKey(): void
    {
        foreach (['templates/node/panel.html.twig', 'templates/formation/param/_fields.html.twig', 'templates/formation/view.html.twig'] as $file) {
            $src = file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/'.$file);
            self::assertDoesNotMatchRegularExpression('/def\.(field|type)\s*(==|in)\s/', $src, $file);
            self::assertDoesNotMatchRegularExpression("/key\s*==\s*'/", $src, $file);
            self::assertDoesNotMatchRegularExpression('/node\.type\.key\s*(==|in)/', $src, $file);
        }
    }
}
