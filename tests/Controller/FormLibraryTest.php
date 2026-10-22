<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\Spec\CanvasEntry;
use App\Diplome\Spec\CanvasSpec;
use App\Entity\FieldDef;
use App\Entity\NodeType;
use App\Enum\FormScope;
use App\Formulaire\FormResolver;
use App\Formulaire\ResolvedField;
use App\Tests\Support\EditsCanvas;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Bibliothèque de formulaires (/node-types) : variantes, réglages propres à chaque formulaire, confirmation d'impact. */
final class FormLibraryTest extends WebTestCase
{
    use EditsCanvas;
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testCreateVariantFromSocle(): void
    {
        $socle = $this->form('param_presentation_formation');

        $this->client->request('POST', sprintf('/administration/node-types/%d/variante', $socle->getId()), ['label' => 'Présentation allégée']);

        $variant = $this->em->getRepository(NodeType::class)->findOneBy(['label' => 'Présentation allégée']);
        self::assertNotNull($variant);
        self::assertSame('param_presentation_formation', $variant->getParent()->getKey());
        self::assertSame(FormScope::Formation, $variant->getScope());
        self::assertResponseRedirects(sprintf('/node-types/%d/edit', $variant->getId()));
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-variant-of="param_presentation_formation"]'));
    }

    public function testEditingFieldLabelOnSocleStaysOnThatForm(): void
    {
        $socle = $this->form('param_presentation_formation');
        $field = $this->fieldDef('objectif');

        $this->client->request('POST', sprintf('/administration/node-types/%d/champs/%d', $socle->getId(), $field->getId()), ['label' => 'But', 'type' => 'textarea', 'required' => '1', 'help' => 'Aide propre', 'confirmImpact' => '1']);

        $this->em->clear();
        self::assertSame('Objectif', $this->fieldDef('objectif')->getLabel());
        self::assertSame('But', $this->resolved('param_presentation_formation', 'objectif')->label);
        self::assertSame('Aide propre', $this->resolved('param_presentation_formation', 'objectif')->help);
        self::assertSame('Objectif', $this->resolved('param_presentation_parcours', 'objectif')->label);
    }

    public function testHidingInheritedFieldInVariant(): void
    {
        $variant = $this->variantOf('param_presentation_formation');

        $this->client->request('POST', sprintf('/administration/node-types/%d/champs/%d/affichage', $variant->getId(), $this->fieldDef('contenu')->getId()), ['visible' => '0']);

        $this->em->clear();
        self::assertFalse($this->form($variant->getKey())->getCapabilities()['contenu']);
        self::assertTrue($this->resolved($variant->getKey(), 'contenu', true)->hidden);
    }

    public function testLockedFieldCannotBeHidden(): void
    {
        $variant = $this->variantOf('param_organisation_formation');

        $this->client->request('POST', sprintf('/administration/node-types/%d/champs/%d/affichage', $variant->getId(), $this->fieldDef('name')->getId()), ['visible' => '0']);

        $this->em->clear();
        self::assertArrayNotHasKey('name', $this->form($variant->getKey())->getCapabilities());
    }

    public function testRemovingInheritedFieldFromVariantHidesIt(): void
    {
        $variant = $this->variantOf('param_presentation_formation');

        $this->client->request('POST', sprintf('/administration/node-types/%d/champs/%d/supprimer', $variant->getId(), $this->fieldDef('contenu')->getId()));

        $this->em->clear();
        self::assertFalse($this->form($variant->getKey())->getCapabilities()['contenu']);
        self::assertNotNull($this->fieldDef('contenu'));
    }

    public function testModificationOfUsedFormRequiresImpactConfirmation(): void
    {
        FormationFactory::create(static::getContainer())->fromType('BUT', 6);
        $socle = $this->form('param_presentation_formation');
        $url = sprintf('/administration/node-types/%d/champs/%d', $socle->getId(), $this->fieldDef('objectif')->getId());
        $edit = ['label' => 'But', 'type' => 'textarea', 'required' => '1'];

        $this->client->request('POST', $url, $edit);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame('Objectif', $this->resolved('param_presentation_formation', 'objectif')->label);

        $crawler = $this->client->request('GET', sprintf('/node-types/%d/edit', $socle->getId()));
        self::assertStringContainsString('BUT', $crawler->filter('[data-form-usage]')->text());

        $this->client->request('POST', $url, $edit + ['confirmImpact' => '1']);
        $this->em->clear();
        self::assertSame('But', $this->resolved('param_presentation_formation', 'objectif')->label);
    }

    public function testUsedFormCannotBeDeleted(): void
    {
        $variant = $this->variantOf('param_presentation_formation');
        $formation = FormationFactory::create(static::getContainer())->fromType('BUT', 6);
        $this->changeEntry($formation, CanvasSpec::FORMATION, 'presentation', static fn (CanvasEntry $e) => new CanvasEntry($e->key, $variant->getKey(), null, $e->label, $e->hidden));

        $this->client->request('POST', sprintf('/node-types/%d/delete', $variant->getId()));

        $this->em->clear();
        self::assertNotNull($this->form($variant->getKey()));
    }

    public function testFormWithVariantCannotBeDeleted(): void
    {
        $v1 = $this->variantOf('param_presentation_formation');
        $this->variantOf($v1->getKey());

        $this->client->request('POST', sprintf('/node-types/%d/delete', $v1->getId()));

        $this->em->clear();
        self::assertNotNull($this->form($v1->getKey()));
    }

    public function testUnusedVariantCanBeDeleted(): void
    {
        $variant = $this->variantOf('param_presentation_formation');

        $this->client->request('POST', sprintf('/node-types/%d/delete', $variant->getId()));

        $this->em->clear();
        self::assertNull($this->form($variant->getKey()));
    }

    public function testNewParentFieldsBannerUntilAcknowledged(): void
    {
        $variant = $this->variantOf('param_presentation_formation');
        $parent = $this->form('param_presentation_formation');
        $parent->setCapabilities($parent->getCapabilities() + ['motsCles' => true]);
        $this->em->flush();

        $crawler = $this->client->request('GET', sprintf('/node-types/%d/edit', $variant->getId()));
        self::assertStringContainsString('Mots clés', $crawler->filter('[data-new-parent-fields]')->text());

        $this->client->request('POST', sprintf('/administration/node-types/%d/nouveautes-vues', $variant->getId()));
        $crawler = $this->client->request('GET', sprintf('/node-types/%d/edit', $variant->getId()));
        self::assertCount(0, $crawler->filter('[data-new-parent-fields]'));
    }

    public function testIndexShowsVariantsUnderTheirParentWithUsage(): void
    {
        $variant = $this->variantOf('param_presentation_formation');

        $crawler = $this->client->request('GET', '/node-types');

        $keys = $crawler->filter('[data-form-key]')->each(static fn ($n) => $n->attr('data-form-key'));
        $at = array_search('param_presentation_formation', $keys, true);
        self::assertSame($variant->getKey(), $keys[$at + 1]);
        self::assertSame('1', $crawler->filter(sprintf('[data-form-key="%s"]', $variant->getKey()))->attr('data-depth'));
        self::assertCount(1, $crawler->filter('[data-form-key="param_presentation_formation"] [data-usage]'));
    }

    public function testKeyOfUsedFormCannotChange(): void
    {
        $socle = $this->form('param_presentation_formation');
        $this->client->request('POST', '/role/superadmin');

        $this->client->request('POST', sprintf('/node-types/%d/edit', $socle->getId()), ['label' => 'Présentation', 'key' => 'presentation_renommee', 'confirmImpact' => '1']);

        $this->em->clear();
        self::assertNotNull($this->form('param_presentation_formation'));
        self::assertNull($this->form('presentation_renommee'));
    }

    private function form(string $key): ?NodeType
    {
        return $this->em->getRepository(NodeType::class)->findOneBy(['key' => $key]);
    }

    private function fieldDef(string $key): ?FieldDef
    {
        return $this->em->getRepository(FieldDef::class)->findOneBy(['key' => $key]);
    }

    private function variantOf(string $parentKey): NodeType
    {
        $parent = $this->form($parentKey);
        $this->client->request('POST', sprintf('/administration/node-types/%d/variante', $parent->getId()), ['label' => $parent->getLabel().' '.bin2hex(random_bytes(2))]);
        $this->em->clear();

        return $this->em->getRepository(NodeType::class)->findOneBy(['parent' => $this->form($parentKey)], ['id' => 'DESC']);
    }

    private function resolved(string $formKey, string $field, bool $withHidden = false): ResolvedField
    {
        foreach (static::getContainer()->get(FormResolver::class)->fields($this->form($formKey), $withHidden) as $f) {
            if ($f->getKey() === $field) {
                return $f;
            }
        }
        self::fail("Champ $field absent de $formKey");
    }
}
