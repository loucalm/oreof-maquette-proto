<?php

declare(strict_types=1);

namespace App\Tests\Formulaire;

use App\Diplome\Spec\CanvasEntry;
use App\Diplome\Spec\CanvasSpec;
use App\Diplome\Spec\VersionConfig;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Formulaire\CanvasResolver;
use App\Formulaire\NavItem;
use App\Maquette\Maquette;
use App\Repository\NodeTypeRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FormationFactory;

final class CanvasResolverTest extends DatabaseTestCase
{
    private CanvasResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = static::getContainer()->get(CanvasResolver::class);
    }

    public function testDefaultCanvasReproducesCurrentFiche(): void
    {
        $c = $this->resolver->forConfig(null);

        self::assertSame(['organisation', 'presentation', 'bcc', 'ramification', 'structure'], $this->keys($c->formation));
        self::assertSame(['organisation', 'presentation', 'bcc', 'positionnement'], $this->keys($c->parcours));
        self::assertSame('param_organisation_formation', $c->entry(CanvasSpec::FORMATION, 'organisation')->form);
        self::assertSame('param_presentation_parcours', $c->entry(CanvasSpec::PARCOURS, 'presentation')->form);
        self::assertTrue($c->entry(CanvasSpec::FORMATION, 'structure')->isBuiltin());
    }

    public function testLegacyFormsAreMappedOntoDefaultEntries(): void
    {
        $c = $this->resolver->forConfig(VersionConfig::fromArray(['forms' => ['formation.presentation' => 'param_organisation_formation']]));

        self::assertSame('param_organisation_formation', $c->entry(CanvasSpec::FORMATION, 'presentation')->form);
        self::assertSame('param_organisation_formation', $c->entry(CanvasSpec::FORMATION, 'organisation')->form);
    }

    public function testDefaultCanvasFollowsLibraryData(): void
    {
        $this->form('param_presentation_formation')->setDefaultEntryKey(null);

        self::assertNotContains('presentation', $this->keys($this->resolver->forConfig(null)->formation));
    }

    public function testBuiltinsMissingFromStoredCanvasAreAppended(): void
    {
        $stored = new CanvasSpec([new CanvasEntry('organisation', 'param_organisation_formation', null, null, false)], []);
        $c = $this->resolver->forConfig($this->config($stored));

        self::assertSame(['organisation', 'bcc', 'ramification', 'structure'], $this->keys($c->formation));
        self::assertFalse($c->entry(CanvasSpec::FORMATION, 'structure')->hidden);
        self::assertSame(['bcc', 'positionnement'], $this->keys($c->parcours));
    }

    public function testEntryWithMissingFormIsSkipped(): void
    {
        $formation = $this->formation('L', false);
        $this->setCanvas($formation, $this->defaults()->withEntries(CanvasSpec::FORMATION, [
            new CanvasEntry('organisation', 'param_organisation_formation', null, null, false),
            new CanvasEntry('fantome', 'formulaire_inexistant', null, 'Fantôme', false),
        ]));

        self::assertNotContains('fantome', $this->navKeys($this->resolver->navigation($formation)));
        self::assertNull($this->resolver->formEntry($formation, CanvasSpec::FORMATION, 'fantome'));
    }

    public function testNavigationSkipsHiddenEntriesAndNumbersTheRest(): void
    {
        $formation = $this->formation('L', false);
        $defaults = $this->defaults();
        $this->setCanvas($formation, $defaults->withEntries(CanvasSpec::FORMATION, array_map(
            static fn (CanvasEntry $e) => 'presentation' === $e->key ? new CanvasEntry($e->key, $e->form, $e->builtin, $e->label, true) : $e,
            $defaults->formation,
        )));

        $items = array_map(static fn (NavItem $i) => $i->number.' '.$i->key, $this->resolver->navigation($formation));
        self::assertSame(['1 organisation', '2 bcc', '3 structure'], $items);
        self::assertNull($this->resolver->formEntry($formation, CanvasSpec::FORMATION, 'presentation'));
    }

    public function testNavigationUsesEntryLabelThenFormLabel(): void
    {
        $formation = $this->formation('L', false);
        $defaults = $this->defaults();
        $this->setCanvas($formation, $defaults->withEntries(CanvasSpec::FORMATION, array_map(
            static fn (CanvasEntry $e) => 'organisation' === $e->key ? new CanvasEntry($e->key, $e->form, null, 'Organisation', false) : $e,
            $defaults->formation,
        )));

        $labels = array_map(static fn (NavItem $i) => $i->label, $this->resolver->navigation($formation));
        self::assertSame('Organisation', $labels[0]);
        self::assertSame('Présentation', $labels[1]);
    }

    public function testNavigationOfMultiParcoursFormationShowsRamificationNotBcc(): void
    {
        $formation = $this->formation('L', true);

        self::assertSame(['organisation', 'presentation', 'ramification', 'structure'], $this->navKeys($this->resolver->navigation($formation)));
    }

    public function testParcoursNavigationListsParcoursFiche(): void
    {
        $formation = $this->formation('L', true);
        $parcours = static::getContainer()->get(Maquette::class)->open($formation)->parcoursNodes()[0];

        $items = $this->resolver->navigation($formation, $parcours);
        self::assertSame(['organisation', 'presentation', 'bcc', 'positionnement'], $this->navKeys($items));
        self::assertSame(CanvasSpec::PARCOURS, $items[0]->fiche);
    }

    private function formation(string $type, bool $avecParcours): Formation
    {
        return FormationFactory::create(static::getContainer())->fromType($type, 3, 'Formation de test', $avecParcours);
    }

    private function defaults(): CanvasSpec
    {
        return $this->resolver->forConfig(null);
    }

    private function config(CanvasSpec $canvas): VersionConfig
    {
        return VersionConfig::fromArray(['canvas' => $canvas->toArray()]);
    }

    private function setCanvas(Formation $formation, CanvasSpec $canvas): void
    {
        $version = static::getContainer()->get(TypeDiplomeResolver::class)->versionFor($formation);
        $version->setConfig(VersionConfig::fromArray(['canvas' => $canvas->toArray()] + $version->config()->toArray()));
        $this->em->flush();
    }

    private function form(string $key): \App\Entity\NodeType
    {
        return static::getContainer()->get(NodeTypeRepository::class)->findOneByKey($key);
    }

    /** @param list<CanvasEntry> $entries @return list<string> */
    private function keys(array $entries): array
    {
        return array_map(static fn (CanvasEntry $e) => $e->key, $entries);
    }

    /** @param list<NavItem> $items @return list<string> */
    private function navKeys(array $items): array
    {
        return array_map(static fn (NavItem $i) => $i->key, $items);
    }
}
