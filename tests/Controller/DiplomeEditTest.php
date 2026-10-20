<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\StructureInstantiator;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DiplomeEditTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function type(string $key): TypeDiplome
    {
        return $this->em->getRepository(TypeDiplome::class)->findOneBy(['key' => $key]);
    }

    private function editPath(TypeDiplome $type, string $step): string
    {
        return sprintf('/diplomes/%d/versions/%d/%s', $type->getId(), $type->latestVersion()->getId(), $step);
    }

    /** Formation du type donné, instanciée depuis sa dernière version. */
    private function formationOf(string $key, int $duration = 1): Formation
    {
        $type = $this->type($key);
        $formation = new Formation('Formation '.$key);
        $formation->setTypeDiplome($type)->setAnneeUniversitaire($type->latestVersion()->getAnneeUniversitaire());
        $this->em->persist($formation);
        $this->em->flush();
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        (new StructureInstantiator())->instantiate($doc, $type->latestVersion(), $duration, false);
        $maquette->save($formation, $doc, 'Création');

        return $formation;
    }

    public function testButSaeAllowsOnlyContinuousAssessment(): void
    {
        $type = $this->type('BUT');
        // un EC de nature « sae » dans une formation BUT
        $formation = $this->formationOf('BUT', 3);
        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        $ue = current(array_filter($doc->allNodes(), static fn (TreeNode $n) => 'ue' === $n->typeKey));
        $ec = $doc->addNode($ue->nid, 'ec', 'SAÉ 1');
        $ec->setAttribute('natureElement', 'sae');
        $maquette->save($formation, $doc, 'Ajout');

        $crawler = $this->client->request('GET', $this->editPath($type, 'mccc'));
        self::assertResponseIsSuccessful();
        // une ligne par nature déclarée, avec ses types et son profil
        self::assertSame(['ressource', 'sae', 'stage'], $crawler->filter('fieldset[data-nature]')->each(static fn ($n) => $n->attr('data-nature')));

        $values = $crawler->filter('form#wizard-form')->form()->getPhpValues();
        $values['mcc_config']['natures'][1]['types'] = ['CC'];
        $values['confirmImpact'] = '1'; // la formation créée plus haut utilise cette version
        $this->client->request('POST', $this->editPath($type, 'mccc'), $values);
        self::assertResponseRedirects($this->editPath($type, 'mccc'));

        $this->em->clear();
        $mccc = $this->type('BUT')->latestVersion()->config()->mccc;
        self::assertSame(['CC'], $mccc['sae']['types']);

        // le panneau de cet EC ne propose que CC
        $crawler = $this->client->request('GET', sprintf('/formations/%d/nodes/%s', $formation->getId(), $ec->nid));
        self::assertSame(['CC'], $crawler->filter('input[type=radio][name="attr_mccc[type]"]')->each(static fn ($n) => $n->attr('value')));
        // la nature se choisit parmi celles de la version
        self::assertSame(['', 'ressource', 'sae', 'stage'], $crawler->filter('select[name=attr_natureElement] option')->each(static fn ($n) => $n->attr('value')));
    }

    public function testBccLevelsAndLinkLevelAreStored(): void
    {
        $type = $this->type('M2E');
        $crawler = $this->client->request('GET', $this->editPath($type, 'bcc'));
        $form = $crawler->filter('form#wizard-form')->form([
            'bcc_config[level1]' => 'Bloc',
            'bcc_config[level2]' => 'Objectif',
            'bcc_config[level3]' => 'Attendu',
            'bcc_config[linkLevel]' => '2',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects($this->editPath($type, 'bcc'));

        $this->em->clear();
        $config = $this->type('M2E')->latestVersion()->config();
        self::assertSame(['Bloc', 'Objectif', 'Attendu'], $config->bccLevels);
        self::assertSame(2, $config->bccLinkLevel);
    }

    public function testBccRejectsLinkLevelBeyondDeclaredLevels(): void
    {
        $type = $this->type('M2E');
        $crawler = $this->client->request('GET', $this->editPath($type, 'bcc'));
        $form = $crawler->filter('form#wizard-form')->form(['bcc_config[level1]' => 'Bloc', 'bcc_config[level2]' => '', 'bcc_config[level3]' => '', 'bcc_config[linkLevel]' => '2']);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        self::assertSame(['Bloc', 'Objectif', 'Attendu'], $this->type('M2E')->latestVersion()->config()->bccLevels);
    }

    public function testSavingUsedVersionRequiresImpactConfirmation(): void
    {
        $this->formationOf('L', 3);
        $type = $this->type('L');
        $crawler = $this->client->request('GET', $this->editPath($type, 'bcc'));
        // les formations concernées sont listées avant l'enregistrement
        self::assertStringContainsString('Formation L', $crawler->filter('[data-impact]')->text());

        $values = ['bcc_config[level1]' => 'Savoirs', 'bcc_config[level2]' => '', 'bcc_config[level3]' => '', 'bcc_config[linkLevel]' => '0'];
        $this->client->submit($crawler->filter('form#wizard-form')->form($values));
        self::assertResponseStatusCodeSame(422);
        $this->em->clear();
        self::assertSame(['Bloc', 'Compétence'], $this->type('L')->latestVersion()->config()->bccLevels);

        $crawler = $this->client->request('GET', $this->editPath($type, 'bcc'));
        $form = $crawler->filter('form#wizard-form')->form($values);
        $form['confirmImpact']->tick();
        $this->client->submit($form);
        self::assertResponseRedirects($this->editPath($type, 'bcc'));
        $this->em->clear();
        self::assertSame(['Savoirs'], $this->type('L')->latestVersion()->config()->bccLevels);
    }

    public function testNewYearVersionCopiesPrevious(): void
    {
        $type = $this->type('L');
        $previous = $type->latestVersion();
        $this->client->request('POST', sprintf('/diplomes/%d/versions', $type->getId()));
        self::assertResponseRedirects();

        $this->em->clear();
        $versions = $this->type('L')->getVersions();
        self::assertCount(2, $versions);
        self::assertSame('2027-2028', $versions[1]->getAnneeUniversitaire());
        self::assertSame($previous->config()->toArray(), $versions[1]->config()->toArray());
    }

    public function testListOffersEditAndNewVersion(): void
    {
        $type = $this->type('L');
        $crawler = $this->client->request('GET', '/diplomes');
        self::assertCount(1, $crawler->filter(sprintf('a[data-action=edit][href="%s"]', $this->editPath($type, 'formulaires'))));
        self::assertCount(1, $crawler->filter(sprintf('form[action="/diplomes/%d/versions"] button[data-action=new-version]', $type->getId())));
    }

    public function testEditRejectsNonEditableStepAndForeignVersion(): void
    {
        $type = $this->type('L');
        $this->client->request('GET', $this->editPath($type, 'recapitulatif'));
        self::assertResponseStatusCodeSame(404);
        $other = $this->type('BUT');
        $this->client->request('GET', sprintf('/diplomes/%d/versions/%d/bcc', $other->getId(), $type->latestVersion()->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testPropagateButtonAddsMissingImposedElement(): void
    {
        $formation = $this->formationOf('LP');
        $type = $this->type('LP');
        $config = $type->latestVersion()->config()->toArray();
        $config['structure']['imposed'][] = ['uid' => 'lp-nouveau', 'typeKey' => 'ue', 'label' => 'Nouvelle UE imposée', 'parentLevel' => 'semestre', 'position' => 'last', 'locked' => ['delete']];
        $type->latestVersion()->setConfig(\App\Diplome\Spec\VersionConfig::fromArray($config));
        $this->em->flush();

        $this->client->request('POST', sprintf('/diplomes/%d/propager/%d', $type->getId(), $type->latestVersion()->getId()));
        self::assertResponseRedirects();

        $this->em->clear();
        $doc = static::getContainer()->get(Maquette::class)->open($this->em->find(Formation::class, $formation->getId()));
        self::assertCount(1, array_filter($doc->allNodes(), static fn (TreeNode $n) => 'lp-nouveau' === $n->getAttribute('_imposed')));
    }
}
