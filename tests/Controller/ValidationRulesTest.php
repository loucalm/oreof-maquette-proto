<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\FieldDef;
use App\Entity\Formation;
use App\Entity\NodeType;
use App\FieldType\FieldTypeRegistry;
use App\Maquette\Completion;
use App\Maquette\Maquette;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use App\Validation\ValidationRule;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Retour externe, lot B : registre des types de champ et règles de validation
 * par onglet (obligatoire sous condition, au moins un parmi, bornes).
 */
final class ValidationRulesTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function saveParam(Formation $formation, string $section, array $p, array $entity = []): array
    {
        $this->client->request('POST', sprintf('/formations/%d/parametre/%s', $formation->getId(), $section), ['p' => $p] + $entity, [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** Champs d'organisation portés par l'entité Formation (envoyés hors `p`). */
    private const ENTITY = ['name' => 'Licence test', 'diplome' => 'Licence', 'domaine' => 'Droit, économie, gestion', 'composante' => 'UFR Sciences'];

    /** Organisation complète sauf ce que le test fait varier. */
    private function organisation(array $override): array
    {
        return $override + ['niveauEntree' => 'Bac', 'niveauSortie' => 'Bac+3', 'respMention' => 'M. Dupont'];
    }

    public function testRegistryDrivesTheFieldEditor(): void
    {
        $registry = static::getContainer()->get(FieldTypeRegistry::class);

        self::assertSame('Texte court', $registry->choices()['text']);
        self::assertSame(['settings' => [], 'bounds' => 'length'], $registry->editorConfig()['textarea']);
        self::assertContains('referentiel', $registry->editorConfig()['choice']['settings']);
        self::assertSame('count', $registry->editorConfig()['checkbox']['bounds']);
        self::assertFalse($registry->get('separator')->isInput());

        $type = $this->em->getRepository(NodeType::class)->findOneBy(['key' => 'ue']);
        $this->client->request('GET', '/node-types/'.$type->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-controller="field-config"] [data-setting="referentiel"]');
    }

    public function testConditionalRequiredFollowsTheCondition(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromTemplate('licence_lmd');

        $out = $this->saveParam($formation, 'organisation', $this->organisation(['rncp' => 'oui']), self::ENTITY);
        self::assertSame('incomplete', $out['status']);
        self::assertStringContainsString('obligatoire si', $out['issues']['codeRncp'][0] ?? '');

        $out = $this->saveParam($formation, 'organisation', $this->organisation(['rncp' => 'oui', 'codeRncp' => 'RNCP1234']), self::ENTITY);
        self::assertSame('ok', $out['status']);
        self::assertSame([], $out['issues']);

        $out = $this->saveParam($formation, 'organisation', $this->organisation(['rncp' => 'non']), self::ENTITY);
        self::assertSame('ok', $out['status']);
    }

    public function testEqualsOnMultipleChoiceMeansExactlyThatValue(): void
    {
        $validator = static::getContainer()->get(\App\Validation\TabValidator::class);
        $type = (new NodeType('t_test', 'Test'))->addValidationRule(
            new ValidationRule('r', ValidationRule::REQUIRED_IF, 'organisation', ['lieu'], 'regimes', 'eq', 'FI'),
        );
        $check = static fn (array $regimes) => $validator->check($type, static fn (string $k) => 'regimes' === $k ? $regimes : null, ['lieu', 'regimes'])['units'];

        self::assertSame(1, $check(['FI']));
        self::assertSame(0, $check(['FI', 'FI_APP']));
    }

    public function testBoundsViolationPutsTheTabInError(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromTemplate('licence_lmd');

        $out = $this->saveParam($formation, 'presentation', ['objectif' => 'x', 'resultats' => str_repeat('a', 3001), 'contenu' => 'y']);

        self::assertSame('error', $out['status']);
        self::assertStringContainsString('au plus 3000 caractères', $out['issues']['resultats'][0] ?? '');
    }

    public function testAdminAddsAndRemovesARule(): void
    {
        $type = $this->em->getRepository(NodeType::class)->findOneBy(['key' => 'param_presentation_formation']);
        $url = '/administration/node-types/'.$type->getId().'/regles';

        $this->client->request('POST', $url, ['kind' => 'one_of', 'tab' => 'presentation', 'fields' => ['objectif']]);
        $this->em->clear();
        self::assertCount(0, $this->em->find(NodeType::class, $type->getId())->getValidationRules(), 'un seul champ : refusé');

        $this->client->request('POST', $url, ['kind' => 'one_of', 'tab' => 'presentation', 'fields' => ['rythme', 'rythmePrecision']]);
        $this->em->clear();
        $rules = $this->em->find(NodeType::class, $type->getId())->getValidationRules();
        self::assertCount(1, $rules);
        self::assertSame(['rythme', 'rythmePrecision'], $rules[0]->fields);

        $this->client->request('GET', '/administration/regles-de-validation');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Renseigner au moins un champ parmi');

        $this->client->request('POST', $url.'/'.$rules[0]->id.'/supprimer');
        $this->em->clear();
        self::assertCount(0, $this->em->find(NodeType::class, $type->getId())->getValidationRules());
    }

    public function testRulesApplyToTreeNodes(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromTemplate('licence_lmd');
        $maquette = static::getContainer()->get(Maquette::class);
        $ec = FormationFactory::create(static::getContainer())->first($maquette->open($formation), 'ec');

        // une règle « au moins un » sur le type EC, entre deux champs que l'EC porte
        $ec->getType()->addValidationRule(new ValidationRule('t1', ValidationRule::ONE_OF, 'props', ['ects', 'ficheMatiere']));

        $ec->setAttribute('ects', null);
        $ec->setAttribute('ficheMatiere', null);
        $labels = array_column(static::getContainer()->get(Completion::class)->missing($ec), 'label');
        self::assertContains('Renseigner au moins un champ parmi : « '.$this->label('ects').' », « '.$this->label('ficheMatiere').' ».', $labels);

        $ec->setAttribute('ects', 3);
        $labels = array_column(static::getContainer()->get(Completion::class)->missing($ec), 'label');
        self::assertNotContains('Renseigner au moins un champ parmi : « '.$this->label('ects').' », « '.$this->label('ficheMatiere').' ».', $labels);
    }

    private function label(string $key): string
    {
        return $this->em->getRepository(FieldDef::class)->findOneBy(['key' => $key])->getLabel();
    }
}
