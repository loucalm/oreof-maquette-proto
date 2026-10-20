<?php

declare(strict_types=1);

namespace App\Tests\Diplome;

use App\Diplome\Spec\VersionConfig;
use App\Diplome\StructureInstantiator;
use App\Diplome\VersionMccc;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Maquette\McccValidator;
use App\Tests\Support\DatabaseTestCase;

final class VersionMcccTest extends DatabaseTestCase
{
    private function ecOfLicence(): TreeNode
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneByKey('L');
        $formation = new Formation('Licence test');
        $formation->setTypeDiplome($type);
        $this->em->persist($formation);
        $this->em->flush();
        $doc = static::getContainer()->get(Maquette::class)->open($formation);
        (new StructureInstantiator())->instantiate($doc, $type->latestVersion(), 3, false);
        $ue = current(array_filter($doc->allNodes(), static fn (TreeNode $n) => 'ue' === $n->typeKey));
        $ec = $doc->addNode($ue->nid, 'ec', 'EC');
        $ec->setAttribute('mccc', ['type' => 'CCI', 'evaluations' => [['type' => 'ecrit', 'weight' => 100]]]);

        return $ec;
    }

    public function testNodeWithoutNatureUsesFirstDeclaredNature(): void
    {
        $ec = $this->ecOfLicence();
        $types = static::getContainer()->get(VersionMccc::class)->typesFor($ec);

        self::assertSame(['CCI', 'CC_CT', 'CT', 'CC'], array_map(static fn ($t) => $t->getKey(), $types));
    }

    public function testValidatorTakesProfileFromVersionConfig(): void
    {
        $ec = $this->ecOfLicence();
        $validator = static::getContainer()->get(McccValidator::class);
        // profil « licence » de la version : au moins 3 épreuves, une seule saisie → erreur
        self::assertTrue($validator->hasError($ec));

        $version = $ec->getFormation()->getTypeDiplome()->latestVersion();
        $config = $version->config()->toArray();
        $config['mccc']['ec']['profile'] = null;
        $version->setConfig(VersionConfig::fromArray($config));
        self::assertFalse($validator->hasError($ec));
    }
}
