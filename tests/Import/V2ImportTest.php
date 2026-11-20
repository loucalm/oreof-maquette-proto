<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Entity\FicheMatiere;
use App\Entity\Formation;
use App\Entity\Referentiel;
use App\Import\V2\V2ExtractFile;
use App\Import\V2\V2Importer;
use App\Import\V2\V2Reader;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Tests\Support\ResetsDatabase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Import ORéOF v2 : lecture d'une mini base v2 (SQLite, mêmes tables et colonnes que MariaDB), traduction dans le
 * prototype, remplacement à l'import suivant, extrait JSON anonymisé et commande.
 */
final class V2ImportTest extends KernelTestCase
{
    use ResetsDatabase;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    public function testReaderBuildsTheExtract(): void
    {
        $extract = (new V2Reader(self::v2()))->extract([10]);

        self::assertContains('Ada Lovelace', $extract['referentiels']['responsables']);
        self::assertContains('Troyes', $extract['referentiels']['villes']);
        $formation = $extract['formations'][0];
        self::assertSame('Géographie', $formation['name']);
        self::assertSame('GEO', $formation['fiche']['organisation']['sigle']);
        self::assertSame('Bac+3', $formation['fiche']['organisation']['niveauSortie']);
        $parcours = $formation['parcours'][0];
        self::assertTrue($parcours['defaut']);
        self::assertSame(['Formation Initiale', 'Formation Continue'], $parcours['fiche']['organisation']['regimes']);
        self::assertSame([1, 2], array_column($parcours['semestres'], 'ordre'));
        // S2 « raccroché » : ses UE sont celles du semestre source
        self::assertSame('UE mutualisée', $parcours['semestres'][1]['ues'][0]['label']);
        // UE à choix et ses options, EC avec heures et fiche
        $choix = $parcours['semestres'][0]['ues'][1];
        self::assertSame('À choix restreint', $choix['nature']);
        self::assertSame(['Option A', 'Option B'], array_column($choix['children'], 'label'));
        $ec = $parcours['semestres'][0]['ues'][0]['ecs'][0];
        self::assertSame(['pres' => ['cm' => 12, 'td' => 6], 'te' => 4], $ec['hours']);
        self::assertSame(500, $ec['fiche']);
        self::assertSame('Cartographie', $formation['fiches'][0]['data']['libelle']);
    }

    public function testImporterBuildsFormationsInThePrototype(): void
    {
        $report = $this->importer()->import((new V2Reader(self::v2()))->extract([10, 30]));
        self::assertContains('Formation v2 n°10 importée : « Géographie » (Licence, sans parcours, 1 fiche(s) matière).', $report);

        $mono = $this->imported(10);
        self::assertFalse($mono->isAvecParcours());
        $doc = static::getContainer()->get(Maquette::class)->open($mono);
        $semestres = array_values(array_filter($doc->allNodes(), static fn (TreeNode $n) => 'semestre' === $n->typeKey));
        self::assertCount(2, $semestres);
        $bloc = array_values(array_filter($doc->allNodes(), static fn (TreeNode $n) => 'bloc_choix' === $n->typeKey))[0];
        self::assertSame(['Option A', 'Option B'], array_map(static fn (TreeNode $n) => $n->getLabel(), $bloc->getChildren()));
        self::assertSame('choix_restreint', $bloc->getAttribute('nature'), 'Nature v2 rangée sur la clé du référentiel nature_elp.');

        $ec = array_values(array_filter($doc->allNodes(), static fn (TreeNode $n) => 'ec' === $n->typeKey && 'Lire une carte' === $n->getLabel()))[0];
        self::assertSame(3, $ec->getAttribute('ects'));
        $fiche = $this->em->getRepository(FicheMatiere::class)->find($ec->getAttribute('ficheMatiere'));
        self::assertSame('Cartographie', $fiche->getLibelle());
        self::assertSame($mono->getId(), $fiche->getFormation()->getId());

        // fiche du parcours par défaut : libellés v2 rangés sur les clés du référentiel (« Formation Initiale » → FI)
        self::assertSame(['FI', 'FC'], $mono->getParametre('parcours.organisation')['regimes']);
        self::assertSame('Ada Lovelace', $mono->getParametre('organisation')['respMention']);
        $villes = $this->em->getRepository(Referentiel::class)->findOneBy(['key' => 'villes'])->getValues();
        self::assertArrayHasKey('Troyes', $villes, 'Valeur v2 absente ajoutée au référentiel.');

        $multi = $this->imported(30);
        self::assertTrue($multi->isAvecParcours());
        $parcours = static::getContainer()->get(Maquette::class)->open($multi)->parcoursNodes();
        self::assertSame(['Tronc commun', 'Spécialité'], array_map(static fn (TreeNode $n) => $n->getLabel(), $parcours));
        self::assertSame($parcours[0]->nid, $parcours[1]->getParcoursParent()?->nid);
    }

    public function testImportingAgainReplacesThePreviousImport(): void
    {
        $extract = (new V2Reader(self::v2()))->extract([10]);
        $this->importer()->import($extract);
        $report = $this->importer()->import($extract);

        self::assertContains('Formation v2 n°10 : import précédent remplacé.', $report);
        $this->em->clear();
        $imported = array_filter($this->em->getRepository(Formation::class)->findAll(), static fn (Formation $f) => 10 === ($f->getParametres()[V2Importer::MARK]['id'] ?? null));
        self::assertCount(1, $imported);
        self::assertCount(1, $this->em->getRepository(FicheMatiere::class)->findBy(['libelle' => 'Cartographie']));
    }

    public function testSavedExtractIsAnonymized(): void
    {
        $path = sys_get_temp_dir().'/v2-extrait-'.bin2hex(random_bytes(4)).'.json';
        V2ExtractFile::save((new V2Reader(self::v2()))->extract([10]), $path);
        $json = (string) file_get_contents($path);
        $extract = V2ExtractFile::load($path);
        unlink($path);

        self::assertStringNotContainsString('Lovelace', $json);
        self::assertStringNotContainsString('scolarite@univ.fr', $json);
        self::assertStringNotContainsString('secretariat', $json);
        self::assertSame(['Responsable 1'], $extract['referentiels']['responsables']);
        self::assertSame('Responsable 1', $extract['formations'][0]['fiche']['organisation']['respMention']);
        self::assertStringContainsString('contact@exemple.fr', $extract['formations'][0]['parcours'][0]['fiche']['presentation']['debouches']);
    }

    public function testCommandReplaysASavedExtractWithoutTheV2Database(): void
    {
        $path = sys_get_temp_dir().'/v2-extrait-'.bin2hex(random_bytes(4)).'.json';
        V2ExtractFile::save((new V2Reader(self::v2()))->extract([10]), $path);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:import-v2'));

        $tester->execute(['--from' => $path]);
        unlink($path);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Formation v2 n°10 importée', $tester->getDisplay());
        self::assertNotNull($this->imported(10));
    }

    public function testCommandWithoutSourceExplainsHowToProceed(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:import-v2'));

        $status = $tester->execute([]);

        if ('' !== (string) ($_SERVER['OREOF_V2_DATABASE_URL'] ?? '')) {
            self::markTestSkipped('OREOF_V2_DATABASE_URL est réglée sur ce poste.');
        }
        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('--from=data/import-v2/exemple.json', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testVersionedExampleIsAnonymizedAndImportable(): void
    {
        $path = static::getContainer()->getParameter('kernel.project_dir').'/data/import-v2/exemple.json';
        $extract = V2ExtractFile::load($path);

        self::assertTrue($extract['anonymized'] ?? false);
        self::assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@(?!exemple\.fr)[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', (string) file_get_contents($path));
        $report = $this->importer()->import($extract);
        self::assertCount(\count($extract['formations']), array_filter($report, static fn (string $l) => str_contains($l, 'importée')));
    }

    private function importer(): V2Importer
    {
        return static::getContainer()->get(V2Importer::class);
    }

    private function imported(int $v2Id): ?Formation
    {
        foreach ($this->em->getRepository(Formation::class)->findAll() as $formation) {
            if ($v2Id === ($formation->getParametres()[V2Importer::MARK]['id'] ?? null)) {
                return $formation;
            }
        }

        return null;
    }

    /** Mini base ORéOF v2 : les tables et colonnes lues par V2Reader, quelques lignes. */
    private static function v2(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = [
            'type_diplome' => 'id, libelle_court', 'mention' => 'id, libelle', 'domaine' => 'id, libelle', 'composante' => 'id, libelle',
            'ville' => 'id, libelle', 'rythme_formation' => 'id, libelle', 'etablissement' => 'id, libelle', 'plateforme_admission' => 'id, libelle',
            'type_ue' => 'id, libelle', 'type_ec' => 'id, libelle, formation_id', 'type_epreuve' => 'id, libelle', 'nature_ue_ec' => 'id, libelle, choix, libre',
            'user' => 'id, prenom, nom', 'campagne_collecte' => 'id, libelle', 'dpe_parcours' => 'parcours_id, campagne_collecte_id',
            'formation' => 'id, type_diplome_id, mention_id, mention_texte, domaine_id, composante_porteuse_id, rythme_formation_id, sigle, niveau_entree, niveau_sortie, in_rncp, code_rncp, regime_inscription, modalites_alternance, code_mention_apogee, responsable_mention_id, co_responsable_id, objectifs_formation, resultats_attendus, contenu_formation, rythme_formation_texte',
            'parcours' => 'id, formation_id, libelle, ville_id, composante_inscription_id, rythme_formation_id, parcours_parent_id, regime_inscription, modalites_alternance, modalites_enseignement, has_stage, stage_text, nb_heures_stages, has_projet, projet_text, nb_heures_projet, has_memoire, memoire_text, has_situation_pro, situation_pro_text, nb_heures_situation_pro, code_apogee, resp_parcours_id, co_responsable_id, objectifs_parcours, mots_cles, resultats_attendus, contenu_formation, prerequis, rythme_formation_texte, coord_secretariat, poursuites_etudes, debouches, codes_rome',
            'semestre' => 'id, ordre, semestre_raccroche_id', 'semestre_parcours' => 'id, semestre_id, parcours_id, ordre',
            'ue' => 'id, semestre_id, type_ue_id, nature_ue_ec_id, ordre, ue_parent_id, libelle, ue_raccrochee_id, description_ue_libre, ects, code_apogee',
            'element_constitutif' => 'id, ue_id, ec_parent_id, type_ec_id, nature_ue_ec_id, fiche_matiere_id, ordre, libelle, texte_ec_libre, code, code_apogee, ects, volume_cm_presentiel, volume_td_presentiel, volume_tp_presentiel, volume_cm_distanciel, volume_td_distanciel, volume_tp_distanciel, volume_te',
            'fiche_matiere' => 'id, parcours_id, hors_diplome, libelle, libelle_anglais, sigle, code_apogee, description, objectifs, modalite_enseignement, responsable_fiche_matiere_id, volumes_horaires_impose, ects_impose, mccc_impose, ects, volume_cm_presentiel, volume_td_presentiel, volume_tp_presentiel, volume_cm_distanciel, volume_td_distanciel, volume_tp_distanciel, volume_te',
        ];
        foreach ($tables as $table => $columns) {
            $db->executeStatement(sprintf('CREATE TABLE "%s" (%s)', $table, $columns));
        }
        $insert = static fn (string $table, array $row) => $db->insert('"'.$table.'"', $row);

        $insert('type_diplome', ['id' => 1, 'libelle_court' => 'L']);
        $insert('mention', ['id' => 1, 'libelle' => 'Géographie']);
        $insert('domaine', ['id' => 1, 'libelle' => 'Sciences humaines et sociales']);
        $insert('composante', ['id' => 1, 'libelle' => 'UFR Lettres']);
        $insert('ville', ['id' => 1, 'libelle' => 'Troyes']);
        $insert('type_ue', ['id' => 1, 'libelle' => 'Disciplinaire']);
        $insert('type_ec', ['id' => 1, 'libelle' => 'Disciplinaire', 'formation_id' => null]);
        $insert('nature_ue_ec', ['id' => 1, 'libelle' => 'UE Obligatoire', 'choix' => 0, 'libre' => 0]);
        $insert('nature_ue_ec', ['id' => 4, 'libelle' => 'UE à choix restreint', 'choix' => 1, 'libre' => 0]);
        $insert('nature_ue_ec', ['id' => 8, 'libelle' => 'EC obligatoire', 'choix' => 0, 'libre' => 0]);
        $insert('user', ['id' => 7, 'prenom' => 'Ada', 'nom' => 'Lovelace']);

        // formation sans parcours (« Parcours par défaut »)
        $insert('formation', ['id' => 10, 'type_diplome_id' => 1, 'mention_id' => 1, 'domaine_id' => 1, 'composante_porteuse_id' => 1, 'sigle' => 'GEO', 'niveau_entree' => 14, 'niveau_sortie' => 16, 'in_rncp' => 1, 'code_rncp' => 'RNCP1', 'regime_inscription' => '["Formation Initiale"]', 'responsable_mention_id' => 7, 'objectifs_formation' => 'Former des géographes.']);
        $insert('parcours', ['id' => 100, 'formation_id' => 10, 'libelle' => 'Parcours par défaut', 'ville_id' => 1, 'regime_inscription' => '{"0":"Formation Initiale","2":"Formation Continue"}', 'modalites_enseignement' => 0, 'has_stage' => 1, 'stage_text' => 'Stage de 8 semaines', 'coord_secretariat' => 'Secrétariat : scolarite@univ.fr', 'debouches' => 'Écrire à scolarite@univ.fr', 'codes_rome' => '[{"code":"K1802 - Développement local"}]']);
        $insert('semestre', ['id' => 1000, 'ordre' => 1]);
        $insert('semestre', ['id' => 1001, 'ordre' => 2, 'semestre_raccroche_id' => 1002]);
        $insert('semestre', ['id' => 1002, 'ordre' => 2]);
        $insert('semestre_parcours', ['id' => 1, 'semestre_id' => 1000, 'parcours_id' => 100, 'ordre' => 1]);
        $insert('semestre_parcours', ['id' => 2, 'semestre_id' => 1001, 'parcours_id' => 100, 'ordre' => 2]);
        $insert('ue', ['id' => 1, 'semestre_id' => 1000, 'type_ue_id' => 1, 'nature_ue_ec_id' => 1, 'ordre' => 1, 'libelle' => 'Bases', 'ects' => 6]);
        $insert('ue', ['id' => 2, 'semestre_id' => 1000, 'nature_ue_ec_id' => 4, 'ordre' => 2, 'libelle' => 'Options', 'ects' => 3]);
        $insert('ue', ['id' => 3, 'semestre_id' => 1000, 'ue_parent_id' => 2, 'ordre' => 1, 'libelle' => 'Option A', 'ects' => 3]);
        $insert('ue', ['id' => 4, 'semestre_id' => 1000, 'ue_parent_id' => 2, 'ordre' => 2, 'libelle' => 'Option B', 'ects' => 3]);
        $insert('ue', ['id' => 5, 'semestre_id' => 1002, 'ordre' => 1, 'libelle' => 'UE mutualisée', 'ects' => 30]);
        $insert('element_constitutif', ['id' => 1, 'ue_id' => 1, 'type_ec_id' => 1, 'nature_ue_ec_id' => 8, 'fiche_matiere_id' => 500, 'ordre' => 1, 'libelle' => 'Lire une carte', 'ects' => 3, 'volume_cm_presentiel' => 12, 'volume_td_presentiel' => 6, 'volume_tp_presentiel' => 0, 'volume_te' => 4]);
        $insert('fiche_matiere', ['id' => 500, 'parcours_id' => 100, 'hors_diplome' => 0, 'libelle' => 'Cartographie', 'responsable_fiche_matiere_id' => 7, 'ects' => 3]);

        // formation à deux parcours, le second rattaché au premier (ramification)
        $insert('formation', ['id' => 30, 'type_diplome_id' => 1, 'mention_texte' => 'Histoire', 'domaine_id' => 1]);
        $insert('parcours', ['id' => 300, 'formation_id' => 30, 'libelle' => 'Tronc commun']);
        $insert('parcours', ['id' => 301, 'formation_id' => 30, 'libelle' => 'Spécialité', 'parcours_parent_id' => 300]);
        $insert('semestre', ['id' => 3000, 'ordre' => 1]);
        $insert('semestre', ['id' => 3001, 'ordre' => 3]);
        $insert('semestre_parcours', ['id' => 3, 'semestre_id' => 3000, 'parcours_id' => 300, 'ordre' => 1]);
        $insert('semestre_parcours', ['id' => 4, 'semestre_id' => 3001, 'parcours_id' => 301, 'ordre' => 1]);

        return $db;
    }
}
