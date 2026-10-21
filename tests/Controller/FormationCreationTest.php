<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Formation;
use App\Entity\TypeDiplome;
use App\Maquette\Maquette;
use App\Maquette\MaquetteBuilder;
use App\Maquette\NodeView;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Création d'une formation pilotée par le type de diplôme (type, année universitaire, durée). */
final class FormationCreationTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    /** @param array<string, mixed> $override */
    private function create(string $type, array $override = []): void
    {
        $this->client->request('POST', '/formations', $override + [
            'name' => 'Ma formation',
            'typeDiplome' => $type,
            'duree' => '3',
            'anneeUniversitaire' => '2026-2027',
            'domaine' => 'Droit, économie, gestion',
            'composante' => 'UFR Droit',
        ]);
    }

    private function created(string $name = 'Ma formation'): ?Formation
    {
        $this->em->clear();

        return $this->em->getRepository(Formation::class)->findOneBy(['name' => $name]);
    }

    /** @return list<string> */
    private function labelsOf(Formation $formation, string $typeKey): array
    {
        $labels = [];
        $walk = static function (array $views) use (&$walk, &$labels, $typeKey): void {
            foreach ($views as $v) {
                /** @var NodeView $v */
                if ($v->node->typeKey === $typeKey) {
                    $labels[] = $v->ref;
                }
                $walk($v->children);
            }
        };
        $walk(static::getContainer()->get(MaquetteBuilder::class)->build($formation));

        return $labels;
    }

    public function testCreatingLpInTwoYearsGivesS5ToS8(): void
    {
        $this->create('LP', ['duree' => '2']);

        self::assertResponseRedirects();
        $formation = $this->created();
        self::assertNotNull($formation);
        self::assertSame('LP', $formation->getTypeDiplome()->getKey());
        self::assertSame('Licence professionnelle', $formation->getDiplome());
        self::assertSame('2026-2027', $formation->getAnneeUniversitaire());
        self::assertSame(2, $formation->getCalendarSpan());

        $doc = static::getContainer()->get(Maquette::class)->open($formation);
        self::assertCount(2, array_filter($doc->pedagogicalRoots(), static fn ($n) => 'annee' === $n->typeKey));
        $semestres = array_values(array_filter($doc->allNodes(), static fn ($n) => 'semestre' === $n->typeKey));
        self::assertCount(4, $semestres);
        // références calculées des semestres : numérotation de la version du type (LP : à partir de 5)
        self::assertSame(['5', '6', '7', '8'], $this->labelsOf($formation, 'semestre'));

        // l'élément imposé du diplôme est présent, verrouillé contre la suppression
        $imposed = array_values(array_filter($doc->allNodes(), static fn ($n) => 'lp-projet-stage' === $n->getAttribute('_imposed')));
        self::assertCount(1, $imposed);
        self::assertTrue($imposed[0]->isLocked('delete'));
    }

    public function testDurationOutsideBoundsIsRefused(): void
    {
        $this->create('LP', ['duree' => '3']);

        self::assertResponseRedirects('/');
        self::assertNull($this->created());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'hors des bornes');
        self::assertSelectorTextContains('body', 'de 1 à 2');
    }

    public function testArchivedTypeCannotBeChosen(): void
    {
        $this->em->getRepository(TypeDiplome::class)->findOneByKey('L')->setArchived(true);
        $this->em->flush();

        // le type archivé n'est pas proposé…
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('select[name=typeDiplome] option[value=L]'));
        self::assertGreaterThan(0, $crawler->filter('select[name=typeDiplome] option[value=LP]')->count());

        // … et un envoi forgé est refusé
        $this->create('L');
        self::assertResponseRedirects('/');
        self::assertNull($this->created());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'archivé');
    }

    public function testParcoursRefusedWhenTheTypeDoesNotAllowThem(): void
    {
        $this->create('M2E', ['duree' => '2', 'avecParcours' => '1']);

        self::assertNull($this->created());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'n’autorise pas les parcours');
    }

    public function testMultiParcoursCreationWhenAllowed(): void
    {
        $this->create('M', ['duree' => '2', 'avecParcours' => '1']);

        $formation = $this->created();
        self::assertTrue($formation->isAvecParcours());
        self::assertCount(1, static::getContainer()->get(Maquette::class)->open($formation)->parcoursNodes());
    }

    public function testUnknownTypeOrBadYearIsRefused(): void
    {
        $this->create('XX');
        self::assertNull($this->created());

        $this->create('L', ['anneeUniversitaire' => '2026']);
        self::assertNull($this->created());

        $this->create('L', ['anneeUniversitaire' => '2026-2028']);
        self::assertNull($this->created());

        // aucune version à cette date : la plus récente antérieure s'applique, avant la première, refus
        $this->create('L', ['anneeUniversitaire' => '2020-2021']);
        self::assertNull($this->created());
        $this->create('L', ['anneeUniversitaire' => '2030-2031']);
        self::assertNotNull($this->created());
    }

    public function testNoReferenceToStructureTemplateRemains(): void
    {
        $root = static::getContainer()->getParameter('kernel.project_dir');
        $hits = [];
        foreach (['src', 'templates'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $content = (string) file_get_contents($file->getPathname());
                foreach (['StructureTemplate', 'TemplateApplier', 'template_for_diplome'] as $needle) {
                    if (str_contains($content, $needle)) {
                        $hits[] = $file->getPathname().' : '.$needle;
                    }
                }
            }
        }

        self::assertSame([], $hits);
    }

    public function testStructurePageIsLockedByTheTypeForAResponsableAndFreeForAdmin(): void
    {
        $this->create('L');
        $formation = $this->created();
        $url = '/formations/'.$formation->getId().'/structure';

        $this->client->request('POST', '/role/responsable');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Fixé par le type de diplôme');
        self::assertSelectorNotExists('input[name=calendarSpan]');

        // un responsable ne peut pas basculer en multi-parcours par un envoi forgé
        $this->client->request('POST', '/formations/'.$formation->getId().'/settings', ['name' => 'Ma formation', 'avecParcours' => '1']);
        $this->em->clear();
        self::assertFalse($this->em->find(Formation::class, $formation->getId())->isAvecParcours());

        $this->client->request('POST', '/role/admin');
        $this->client->request('GET', $url);
        self::assertSelectorExists('input[name=calendarSpan]');
        self::assertSelectorNotExists('form[action$="/template/propager"]');
    }

    public function testAdminCannotTurnATypeWithoutParcoursIntoMultiParcours(): void
    {
        $this->create('M2E', ['duree' => '2']);
        $formation = $this->created();

        $this->client->request('POST', '/formations/'.$formation->getId().'/settings', ['name' => 'Ma formation', 'avecParcours' => '1']);

        $this->em->clear();
        self::assertFalse($this->em->find(Formation::class, $formation->getId())->isAvecParcours());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'n’autorise pas les parcours');
    }

    public function testAdminIndexNoLongerListsTemplates(): void
    {
        $this->client->request('GET', '/administration');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Templates de structure');
    }

    public function testResponsableCannotCreateAFormation(): void
    {
        $this->client->request('POST', '/role/responsable');
        $this->create('L');

        self::assertResponseRedirects('/');
        self::assertNull($this->created());
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('select[name=typeDiplome]'));
        self::assertSelectorTextNotContains('body', 'Ajouter une formation');
    }

    public function testAFailedGenerationLeavesNoFormationAndNoServerError(): void
    {
        // version dont l'élément imposé est mal placé : la génération échoue APRÈS l'écriture de la formation
        $version = $this->em->getRepository(TypeDiplome::class)->findOneByKey('L')->latestVersion();
        $config = $version->config()->toArray();
        $config['structure']['imposed'] = [['uid' => 'mal-place', 'typeKey' => 'ue', 'label' => 'X', 'parentLevel' => 'annee', 'position' => 'last', 'locked' => []]];
        $version->setConfig(\App\Diplome\Spec\VersionConfig::fromArray($config));
        $this->em->flush();

        $this->create('L');

        self::assertResponseRedirects('/');
        // la transaction annulée ferme le gestionnaire : on repart d'un neuf
        $this->em = static::getContainer()->get('doctrine')->resetManager();
        self::assertNull($this->created());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Aucune formation n’a été créée');
    }

    public function testRamifiedTypedFormationHasNoDurationGapAndShowsItsGraph(): void
    {
        $formation = $this->em->getRepository(Formation::class)->findOneBy(['name' => 'Licence Sciences & Technologies (démo avec parcours)']);
        $doc = static::getContainer()->get(Maquette::class)->open($formation);
        self::assertNotEmpty(array_filter($doc->parcoursNodes(), static fn ($p) => null !== $p->getParcoursParent()));
        $violations = static::getContainer()->get(\App\Maquette\Completion::class)->structureViolations($doc);
        self::assertSame([], array_filter($violations, static fn ($v) => str_starts_with($v->ruleKey, 'duration')));

        $crawler = $this->client->request('GET', '/formations/'.$formation->getId().'/parcours');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Portail commun (L1)');
        self::assertSelectorTextContains('body', 'Informatique — Génie logiciel');
        self::assertGreaterThan(0, $crawler->filter('svg path')->count());
    }
}
