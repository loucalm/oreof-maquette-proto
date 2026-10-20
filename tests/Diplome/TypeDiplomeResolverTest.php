<?php

declare(strict_types=1);

namespace App\Tests\Diplome;

use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Entity\TypeDiplomeVersion;
use App\Tests\Support\DatabaseTestCase;

final class TypeDiplomeResolverTest extends DatabaseTestCase
{
    private function resolver(): TypeDiplomeResolver
    {
        return new TypeDiplomeResolver($this->em->getRepository(TypeDiplomeVersion::class));
    }

    private function formationOfYear(?string $annee): Formation
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneByKey('L');
        $f = (new Formation('F'))->setTypeDiplome($type)->setAnneeUniversitaire($annee);
        $this->em->persist($f);
        $this->em->flush();

        return $f;
    }

    public function testFormationUsesVersionOfItsYearOrLatestEarlier(): void
    {
        $type = $this->em->getRepository(TypeDiplome::class)->findOneByKey('L');
        $type->addVersion(new TypeDiplomeVersion($type, '2028-2029'));
        $this->em->flush();

        self::assertSame('2026-2027', $this->resolver()->versionFor($this->formationOfYear('2027-2028'))?->getAnneeUniversitaire());
        self::assertSame('2028-2029', $this->resolver()->versionFor($this->formationOfYear('2029-2030'))?->getAnneeUniversitaire());
        self::assertNull($this->resolver()->versionFor($this->formationOfYear('2025-2026')));
        self::assertSame('2026-2027', $this->resolver()->versionFor($this->formationOfYear(null))?->getAnneeUniversitaire());
        self::assertNotNull($this->resolver()->configFor($this->formationOfYear('2026-2027')));
    }

    public function testFormationWithoutTypeHasNoVersion(): void
    {
        $f = $this->em->getRepository(Formation::class)->findOneBy(['name' => 'Certificat Data Analyst (6 semaines)']);
        self::assertNotNull($f);
        self::assertNull($this->resolver()->versionFor($f));
        self::assertNull($this->resolver()->configFor($f));
        self::assertSame('Certificat', $f->getDiplome());
    }

    public function testLicenceFormationsAreTypedAndKeepTheirLabel(): void
    {
        $f = $this->em->getRepository(Formation::class)->findOneBy(['name' => 'Licence Informatique (démo)']);
        self::assertSame('L', $f->getTypeDiplome()?->getKey());
        self::assertSame('Licence', $f->getDiplome());
    }
}
