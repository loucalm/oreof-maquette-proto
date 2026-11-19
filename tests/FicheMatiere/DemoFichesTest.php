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
use App\Repository\FicheMatiereMutualisationRepository;
use App\Repository\FicheMatiereRepository;
use App\Tests\Support\DatabaseTestCase;

/** Données de démonstration des fiches matière (spec §11). */
final class DemoFichesTest extends DatabaseTestCase
{
    private FicheMatiereRepository $fiches;
    private EcValueResolver $values;

    protected bool $withDemoFiches = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fiches = static::getContainer()->get(FicheMatiereRepository::class);
        $this->values = static::getContainer()->get(EcValueResolver::class);
    }

    public function testEveryDemoEcHasAFiche(): void
    {
        $maquette = static::getContainer()->get(Maquette::class);
        $ecCount = 0;
        foreach ($this->em->getRepository(Formation::class)->findAll() as $formation) {
            foreach ($maquette->open($formation)->allNodes() as $node) {
                if (!$node->getType()->isElementLevel()) {
                    continue;
                }
                ++$ecCount;
                $id = $this->values->ficheId($node);
                self::assertNotNull($id, \sprintf('EC « %s » de « %s » sans fiche matière', $node->getLabel(), $formation->getName()));
                self::assertNotNull($this->values->fiche($node), \sprintf('Fiche %d introuvable pour l’EC « %s »', $id, $node->getLabel()));
            }
        }
        self::assertGreaterThan(0, $ecCount, 'Les formations de démonstration devraient contenir des EC.');
    }

    public function testDemoCoversEveryCase(): void
    {
        $libelles = array_map(static fn (FicheMatiere $f): string => $f->getLibelle(), $this->fiches->findAll());
        foreach (['Fiche — Algorithmique', 'Fiche — Programmation', 'Fiche — Bases de données', 'Fiche — Mathématiques'] as $libelle) {
            self::assertContains($libelle, $libelles, 'Libellé de l’ancien référentiel non repris.');
        }

        self::assertContains('Anglais', array_map(static fn (FicheMatiere $f): string => $f->getLibelle(), $this->fiches->horsDiplome()));
        self::assertNotEmpty(array_filter($this->fiches->findAll(), static fn (FicheMatiere $f): bool => $f->isImposed('mccc')), 'Aucune fiche avec MCCC imposées.');

        $formation = $this->em->getRepository(Formation::class)->findOneBy(['name' => 'Licence Sciences & Technologies (démo avec parcours)']);
        self::assertNotNull($formation);
        $doc = static::getContainer()->get(Maquette::class)->open($formation);
        $info = $this->parcours($doc, 'Parcours Informatique');
        $maths = $this->parcours($doc, 'Parcours Mathématiques');

        $shared = array_intersect($this->ficheIds($this->ecs($info)), $this->ficheIds($this->ecs($maths)));
        self::assertNotEmpty($shared, 'Aucune fiche partagée entre deux parcours de la Licence Sciences & Technologies.');
        $fiche = $this->fiches->find(reset($shared));
        self::assertNotNull($fiche);
        $mutualisations = static::getContainer()->get(FicheMatiereMutualisationRepository::class)->findBy(['fiche' => $fiche, 'formation' => $formation]);
        self::assertNotEmpty(array_filter(
            $mutualisations,
            static fn (FicheMatiereMutualisation $m): bool => $m->getParcoursNid() === $maths->nid,
        ), 'Le second parcours n’est pas autorisé à utiliser la fiche partagée.');

        $porteurs = array_values(array_filter($this->ecs($maths), fn (TreeNode $ec): bool => $this->values->requestedSource($ec, 'mccc') === EcValueResolver::SOURCE_PORTEUR));
        self::assertNotEmpty($porteurs, 'Aucun EC en source « porteur » pour les MCCC.');
        self::assertSame(EcValueResolver::SOURCE_PORTEUR, $this->values->resolve($porteurs[0], 'mccc')->source);
    }

    /** @return list<TreeNode> */
    private function ecs(TreeNode $node): array
    {
        $out = $node->getType()->isElementLevel() ? [$node] : [];
        foreach ($node->getChildren() as $child) {
            array_push($out, ...$this->ecs($child));
        }

        return $out;
    }

    /** @param list<TreeNode> $ecs */
    private function ficheIds(array $ecs): array
    {
        return array_values(array_filter(array_map(fn (TreeNode $ec): ?int => $this->values->ficheId($ec), $ecs)));
    }

    private function parcours(MaquetteDoc $doc, string $label): TreeNode
    {
        foreach ($doc->parcoursNodes() as $parcours) {
            if ($parcours->getLabel() === $label) {
                return $parcours;
            }
        }

        self::fail(\sprintf('Parcours « %s » introuvable dans les données de démonstration.', $label));
    }
}
