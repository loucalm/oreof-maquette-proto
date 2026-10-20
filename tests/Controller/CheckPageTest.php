<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Diplome\StructureInstantiator;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Maquette;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CheckPageTest extends WebTestCase
{
    use ResetsDatabase;

    public function testPageListsEachViolationWithItsNodeLabel(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $type = $this->em->getRepository(TypeDiplome::class)->findOneByKey('L');
        $formation = new Formation('Licence à vérifier');
        $formation->setTypeDiplome($type);
        $this->em->persist($formation);
        $this->em->flush();

        $maquette = static::getContainer()->get(Maquette::class);
        $doc = $maquette->open($formation);
        (new StructureInstantiator())->instantiate($doc, $type->latestVersion(), 3, false);
        $semestre = null;
        foreach ($doc->allNodes() as $n) {
            if ('semestre' === $n->typeKey) {
                $semestre = $n;
                break;
            }
        }
        for ($i = 0; $i < 4; ++$i) {
            $doc->addNode($semestre->nid, 'ue');
        }
        $maquette->save($formation, $doc, 'Création');

        $client->request('GET', '/formations/'.$formation->getId().'/verifier');
        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString("Nombre d'éléments : au plus 6", html_entity_decode($html, \ENT_QUOTES));
        self::assertStringContainsString($semestre->getDisplayLabel(), html_entity_decode($html, \ENT_QUOTES));
    }

    public function testNoStructureSectionWithoutVersion(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $formation = new Formation('Sans type');
        $this->em->persist($formation);
        $this->em->flush();

        $client->request('GET', '/formations/'.$formation->getId().'/verifier');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('écart(s) à la structure', $client->getResponse()->getContent());
    }
}
