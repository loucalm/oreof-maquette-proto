<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\FieldDef;
use App\Entity\NodeType;
use App\Maquette\Maquette;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Retour externe sur le prototype (lot A) : clé des types d'ELP générée et
 * réservée au super-admin, RF / RP au minimum requis, BCC à code calculé,
 * page Référentiel sans bloc « Métamodèle », enregistrement en direct côté parcours.
 */
final class PrototypeFeedbackTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function nodeType(string $label): ?NodeType
    {
        $this->em->clear();

        return $this->em->getRepository(NodeType::class)->findOneBy(['label' => $label]);
    }

    public function testAdminCannotChooseTheKeyItIsGeneratedAndUnique(): void
    {
        $this->client->request('POST', '/node-types/new', ['label' => 'Unité d’enseignement libre', 'key' => 'choisie']);
        $this->client->request('POST', '/node-types/new', ['label' => 'Module', 'key' => 'choisie']);
        $this->client->request('POST', '/node-types/new', ['label' => 'Module']);

        self::assertSame('unite_d_enseignement_libre', $this->nodeType('Unité d’enseignement libre')?->getKey());
        $keys = array_map(static fn (NodeType $t) => $t->getKey(), $this->em->getRepository(NodeType::class)->findBy(['label' => 'Module']));
        sort($keys);
        self::assertSame(['module', 'module_2'], $keys);
    }

    public function testAdminEditKeepsTheKey(): void
    {
        $this->client->request('POST', '/node-types/new', ['label' => 'Atelier']);
        $type = $this->nodeType('Atelier');

        $this->client->request('POST', '/node-types/'.$type->getId().'/edit', ['label' => 'Atelier renommé', 'key' => 'autre']);

        self::assertSame('atelier', $this->nodeType('Atelier renommé')?->getKey());
    }

    public function testSuperAdminCanSetAFreeButUniqueKey(): void
    {
        $this->client->request('POST', '/role/superadmin');
        $this->client->request('POST', '/node-types/new', ['label' => 'Stage', 'key' => 'stage_pro']);
        self::assertSame('stage_pro', $this->nodeType('Stage')?->getKey());

        // clé déjà prise par un autre type : refus, la clé reste inchangée
        $type = $this->nodeType('Stage');
        $this->client->request('POST', '/node-types/'.$type->getId().'/edit', ['label' => 'Stage', 'key' => 'ue']);
        self::assertSame('stage_pro', $this->nodeType('Stage')?->getKey());
    }

    public function testSuperAdminKeepsAdminAccess(): void
    {
        $this->client->request('POST', '/role/superadmin');
        $this->client->request('GET', '/node-types');

        self::assertResponseIsSuccessful();
    }

    public function testResponsablesRfRpAreMinimumRequiredAndRendered(): void
    {
        $fields = $this->em->getRepository(FieldDef::class);
        foreach (['respMention', 'respParcours'] as $key) {
            $field = $fields->findOneBy(['key' => $key]);
            self::assertTrue($field->isLocked(), $key);
            self::assertTrue($field->isRequired(), $key);
        }

        $formation = FormationFactory::create(static::getContainer())->fromTemplate('licence_lmd');
        $this->client->request('GET', '/formations/'.$formation->getId().'/parametre/organisation');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="p[respMention]"]');
    }

    public function testBccCodeIsComputedAndNotEditable(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromTemplate('licence_lmd');
        $this->client->request('POST', '/formations/'.$formation->getId().'/bcc/blocs');
        $this->client->request('GET', '/formations/'.$formation->getId().'/bcc');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form.bcc-edit [name="code"]');
        self::assertSelectorTextContains('form.bcc-edit', 'généré automatiquement');
    }

    public function testReferentielPageHasNoMetamodelBlock(): void
    {
        $this->client->request('GET', '/administration/referentiels');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Métamodèle', (string) $this->client->getResponse()->getContent());
    }

    public function testParcoursParamAutosaveReturnsStatus(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromTemplate('master_parcours');
        $parcours = static::getContainer()->get(Maquette::class)->open($formation)->parcoursNodes()[0];

        $this->client->request(
            'POST',
            sprintf('/formations/%d/parcours/%s/parametre/organisation', $formation->getId(), $parcours->nid),
            ['p' => ['lieu' => 'Campus nord']],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseIsSuccessful();
        self::assertContains(json_decode((string) $this->client->getResponse()->getContent(), true)['status'] ?? null, ['empty', 'incomplete', 'ok']);
    }
}
