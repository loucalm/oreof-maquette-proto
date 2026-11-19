<?php

declare(strict_types=1);

namespace App\Tests\FicheMatiere;

use App\Diplome\Spec\CanvasEntry;
use App\Diplome\Spec\CanvasSpec;
use App\Diplome\Spec\VersionConfig;
use App\Diplome\TypeDiplomeResolver;
use App\Diplome\VersionMccc;
use App\Entity\FicheMatiere;
use App\Entity\Formation;
use App\Entity\NodeType;
use App\Entity\TypeDiplome;
use App\Enum\FormScope;
use App\FicheMatiere\FicheMatiereForms;
use App\FieldType\Options\FieldOptionsSources;
use App\Formulaire\CanvasResolver;
use App\Formulaire\ResolvedField;
use App\Formulaire\VariantFactory;
use App\Maquette\Maquette;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\EditsCanvas;
use App\Tests\Support\FormationFactory;
use App\Twig\FieldTypeExtension;
use App\Twig\MaquetteExtension;

final class FicheMatiereFormsTest extends DatabaseTestCase
{
    use EditsCanvas;

    private FicheMatiereForms $forms;
    private Formation $formation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forms = static::getContainer()->get(FicheMatiereForms::class);
        $this->formation = FormationFactory::create(static::getContainer())->fromType('L', 3, avecParcours: true, withLeaves: true);
    }

    public function testDefaultFormIsSocle(): void
    {
        $socle = $this->socle();

        self::assertSame($socle, $this->forms->formFor($this->porteurFiche()));
        self::assertSame($socle, $this->forms->formFor($this->horsDiplome()));
        self::assertNull($this->forms->configFor($this->horsDiplome()));
        self::assertSame(FicheMatiereForms::TABS, ['identite', 'mutualisation', 'presentation', 'competences', 'volume_horaire', 'mccc']);

        // champs socles, par onglet, dans l'ordre
        $byTab = [];
        foreach ($this->forms->fields($this->porteurFiche()) as $field) {
            $byTab[$field->tab][] = $field->getKey();
        }
        self::assertSame([
            'identite' => ['libelle', 'libelleEn', 'sigle', 'referent', 'codeApogeeFiche', 'typeEc'],
            'presentation' => ['description', 'objectifsEnseignement', 'languesCours', 'languesSupport', 'modaliteEnseignement'],
            'competences' => ['competencies'],
            'volume_horaire' => ['hours', 'volumesImposes'],
            'mccc' => ['ects', 'ectsImposes', 'mccc', 'mcccImposes'],
        ], $byTab);
    }

    public function testVersionCanvasPicksVariant(): void
    {
        $variant = static::getContainer()->get(VariantFactory::class)->create($this->socle(), 'Fiche matière · L');
        $this->em->flush();
        $canvas = $this->canvasOf($this->formation);
        $this->setCanvas($this->formation, $canvas->withEntries(CanvasSpec::FICHE_MATIERE, [
            new CanvasEntry(CanvasSpec::FICHE_MATIERE, $variant->getKey(), null, null, false),
        ]));

        self::assertSame($variant->getKey(), $this->forms->formFor($this->porteurFiche())->getKey());
        // fiche hors diplôme de ce type : dernière version du type
        self::assertSame($variant->getKey(), $this->forms->formFor($this->horsDiplome($this->formation->getTypeDiplome()))->getKey());
        // fiche hors diplôme sans type : le socle
        self::assertSame($this->socle()->getKey(), $this->forms->formFor($this->horsDiplome())->getKey());
    }

    public function testHorsDiplomeHasNoCompetencesTab(): void
    {
        $keys = static fn (array $fields) => array_map(static fn (ResolvedField $f) => $f->getKey(), $fields);

        self::assertContains('competencies', $keys($this->forms->fields($this->porteurFiche())));
        $hors = $this->forms->fields($this->horsDiplome());
        self::assertNotContains('competencies', $keys($hors));
        self::assertSame([], array_filter($hors, static fn (ResolvedField $f) => 'competences' === $f->tab));
        self::assertContains('hours', $keys($hors));
    }

    public function testCanvasSpecRoundTrip(): void
    {
        $spec = new CanvasSpec([], [], [new CanvasEntry(CanvasSpec::FICHE_MATIERE, 'param_fiche_matiere_x', null, null, false)]);

        $back = CanvasSpec::fromArray($spec->toArray());
        self::assertSame('param_fiche_matiere_x', $back->entry(CanvasSpec::FICHE_MATIERE, CanvasSpec::FICHE_MATIERE)?->form);
        self::assertContains(CanvasSpec::FICHE_MATIERE, CanvasSpec::FICHES);

        // une seule entrée, ni libellé, ni masquage, ni écran intégré
        $normalised = CanvasSpec::fromArray(['fiche_matiere' => [
            ['key' => 'autre', 'form' => 'param_a', 'label' => 'Renommée', 'hidden' => true],
            ['key' => 'encore', 'form' => 'param_b'],
        ]]);
        self::assertEquals([new CanvasEntry(CanvasSpec::FICHE_MATIERE, 'param_a', null, null, false)], $normalised->entries(CanvasSpec::FICHE_MATIERE));

        // canevas enregistré sans fiche matière (versions existantes) : le formulaire socle
        $resolved = static::getContainer()->get(CanvasResolver::class)->forConfig(VersionConfig::fromArray(['canvas' => ['formation' => [], 'parcours' => []]]));
        self::assertEquals([new CanvasEntry(CanvasSpec::FICHE_MATIERE, $this->socle()->getKey(), null, null, false)], $resolved->entries(CanvasSpec::FICHE_MATIERE));
    }

    public function testOptionsFollowVersion(): void
    {
        $none = ['quitus' => false, 'assiduite' => false, 'sansNote' => false];
        self::assertSame($none, $this->forms->options($this->horsDiplome()));

        $version = static::getContainer()->get(TypeDiplomeResolver::class)->versionFor($this->formation);
        $version->setConfig(VersionConfig::fromArray(['options' => ['quitus' => true, 'assiduite' => false, 'sansNote' => true]] + $version->config()->toArray()));
        $this->em->flush();

        self::assertSame(['quitus' => true, 'assiduite' => false, 'sansNote' => true], $this->forms->options($this->porteurFiche()));
        self::assertSame($version, $this->forms->versionFor($this->porteurFiche()));
        self::assertSame(['quitus' => true, 'assiduite' => false, 'sansNote' => true], $this->forms->options($this->horsDiplome($this->formation->getTypeDiplome())));
    }

    public function testTypeEcOptionsFromVersionNatures(): void
    {
        $natures = static::getContainer()->get(TypeDiplomeResolver::class)->configFor($this->formation)->structure->natureKeys();
        self::assertNotEmpty($natures);

        // `typeEc` : source d'options `version_natures`, lue pour la fiche (version de sa formation porteuse)
        $sources = static::getContainer()->get(FieldOptionsSources::class);
        self::assertSame($natures, array_keys($sources->options('version_natures', $this->porteurFiche())));
        self::assertSame($natures, array_keys($sources->options('version_natures', $this->horsDiplome($this->formation->getTypeDiplome()))));
        self::assertSame([], $sources->options('version_natures', $this->horsDiplome()));

        $ext = static::getContainer()->get(MaquetteExtension::class);
        self::assertSame(['quitus' => false, 'assiduite' => false, 'sansNote' => false], $ext->mcccOptions($this->horsDiplome()));
    }

    public function testMcccTypesAndCompetenciesForFiche(): void
    {
        $ext = static::getContainer()->get(MaquetteExtension::class);
        $versionMccc = static::getContainer()->get(VersionMccc::class);
        $parcours = static::getContainer()->get(Maquette::class)->open($this->formation)->parcoursNodes()[0];
        $ec = null;
        foreach (static::getContainer()->get(Maquette::class)->open($this->formation)->allNodes() as $n) {
            if ('ec' === $n->getType()->getKey() && null !== $n->getParent()) {
                $ec = $n;
                break;
            }
        }
        self::assertNotNull($ec);

        $porteur = $this->porteurFiche();
        $typeKeys = static fn (?array $types) => null === $types ? null : array_map(static fn ($t) => $t->getKey(), $types);
        self::assertSame($typeKeys($versionMccc->typesFor($ec)), $typeKeys($versionMccc->typesForFiche($porteur)));
        self::assertNull($versionMccc->typesForFiche($this->horsDiplome()));
        // sans version : tous les types du catalogue, comme pour un nœud
        $mcccTypesFor = $this->twigFunction($ext, 'mccc_types_for');
        self::assertNotEmpty($mcccTypesFor($this->horsDiplome()));

        // compétences : celles du parcours porteur ; aucune hors diplôme
        $doc = static::getContainer()->get(Maquette::class)->open($this->formation);
        $bloc = $doc->addNode($parcours->nid, 'bloc_competences', 'Bloc du parcours');
        $doc->addNode($bloc->nid, 'competence', 'Compétence du parcours');
        $bcc = $this->twigFunction($ext, 'bcc_competences');
        self::assertNotEmpty($bcc($porteur));
        self::assertSame($ext->bccCompetences($parcours), $bcc($porteur));
        self::assertSame([], $bcc($this->horsDiplome()));
    }

    public function testWidgetsRenderForFiche(): void
    {
        $version = static::getContainer()->get(TypeDiplomeResolver::class)->versionFor($this->formation);
        $version->setConfig(VersionConfig::fromArray(['options' => ['quitus' => true, 'assiduite' => false, 'sansNote' => false]] + $version->config()->toArray()));
        $parcours = static::getContainer()->get(Maquette::class)->open($this->formation)->parcoursNodes()[0];
        $doc = static::getContainer()->get(Maquette::class)->open($this->formation);
        $bloc = $doc->addNode($parcours->nid, 'bloc_competences', 'Bloc du parcours');
        $doc->addNode($bloc->nid, 'competence', 'Compétence du parcours porteur');
        $fiche = $this->porteurFiche()->setDefault('mccc', ['type' => 'CT', 'evaluations' => []]);
        $this->em->flush();

        $twig = static::getContainer()->get('twig');
        $ext = static::getContainer()->get(FieldTypeExtension::class);
        $html = [];
        foreach ($this->forms->fields($fiche) as $field) {
            self::assertTrue($ext->available($field, $fiche), $field->getKey());
            $html[$field->getKey()] = $ext->widget($twig, $field, $field->getKey(), $fiche->getDefault($field->getKey()) ?? $fiche->getDataValue($field->getKey()), 'f['.$field->getKey().']', $fiche, [], 'node');
        }

        self::assertStringContainsString('value="CT" checked', $html['mccc']);
        self::assertStringContainsString('Quitus', $html['mccc']);
        self::assertStringContainsString('Compétence du parcours porteur', $html['competencies']);
        self::assertStringContainsString('f[hours][pres][cm]', $html['hours']);
        $natures = static::getContainer()->get(TypeDiplomeResolver::class)->configFor($this->formation)->structure->natureKeys();
        self::assertStringContainsString('value="'.$natures[0].'"', $html['typeEc']);
    }

    private function twigFunction(MaquetteExtension $ext, string $name): callable
    {
        foreach ($ext->getFunctions() as $function) {
            if ($function->getName() === $name) {
                return $function->getCallable();
            }
        }
        self::fail($name);
    }

    private function socle(): NodeType
    {
        $socles = array_values(array_filter(
            $this->em->getRepository(NodeType::class)->findAll(),
            static fn (NodeType $t) => FormScope::FicheMatiere === $t->getScope() && null === $t->getParent(),
        ));
        self::assertCount(1, $socles);

        return $socles[0];
    }

    private function porteurFiche(): FicheMatiere
    {
        $parcours = static::getContainer()->get(Maquette::class)->open($this->formation)->parcoursNodes()[0];
        $fiche = (new FicheMatiere())->setFormation($this->formation)->setParcoursNid($parcours->nid)->setData(['libelle' => 'Porteur']);
        $this->em->persist($fiche);
        $this->em->flush();

        return $fiche;
    }

    private function horsDiplome(?TypeDiplome $type = null): FicheMatiere
    {
        $fiche = (new FicheMatiere())->setHorsDiplome(true)->setTypeDiplome($type)->setData(['libelle' => 'Libre']);
        $this->em->persist($fiche);
        $this->em->flush();

        return $fiche;
    }
}
