<?php

declare(strict_types=1);

namespace App\Tests\FicheMatiere;

use App\Entity\Formation;
use App\Entity\FicheMatiere;
use App\Entity\FicheMatiereMutualisation;
use App\Repository\FicheMatiereRepository;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FormationFactory;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class FicheMatiereRepositoryTest extends DatabaseTestCase
{
    private FicheMatiereRepository $fiches;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fiches = static::getContainer()->get(FicheMatiereRepository::class);
    }

    public function testPortedByReturnsOnlyThatParcours(): void
    {
        $f = $this->formation();
        $this->fiche($f, 'n17', 'Bêta');
        $this->fiche($f, 'n17', 'Alpha');
        $this->fiche($f, 'n27', 'Gamma');
        $this->em->flush();

        $result = $this->fiches->portedBy($f, 'n17');

        self::assertCount(2, $result);
        self::assertSame(['Alpha', 'Bêta'], array_map(static fn (FicheMatiere $x) => $x->getLibelle(), $result));
    }

    public function testMonoParcoursUsesNullNid(): void
    {
        $f = $this->formation();
        $this->fiche($f, null, 'Seule');
        $this->em->flush();

        self::assertCount(1, $this->fiches->portedBy($f, null));
        self::assertCount(0, $this->fiches->portedBy($f, 'n17'));
    }

    public function testHorsDiplomeWithFormationIsInvalid(): void
    {
        $fiche = (new FicheMatiere())->setHorsDiplome(true)->setFormation($this->formation());

        $violations = static::getContainer()->get(ValidatorInterface::class)->validate($fiche);

        self::assertCount(1, $violations);
    }

    public function testNeitherHorsDiplomeNorFormationIsInvalid(): void
    {
        $violations = static::getContainer()->get(ValidatorInterface::class)->validate(new FicheMatiere());

        self::assertCount(1, $violations);
    }

    public function testHorsDiplomeAndOfFormationAndSearch(): void
    {
        $f = $this->formation();
        $this->fiche($f, null, 'Portée');
        $libre = (new FicheMatiere())->setHorsDiplome(true)->setData(['libelle' => 'Libre anglais']);
        $this->em->persist($libre);
        $this->em->flush();

        self::assertSame(['Libre anglais'], array_map(static fn (FicheMatiere $x) => $x->getLibelle(), $this->fiches->horsDiplome()));
        self::assertCount(1, $this->fiches->ofFormation($f));
        self::assertSame(['Libre anglais'], array_map(static fn (FicheMatiere $x) => $x->getLibelle(), $this->fiches->search('anglais')));
        self::assertCount(1, $this->fiches->search('', 1));
    }

    public function testMutualisedTo(): void
    {
        $f1 = $this->formation();
        $f2 = $this->formation('Autre');
        $fiche = $this->fiche($f1, 'n1', 'Partagée');
        $this->em->persist(new FicheMatiereMutualisation($fiche, $f2, 'n5'));
        $this->em->flush();

        self::assertSame([$fiche], $this->fiches->mutualisedTo($f2, 'n5'));
        self::assertSame([], $this->fiches->mutualisedTo($f2, 'n6'));
    }

    public function testImposedNeverCompetencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FicheMatiere())->setImposed('competencies', true);
    }

    public function testDefaultsImposedAndData(): void
    {
        $fiche = (new FicheMatiere())->setData(['libelle' => 'X', 'sigle' => 'S']);

        self::assertSame('X', $fiche->getLibelle());
        self::assertSame('S', $fiche->getDataValue('sigle'));
        self::assertNull($fiche->getDataValue('absent'));
        self::assertFalse($fiche->isImposed('ects'));
        self::assertTrue($fiche->setImposed('ects', true)->isImposed('ects'));
        self::assertSame(4, $fiche->setDefault('ects', 4)->getDefault('ects'));
        self::assertNull($fiche->getDefault('hours'));
    }

    private function formation(string $name = 'Formation de test'): Formation
    {
        return FormationFactory::create(static::getContainer())->fromType('L', 3, $name, avecParcours: true);
    }

    private function fiche(Formation $f, ?string $nid, string $libelle): FicheMatiere
    {
        $fiche = (new FicheMatiere())->setFormation($f)->setParcoursNid($nid)->setData(['libelle' => $libelle]);
        $this->em->persist($fiche);

        return $fiche;
    }
}
