<?php

declare(strict_types=1);

namespace App\Tests\FicheMatiere;

use App\Entity\FicheMatiere;
use App\Entity\FicheMatiereMutualisation;
use App\Entity\Formation;
use App\FicheMatiere\EcValueResolver;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\FormationFactory;

final class EcValueResolverTest extends DatabaseTestCase
{
    private EcValueResolver $resolver;
    private Formation $formation;
    private MaquetteDoc $doc;
    /** @var list<TreeNode> */
    private array $parcours;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = static::getContainer()->get(EcValueResolver::class);
        $this->formation = FormationFactory::create(static::getContainer())->fromType('L', 3, avecParcours: true, withLeaves: true);
        $this->doc = static::getContainer()->get(Maquette::class)->open($this->formation);
        // deux parcours frères, chacun avec ses EC
        $this->doc->duplicateNode($this->doc->parcoursNodes()[0]);
        $this->parcours = $this->doc->parcoursNodes();
        self::assertCount(2, $this->parcours);
    }

    public function testImposedWinsOverSource(): void
    {
        $fiche = $this->fiche($this->parcours[0]->nid, 'Algo', ['ects' => 6]);
        $fiche->setImposed('ects', true);
        $this->em->flush();
        $ec = $this->ecsOf($this->parcours[0])[0];
        $this->use($ec, $fiche)->setAttribute('sources', ['ects' => 'ec'])->setAttribute('ects', 3);

        $r = $this->resolver->resolve($ec, 'ects');

        self::assertSame(6, $r->value);
        self::assertSame('fiche', $r->source);
        self::assertTrue($r->locked);
        self::assertNull($r->warning);
    }

    public function testDefaultSourceInPorteur(): void
    {
        $fiche = $this->fiche($this->parcours[0]->nid, 'Algo', ['hours' => ['pres' => ['cm' => 12]], 'competencies' => ['c1']]);
        $this->em->flush();
        $ec = $this->use($this->ecsOf($this->parcours[0])[0], $fiche);
        $ec->setAttribute('competencies', ['propre']);

        foreach (EcValueResolver::KEYS as $key) {
            self::assertSame('fiche', $this->resolver->defaultSource($ec, $key), $key);
            self::assertSame('fiche', $this->resolver->resolve($ec, $key)->source, $key);
        }
        self::assertSame(['pres' => ['cm' => 12]], $this->resolver->value($ec, 'hours'));
        self::assertSame(['c1'], $this->resolver->value($ec, 'competencies'));
        self::assertFalse($this->resolver->resolve($ec, 'hours')->locked);
    }

    public function testDefaultSourceElsewhereCompetenciesFromEc(): void
    {
        $fiche = $this->fiche($this->parcours[1]->nid, 'Algo', ['hours' => ['pres' => ['cm' => 12]], 'competencies' => ['c1']]);
        $this->em->persist(new FicheMatiereMutualisation($fiche, $this->formation, $this->parcours[0]->nid));
        $this->em->flush();
        $ec = $this->use($this->ecsOf($this->parcours[0])[0], $fiche);
        $ec->setAttribute('hours', ['pres' => ['cm' => 99]])->setAttribute('competencies', ['propre']);

        $this->assertFicheValuesElsewhere($ec);
    }

    public function testDefaultSourceAncestorParcours(): void
    {
        $portail = $this->parcours[0];
        $sub = $this->doc->addNode(null, 'parcours', 'Sous-parcours');
        $sub->setParcoursParent($portail);
        $ec = $this->doc->addNode($sub->nid, 'ec', 'EC du sous-parcours');
        $fiche = $this->fiche($portail->nid, 'Algo', ['hours' => ['pres' => ['cm' => 12]], 'competencies' => ['c1']]);
        $this->em->flush();
        $this->use($ec, $fiche)->setAttribute('hours', ['pres' => ['cm' => 99]])->setAttribute('competencies', ['propre']);

        $this->assertFicheValuesElsewhere($ec);
    }

    public function testParentSource(): void
    {
        $ue = $this->ecsOf($this->parcours[0])[0]->getParent();
        self::assertNotNull($ue);
        $bloc = $this->doc->addNode($ue->nid, 'bloc_choix', 'Choix');
        $bloc->setAttribute('enfantsIdentiques', ['hours' => true, 'mccc' => false])
            ->setAttribute('hours', ['pres' => ['cm' => 20]]);
        $ec = $this->doc->addNode($bloc->nid, 'ec', 'Option A');
        $ec->setAttribute('hours', ['pres' => ['cm' => 5]])->setAttribute('mccc', ['propre' => true]);

        self::assertSame('parent', $this->resolver->defaultSource($ec, 'hours'));
        $r = $this->resolver->resolve($ec, 'hours');
        self::assertSame(['pres' => ['cm' => 20]], $r->value);
        self::assertSame('parent', $r->source);
        self::assertNull($r->warning);

        // le bloc ne déclare pas `mccc` identique : la source `parent` est impossible
        self::assertSame('ec', $this->resolver->defaultSource($ec, 'mccc'));
        $ec->setAttribute('sources', ['mccc' => 'parent']);
        $impossible = $this->resolver->resolve($ec, 'mccc');
        self::assertSame(['propre' => true], $impossible->value);
        self::assertSame('ec', $impossible->source);
        self::assertNotNull($impossible->warning);
    }

    public function testPorteurListIsMemoisedButNeverStale(): void
    {
        $fiche = $this->fiche($this->parcours[0]->nid, 'Algo', ['ects' => 1]);
        $this->em->persist(new FicheMatiereMutualisation($fiche, $this->formation, $this->parcours[1]->nid));
        $this->em->flush();
        $first = $this->use($this->ecsOf($this->parcours[0])[0], $fiche);
        $user = $this->use($this->ecsOf($this->parcours[1])[0], $fiche);
        $user->setAttribute('sources', ['ects' => 'porteur']);
        $first->setAttribute('sources', ['ects' => 'ec'])->setAttribute('ects', 7);

        self::assertSame(7, $this->resolver->resolve($user, 'ects')->value);
        self::assertSame(7, $this->resolver->resolve($user, 'ects')->value, 'deuxième résolution : même résultat');

        // modification de l'arbre dans la même requête (sans reset) : un nouvel EC porteur, placé avant
        $second = $this->doc->addNode($first->getParent()->nid, 'ec', 'Autre');
        $this->use($second, $fiche)->setAttribute('sources', ['ects' => 'ec'])->setAttribute('ects', 9);
        $this->doc->moveNode($second, $first->getParent(), 0);
        self::assertSame($second, $this->resolver->porteurEc($user));
        self::assertSame(9, $this->resolver->resolve($user, 'ects')->value);

        // l'EC porteur ne pointe plus la fiche
        $second->setAttribute('ficheMatiere', null);
        self::assertSame($first, $this->resolver->porteurEc($user));

        $this->resolver->reset();
        self::assertSame($first, $this->resolver->porteurEc($user));
    }

    public function testPorteurSource(): void
    {
        $fiche = $this->fiche($this->parcours[0]->nid, 'Algo', ['mccc' => ['fiche' => true]]);
        $this->em->persist(new FicheMatiereMutualisation($fiche, $this->formation, $this->parcours[1]->nid));
        $this->em->flush();
        $porteur = $this->use($this->ecsOf($this->parcours[0])[0], $fiche);
        $porteur->setAttribute('sources', ['mccc' => 'ec'])->setAttribute('mccc', ['porteur' => true]);
        $user = $this->use($this->ecsOf($this->parcours[1])[0], $fiche);
        $user->setAttribute('sources', ['mccc' => 'porteur'])->setAttribute('mccc', ['propre' => true]);

        self::assertSame($porteur, $this->resolver->porteurEc($user));
        self::assertNull($this->resolver->porteurEc($porteur));
        $r = $this->resolver->resolve($user, 'mccc');
        self::assertSame(['porteur' => true], $r->value);
        self::assertSame('porteur', $r->source);
        self::assertFalse($r->locked);
        self::assertNull($r->warning);

        // la valeur de l'EC porteur est elle-même résolue : sans source écrite, il reprend la fiche
        $porteur->setAttribute('sources', null);
        self::assertSame(['fiche' => true], $this->resolver->value($user, 'mccc'));
    }

    public function testPorteurInOtherFormation(): void
    {
        $autre = FormationFactory::create(static::getContainer())->fromType('L', 3, 'Porteuse', avecParcours: true, withLeaves: true);
        $autreDoc = static::getContainer()->get(Maquette::class)->open($autre);
        $autreParcours = $autreDoc->parcoursNodes()[0];
        $fiche = (new FicheMatiere())->setFormation($autre)->setParcoursNid($autreParcours->nid)->setData(['libelle' => 'Ailleurs']);
        $this->em->persist($fiche);
        $this->em->persist(new FicheMatiereMutualisation($fiche, $this->formation, $this->parcours[0]->nid));
        $this->em->flush();
        $porteur = $this->use($this->ecsOf($autreParcours)[0], $fiche);
        $porteur->setAttribute('sources', ['ects' => 'ec'])->setAttribute('ects', 4);
        $user = $this->use($this->ecsOf($this->parcours[0])[0], $fiche);
        $user->setAttribute('sources', ['ects' => 'porteur'])->setAttribute('ects', 1);

        self::assertSame($porteur, $this->resolver->porteurEc($user));
        self::assertSame(4, $this->resolver->value($user, 'ects'));
    }

    public function testPorteurFirstInTreeOrderWithWarning(): void
    {
        $fiche = $this->fiche($this->parcours[0]->nid, 'Algo');
        $this->em->persist(new FicheMatiereMutualisation($fiche, $this->formation, $this->parcours[1]->nid));
        $this->em->flush();
        $ecs = $this->ecsOf($this->parcours[0]);
        $firstUe = $ecs[0]->getParent();
        $secondUe = $ecs[1]->getParent();
        self::assertNotNull($firstUe);
        self::assertNotSame($firstUe, $secondUe);
        // créé après, donc après dans l'index, mais avant dans l'arbre (1re UE)
        $premier = $this->doc->addNode($firstUe->nid, 'ec', 'Ajouté dans la 1re UE');
        $this->use($ecs[1], $fiche)->setAttribute('sources', ['mccc' => 'ec'])->setAttribute('mccc', ['second' => true]);
        $this->use($premier, $fiche)->setAttribute('sources', ['mccc' => 'ec'])->setAttribute('mccc', ['premier' => true]);
        $user = $this->use($this->ecsOf($this->parcours[1])[0], $fiche);
        $user->setAttribute('sources', ['mccc' => 'porteur']);

        self::assertSame($premier, $this->resolver->porteurEc($user));
        $r = $this->resolver->resolve($user, 'mccc');
        self::assertSame(['premier' => true], $r->value);
        self::assertSame('porteur', $r->source);
        self::assertNotNull($r->warning);
    }

    public function testPorteurLoopFallsBack(): void
    {
        $fiche = $this->fiche($this->parcours[0]->nid, 'Algo', ['mccc' => ['fiche' => true]]);
        $this->em->flush();
        $porteur = $this->use($this->ecsOf($this->parcours[0])[0], $fiche);
        $porteur->setAttribute('sources', ['mccc' => 'porteur'])->setAttribute('mccc', ['propre' => true]);

        $r = $this->resolver->resolve($porteur, 'mccc');

        self::assertSame(['propre' => true], $r->value);
        self::assertSame('ec', $r->source);
        self::assertNotNull($r->warning);
    }

    public function testPorteurWithoutPorteurEcFallsBack(): void
    {
        $fiche = $this->fiche($this->parcours[0]->nid, 'Algo');
        $this->em->persist(new FicheMatiereMutualisation($fiche, $this->formation, $this->parcours[1]->nid));
        $this->em->flush();
        $user = $this->use($this->ecsOf($this->parcours[1])[0], $fiche);
        $user->setAttribute('sources', ['ects' => 'porteur'])->setAttribute('ects', 2);

        self::assertNull($this->resolver->porteurEc($user));
        $r = $this->resolver->resolve($user, 'ects');
        self::assertSame(2, $r->value);
        self::assertSame('ec', $r->source);
        self::assertNotNull($r->warning);
    }

    public function testLoopGuardOnMutualPorteurSources(): void
    {
        // fiche sans parcours porteur dans une formation à parcours : aucun EC n'est « dans le porteur »,
        // chacun trouve l'autre comme EC porteur
        $fiche = $this->fiche(null, 'Algo', ['mccc' => ['fiche' => true]]);
        $this->em->flush();
        $a = $this->use($this->ecsOf($this->parcours[0])[0], $fiche);
        $a->setAttribute('sources', ['mccc' => 'porteur'])->setAttribute('mccc', ['a' => true]);
        $b = $this->use($this->ecsOf($this->parcours[1])[0], $fiche);
        $b->setAttribute('sources', ['mccc' => 'porteur'])->setAttribute('mccc', ['b' => true]);

        self::assertSame($b, $this->resolver->porteurEc($a));
        self::assertSame($a, $this->resolver->porteurEc($b));
        foreach ([[$a, ['a' => true]], [$b, ['b' => true]]] as [$ec, $own]) {
            $r = $this->resolver->resolve($ec, 'mccc');
            self::assertSame($own, $r->value);
            self::assertSame('ec', $r->source);
            self::assertNotNull($r->warning);
        }
    }

    public function testHorsDiplomePorteurIsImpossible(): void
    {
        $fiche = (new FicheMatiere())->setHorsDiplome(true)->setData(['libelle' => 'Libre']);
        $this->em->persist($fiche);
        $this->em->flush();
        $ec = $this->use($this->ecsOf($this->parcours[0])[0], $fiche);
        $ec->setAttribute('sources', ['ects' => 'porteur'])->setAttribute('ects', 2);

        $r = $this->resolver->resolve($ec, 'ects');
        self::assertSame(2, $r->value);
        self::assertSame('ec', $r->source);
        self::assertSame('Source impossible : valeur propre de l\'EC utilisée.', $r->warning);
    }

    public function testRequestedSourceReturnsWrittenOrDefault(): void
    {
        $fiche = $this->fiche($this->parcours[1]->nid, 'Algo');
        $this->em->persist(new FicheMatiereMutualisation($fiche, $this->formation, $this->parcours[0]->nid));
        $this->em->flush();
        $ec = $this->use($this->ecsOf($this->parcours[0])[0], $fiche);
        $ec->setAttribute('sources', ['hours' => 'porteur', 'ects' => 'inconnue']);

        self::assertSame('porteur', $this->resolver->requestedSource($ec, 'hours'));
        self::assertSame('fiche', $this->resolver->requestedSource($ec, 'ects'));
        self::assertSame('fiche', $this->resolver->requestedSource($ec, 'mccc'));
        self::assertSame('ec', $this->resolver->requestedSource($ec, 'competencies'));
        $ue = $ec->getParent();
        self::assertNotNull($ue);
        self::assertSame('ec', $this->resolver->requestedSource($ue, 'hours'));
    }

    public function testMissingFicheFallsBackToOwnValue(): void
    {
        $ec = $this->ecsOf($this->parcours[0])[0];
        $ec->setAttribute('ficheMatiere', 999)->setAttribute('ects', 3);

        self::assertNull($this->resolver->fiche($ec));
        $r = $this->resolver->resolve($ec, 'ects');
        self::assertSame(3, $r->value);
        self::assertSame('ec', $r->source);
        self::assertFalse($r->locked);
        self::assertNotNull($r->warning);
    }

    public function testNoFicheMeansEc(): void
    {
        $ec = $this->ecsOf($this->parcours[0])[0];
        $ec->setAttribute('ects', 3);

        foreach (EcValueResolver::KEYS as $key) {
            self::assertSame('ec', $this->resolver->defaultSource($ec, $key), $key);
            $r = $this->resolver->resolve($ec, $key);
            self::assertSame('ec', $r->source, $key);
            self::assertNull($r->warning, $key);
        }
        self::assertSame(3, $this->resolver->value($ec, 'ects'));
    }

    public function testLegacyLabelMeansNoFicheWithoutWarning(): void
    {
        $ec = $this->ecsOf($this->parcours[0])[0];
        $ec->setAttribute('ficheMatiere', 'Fiche — Algorithmique')->setAttribute('ects', 3);

        self::assertNull($this->resolver->fiche($ec));
        self::assertSame('ec', $this->resolver->defaultSource($ec, 'ects'));
        $r = $this->resolver->resolve($ec, 'ects');
        self::assertSame(3, $r->value);
        self::assertSame('ec', $r->source);
        self::assertNull($r->warning);
    }

    public function testLabelFallsBackToFiche(): void
    {
        $fiche = $this->fiche($this->parcours[0]->nid, 'Algorithmique');
        $this->em->flush();
        $ec = $this->use($this->ecsOf($this->parcours[0])[0], $fiche);

        $ec->setLabel('');
        self::assertSame('Algorithmique', $this->resolver->label($ec));
        $ec->setLabel('Algo avancée');
        self::assertSame('Algo avancée', $this->resolver->label($ec));
    }

    public function testNonElementNodeReadsOwnAttribute(): void
    {
        $ue = $this->ecsOf($this->parcours[0])[0]->getParent();
        self::assertNotNull($ue);
        $ue->setAttribute('ects', 9);

        $r = $this->resolver->resolve($ue, 'ects');
        self::assertSame(9, $r->value);
        self::assertSame('ec', $r->source);
        self::assertFalse($r->locked);
        self::assertNull($r->warning);
        self::assertSame(9, $this->resolver->value($ue, 'ects'));
    }

    private function assertFicheValuesElsewhere(TreeNode $ec): void
    {
        self::assertSame('fiche', $this->resolver->defaultSource($ec, 'hours'));
        self::assertSame('ec', $this->resolver->defaultSource($ec, 'competencies'));
        $hours = $this->resolver->resolve($ec, 'hours');
        self::assertSame(['pres' => ['cm' => 12]], $hours->value);
        self::assertSame('fiche', $hours->source);
        $competencies = $this->resolver->resolve($ec, 'competencies');
        self::assertSame(['propre'], $competencies->value);
        self::assertSame('ec', $competencies->source);
        self::assertNull($competencies->warning);
    }

    /** @param array<string, mixed> $defaults */
    private function fiche(?string $nid, string $libelle, array $defaults = []): FicheMatiere
    {
        $fiche = (new FicheMatiere())->setFormation($this->formation)->setParcoursNid($nid)
            ->setData(['libelle' => $libelle])->setDefaults($defaults);
        $this->em->persist($fiche);

        return $fiche;
    }

    private function use(TreeNode $ec, FicheMatiere $fiche): TreeNode
    {
        return $ec->setAttribute('ficheMatiere', $fiche->getId());
    }

    /** EC (niveau élément) sous un parcours, dans l'ordre de l'arbre. @return list<TreeNode> */
    private function ecsOf(TreeNode $parcours): array
    {
        $result = [];
        $walk = static function (TreeNode $n) use (&$walk, &$result): void {
            foreach ($n->getChildren() as $child) {
                if ($child->getType()->isElementLevel()) {
                    $result[] = $child;
                }
                $walk($child);
            }
        };
        $walk($parcours);

        return $result;
    }
}
