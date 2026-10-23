<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\Spec\CanvasEntry;
use App\Diplome\Spec\CanvasSpec;
use App\Diplome\Spec\VersionConfig;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Entity\NodeType;
use App\Enum\NodeFamily;
use App\Formulaire\CanvasResolver;
use App\Formulaire\EntryCompletion;
use App\Formulaire\Fiche\FicheFactory;
use App\Formulaire\FormResolver;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Repository\NodeTypeRepository;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Entrées des fiches formation et parcours rendues et enregistrées depuis le canevas de la version. */
final class FicheEntryTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testFormationEntryRendersResolvedFields(): void
    {
        $formation = $this->formation();
        $this->form('param_presentation_formation')->setFieldSetting('objectif', ['label' => 'But de la formation']);
        $this->em->flush();

        $crawler = $this->client->request('GET', sprintf('/formations/%d/parametre/presentation', $formation->getId()));

        self::assertResponseIsSuccessful();
        self::assertContains('But de la formation', $crawler->filter('.fld-label')->each(static fn ($n) => trim($n->text())));
    }

    public function testSavingKeepsValuesOfFieldsAbsentFromTheForm(): void
    {
        $formation = $this->formation();
        $this->setParam($formation, 'presentation', ['contenu' => 'Programme détaillé']);
        $variant = $this->variantOf('param_presentation_formation');
        $variant->setCapabilities(['contenu' => false]);
        $this->setEntryForm($formation, CanvasSpec::FORMATION, 'presentation', $variant->getKey());

        $this->client->request('POST', sprintf('/formations/%d/parametre/presentation', $formation->getId()), ['p' => ['objectif' => 'Former']]);

        self::assertResponseRedirects();
        self::assertSame(['contenu' => 'Programme détaillé', 'objectif' => 'Former'], $this->reload($formation)->getParametre('presentation'));
    }

    public function testUncheckedMultiChoiceIsCleared(): void
    {
        $formation = $this->formation(true);
        $parcours = $this->firstParcours($formation);
        $this->client->request('POST', $this->parcoursUrl($formation, $parcours, 'organisation'), ['p' => ['regimes' => ['FI'], 'lieu' => 'Campus']]);
        self::assertSame(['FI'], $this->parcoursParam($formation, $parcours, 'organisation')['regimes'] ?? null);

        $this->client->request('POST', $this->parcoursUrl($formation, $parcours, 'organisation'), ['p' => ['lieu' => 'Campus']]);

        $data = $this->parcoursParam($formation, $parcours, 'organisation');
        self::assertArrayNotHasKey('regimes', $data);
        self::assertSame('Campus', $data['lieu']);
    }

    public function testBoundFieldsWriteToEntity(): void
    {
        $formation = $this->formation(true);
        $this->client->request('POST', sprintf('/formations/%d/parametre/organisation', $formation->getId()), ['b' => ['name' => 'Licence renommée', 'composante' => 'UFR Sciences']]);
        $fresh = $this->reload($formation);
        self::assertSame('Licence renommée', $fresh->getName());
        self::assertSame('UFR Sciences', $fresh->getComposante());

        $parcours = $this->firstParcours($fresh);
        $this->client->request('POST', $this->parcoursUrl($fresh, $parcours, 'organisation'), ['b' => ['nom' => 'Parcours A', 'ectsTotal' => '120']]);
        $node = $this->firstParcours($this->reload($fresh));
        self::assertSame('Parcours A', $node->getLabel());
        self::assertSame(120, $node->getAttribute('ects'));
    }

    public function testAddedEntryStoresUnderItsKey(): void
    {
        $formation = $this->formation();
        $canvas = $this->canvas($formation);
        $this->setCanvas($formation, $canvas->withEntries(CanvasSpec::FORMATION, [
            ...$canvas->formation,
            new CanvasEntry('alternance', 'param_presentation_formation', null, 'Alternance', false),
        ]));

        $this->client->request('POST', sprintf('/formations/%d/parametre/alternance', $formation->getId()), ['p' => ['objectif' => 'Rythme 1 semaine / 3 semaines']]);

        self::assertSame('Rythme 1 semaine / 3 semaines', $this->reload($formation)->getParametre('alternance')['objectif'] ?? null);
    }

    public function testHiddenEntryIsNotFound(): void
    {
        $formation = $this->formation();
        $canvas = $this->canvas($formation);
        $this->setCanvas($formation, $canvas->withEntries(CanvasSpec::FORMATION, array_map(
            static fn (CanvasEntry $e) => 'presentation' === $e->key ? new CanvasEntry($e->key, $e->form, null, null, true) : $e,
            $canvas->formation,
        )));

        $this->client->request('GET', sprintf('/formations/%d/parametre/presentation', $formation->getId()));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', sprintf('/formations/%d/parametre/presentation', $formation->getId()), ['p' => ['objectif' => 'x']]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testBuiltinEntryKeyRedirectsToItsScreen(): void
    {
        $formation = $this->formation();

        $this->client->request('GET', sprintf('/formations/%d/parametre/structure', $formation->getId()));

        self::assertResponseRedirects(sprintf('/formations/%d/structure', $formation->getId()));
    }

    public function testRequiredOverrideDrivesStatus(): void
    {
        $formation = $this->formation();
        $form = $this->form('param_presentation_formation');
        foreach (static::getContainer()->get(FormResolver::class)->fields($form) as $field) {
            $form->setFieldSetting($field->getKey(), ['required' => false]);
        }
        $this->em->flush();
        $completion = static::getContainer()->get(EntryCompletion::class);
        $fiche = static::getContainer()->get(FicheFactory::class)->formation($formation);
        self::assertSame('ok', $completion->status($fiche, 'presentation'));

        $form->setFieldSetting('objectif', ['required' => true]);

        self::assertSame('empty', $completion->status($fiche, 'presentation'));
    }

    public function testParcoursUniqueEntryIsServed(): void
    {
        $formation = $this->formation();

        // même clé qu'une entrée de la formation : remplie sur la page de celle-ci
        $this->client->request('GET', sprintf('/formations/%d/parcours-unique/parametre/presentation', $formation->getId()));
        self::assertResponseRedirects(sprintf('/formations/%d/parametre/presentation', $formation->getId()));

        $this->client->request('POST', sprintf('/formations/%d/parcours-unique/parametre/presentation', $formation->getId()), ['p' => ['langue' => 'Français']]);
        self::assertSame('Français', $this->reload($formation)->getParametre('parcours.presentation')['langue'] ?? null);
    }

    public function testParcoursUniqueEntryIsNotFoundForFormationWithParcours(): void
    {
        $formation = $this->formation(true);

        $this->client->request('GET', sprintf('/formations/%d/parcours-unique/parametre/organisation', $formation->getId()));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', sprintf('/formations/%d/parcours-unique/parametre/organisation', $formation->getId()), ['b' => ['ectsTotal' => '999']]);
        self::assertResponseStatusCodeSame(404);
        self::assertNotSame(999, $this->reload($formation)->getEctsTotal());
    }

    private function formation(bool $avecParcours = false): Formation
    {
        return FormationFactory::create(static::getContainer())->fromType('L', 3, 'Licence de test', $avecParcours);
    }

    private function form(string $key): NodeType
    {
        return static::getContainer()->get(NodeTypeRepository::class)->findOneByKey($key);
    }

    private function variantOf(string $parentKey): NodeType
    {
        $parent = $this->form($parentKey);
        $variant = (new NodeType($parentKey.'_v', $parent->getLabel().' · variante'))
            ->setFamily(NodeFamily::Parameter)
            ->setScope($parent->getScope())
            ->setParent($parent);
        $this->em->persist($variant);
        $this->em->flush();
        // le dépôt mémorise les types lus pendant la requête de création de la formation
        static::getContainer()->get(NodeTypeRepository::class)->reset();

        return $variant;
    }

    private function canvas(Formation $formation): CanvasSpec
    {
        return static::getContainer()->get(CanvasResolver::class)->forFormation($formation);
    }

    private function setCanvas(Formation $formation, CanvasSpec $canvas): void
    {
        $version = static::getContainer()->get(TypeDiplomeResolver::class)->versionFor($formation);
        $version->setConfig(VersionConfig::fromArray(['canvas' => $canvas->toArray()] + $version->config()->toArray()));
        $this->em->flush();
    }

    private function setEntryForm(Formation $formation, string $fiche, string $key, string $form): void
    {
        $canvas = $this->canvas($formation);
        $this->setCanvas($formation, $canvas->withEntries($fiche, array_map(
            static fn (CanvasEntry $e) => $e->key === $key ? new CanvasEntry($e->key, $form, null, $e->label, $e->hidden) : $e,
            $canvas->entries($fiche),
        )));
    }

    /** @param array<string, mixed> $data */
    private function setParam(Formation $formation, string $key, array $data): void
    {
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $doc->setFormationParam($key, $data);
        $maquette->save($formation, $doc);
    }

    private function reload(Formation $formation): Formation
    {
        $this->em->clear();

        return $this->em->find(Formation::class, $formation->getId());
    }

    private function firstParcours(Formation $formation): TreeNode
    {
        return static::getContainer()->get(Maquette::class)->open($formation)->parcoursNodes()[0];
    }

    private function parcoursUrl(Formation $formation, TreeNode $parcours, string $key): string
    {
        return sprintf('/formations/%d/parcours/%s/parametre/%s', $formation->getId(), $parcours->getId(), $key);
    }

    /** @return array<string, mixed> */
    private function parcoursParam(Formation $formation, TreeNode $parcours, string $key): array
    {
        $fresh = $this->reload($formation);

        return static::getContainer()->get(Maquette::class)->open($fresh)->parcoursParam($parcours->getId(), $key);
    }
}
