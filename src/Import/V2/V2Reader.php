<?php

declare(strict_types=1);

namespace App\Import\V2;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Lecture seule de la base d'ORéOF v2 (MariaDB) : produit un « extrait » (tableau JSON-isable, cf. docs/import-v2.md)
 * que V2Importer traduit dans le prototype. Rien n'est jamais écrit dans la base v2 (que des SELECT).
 *
 * Les valeurs sont rendues en libellés (domaine, ville, type d'UE…) : c'est l'import qui les range dans les
 * référentiels du prototype. Le SQL reste portable (aucune fonction propre à MariaDB) pour être testable sur SQLite.
 */
final class V2Reader
{
    /** Libellé de v2 pour la formation sans parcours (Parcours::PARCOURS_DEFAUT). */
    public const PARCOURS_DEFAUT = 'Parcours par défaut';

    /** Niveaux RNCP de v2 (NiveauFormationEnum) → libellés des référentiels du prototype. */
    private const NIVEAUX = [14 => 'Baccalauréat', 15 => 'Bac+2', 16 => 'Bac+3', 17 => 'Bac+5', 18 => 'Bac+8'];

    /** Types de MCCC de v2 (`type_mccc`) → types du prototype (MccType). */
    private const MCCC_TYPES = ['cc' => 'CC', 'ct' => 'CT', 'cc_ct' => 'CC_CT', 'cci' => 'CCI', 'cc_tp' => 'CC'];

    /** Modalités d'évaluation du BUT dans v2 (translations/but.fr.yaml) : codes rangés dans `type_epreuve`. */
    private const BUT_MODALITES = [
        'td_tp_oral' => 'TD/TP Oral', 'td_tp_ecrit' => 'TD/TP Écrit', 'td_tp_rapport' => 'TD/TP Rapport', 'td_tp_autre' => 'TD/TP Autre évaluation',
        'cm_ecrit' => 'Épreuve promotion entière Écrit', 'cm_rapport' => 'Épreuve promotion entière Rapport',
        'iut_portfolio' => 'Portfolio', 'iut_livrable' => 'Livrable', 'iut_rapport' => 'Rapport', 'iut_soutenance' => 'Soutenance',
        'hors_iut_entreprise' => 'Stage / Entreprise', 'hors_iut_rapport' => 'Stage / Rapport', 'hors_iut_soutenance' => 'Stage / Soutenance',
    ];

    /** @var array<int, string>|null types d'épreuve de v2 : id → libellé */
    private ?array $epreuves = null;

    /** La formation en cours de lecture est un BUT : seules ses MCCC suivent les modalités codées du BUT. */
    private bool $but = false;

    /** Modalités d'enseignement de v2 (ModaliteEnseignementEnum) → référentiel `modalites_enseignement`. */
    private const MODALITES = [0 => 'En présentiel', 1 => 'Hybride', 2 => 'En distanciel'];

    /** @var array<int, string> responsables rencontrés : id utilisateur v2 → « Prénom Nom » */
    private array $people = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /** Connexion en lecture à partir d'une URL du type `mysql://user:motdepasse@hote:3306/oreof_v2`. */
    public static function fromDsn(string $dsn): self
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'sqlite' => 'pdo_sqlite']))->parse($dsn);

        return new self(DriverManager::getConnection($params + ['charset' => 'utf8mb4']));
    }

    /**
     * Formations de v2, pour choisir quoi importer.
     *
     * @return list<array{id: int, type: string, name: string, parcours: int, campagne: ?string}>
     */
    public function listFormations(?string $type = null): array
    {
        $sql = 'SELECT f.id, td.libelle_court AS type, COALESCE(m.libelle, f.mention_texte) AS name, f.sigle,
                       (SELECT COUNT(*) FROM parcours p WHERE p.formation_id = f.id) AS parcours,
                       (SELECT MAX(cc.libelle) FROM dpe_parcours dp JOIN campagne_collecte cc ON cc.id = dp.campagne_collecte_id
                         JOIN parcours p2 ON p2.id = dp.parcours_id WHERE p2.formation_id = f.id) AS campagne
                FROM formation f
                LEFT JOIN type_diplome td ON td.id = f.type_diplome_id
                LEFT JOIN mention m ON m.id = f.mention_id'
            .(null !== $type ? ' WHERE td.libelle_court = :type' : '')
            .' ORDER BY td.libelle_court, name, f.id';

        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'type' => (string) $r['type'],
            'name' => self::name($r['name'], $r['sigle']),
            'parcours' => (int) $r['parcours'],
            'campagne' => null !== $r['campagne'] ? (string) $r['campagne'] : null,
        ], $this->db->fetchAllAssociative($sql, null !== $type ? ['type' => $type] : []));
    }

    /**
     * Extrait complet : référentiels liés (listes de libellés) et formations demandées.
     *
     * @param list<int> $formationIds
     *
     * @return array<string, mixed>
     */
    public function extract(array $formationIds): array
    {
        $this->people = [];
        $this->epreuves = null;
        $formations = [];
        foreach ($formationIds as $id) {
            $formations[] = $this->formation($id);
        }

        return [
            'source' => 'ORéOF v2',
            'exportedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'referentiels' => $this->referentiels() + ['responsables' => array_values(array_unique($this->people))],
            'formations' => $formations,
        ];
    }

    /** @return array<string, list<string>> clé de référentiel du prototype → libellés */
    private function referentiels(): array
    {
        $labels = fn (string $sql): array => array_values(array_unique(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            $this->db->fetchFirstColumn($sql),
        ), static fn (string $v) => '' !== $v)));

        return [
            'domaines' => $labels('SELECT libelle FROM domaine ORDER BY libelle'),
            'mentions' => $labels('SELECT libelle FROM mention ORDER BY libelle'),
            'composantes' => $labels('SELECT libelle FROM composante ORDER BY libelle'),
            'villes' => $labels('SELECT libelle FROM ville ORDER BY libelle'),
            'rythmes' => $labels('SELECT libelle FROM rythme_formation ORDER BY libelle'),
            'etablissements' => $labels('SELECT libelle FROM etablissement ORDER BY libelle'),
            'plateformes_admission' => $labels('SELECT libelle FROM plateforme_admission ORDER BY libelle'),
            'types_ue' => $labels('SELECT libelle FROM type_ue ORDER BY libelle'),
            'types_ec' => $labels('SELECT libelle FROM type_ec WHERE formation_id IS NULL ORDER BY libelle'),
            'types_epreuve' => $labels('SELECT libelle FROM type_epreuve ORDER BY libelle'),
        ];
    }

    /** @return array<string, mixed> */
    private function formation(int $id): array
    {
        $f = $this->db->fetchAssociative(
            'SELECT f.*, td.libelle_court AS type_court, m.libelle AS mention_libelle, d.libelle AS domaine_libelle,
                    c.libelle AS composante_libelle, rf.libelle AS rythme_libelle
             FROM formation f
             LEFT JOIN type_diplome td ON td.id = f.type_diplome_id
             LEFT JOIN mention m ON m.id = f.mention_id
             LEFT JOIN domaine d ON d.id = f.domaine_id
             LEFT JOIN composante c ON c.id = f.composante_porteuse_id
             LEFT JOIN rythme_formation rf ON rf.id = f.rythme_formation_id
             WHERE f.id = :id',
            ['id' => $id],
        ) ?: throw new \InvalidArgumentException(sprintf('Formation v2 n°%d introuvable.', $id));
        $this->but = 'BUT' === $f['type_court'];

        $parcours = [];
        $fiches = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.*, v.libelle AS ville_libelle, ci.libelle AS composante_inscription_libelle, rf.libelle AS rythme_libelle
             FROM parcours p
             LEFT JOIN ville v ON v.id = p.ville_id
             LEFT JOIN composante ci ON ci.id = p.composante_inscription_id
             LEFT JOIN rythme_formation rf ON rf.id = p.rythme_formation_id
             WHERE p.formation_id = :id ORDER BY p.id',
            ['id' => $id],
        );
        foreach ($rows as $p) {
            $parcours[] = $this->parcours($p, $fiches);
        }

        return [
            'id' => (int) $f['id'],
            'typeDiplome' => (string) $f['type_court'],
            'name' => self::name($f['mention_libelle'] ?? $f['mention_texte'], null) ?: sprintf('Formation v2 n°%d', $id),
            'domaine' => self::text($f['domaine_libelle']),
            'composante' => self::text($f['composante_libelle']),
            'fiche' => [
                'organisation' => self::compact([
                    'sigle' => self::text($f['sigle']),
                    'mention' => self::text($f['mention_libelle'] ?? $f['mention_texte']),
                    'niveauEntree' => self::NIVEAUX[(int) $f['niveau_entree']] ?? null,
                    'niveauSortie' => self::NIVEAUX[(int) $f['niveau_sortie']] ?? null,
                    'rncp' => null === $f['in_rncp'] ? null : ($f['in_rncp'] ? 'oui' : 'non'),
                    'codeRncp' => self::text($f['code_rncp']),
                    'localisations' => array_values(array_unique(array_filter(array_map(static fn (array $p) => self::text($p['ville_libelle']), $rows)))),
                    'regimes' => self::list($f['regime_inscription']),
                    'modalitesAlternance' => self::text($f['modalites_alternance']),
                    'codeApogee' => self::text($f['code_mention_apogee']),
                    'respMention' => $this->person($f['responsable_mention_id']),
                    'coRespMention' => $this->person($f['co_responsable_id']),
                ]),
                'presentation' => self::compact([
                    'objectif' => self::text($f['objectifs_formation']),
                    'resultats' => self::text($f['resultats_attendus']),
                    'contenu' => self::text($f['contenu_formation']),
                    'rythme' => self::text($f['rythme_libelle']),
                    'rythmePrecision' => self::text($f['rythme_formation_texte']),
                ]),
            ],
            'parcours' => $parcours,
            'fiches' => array_values($fiches),
        ];
    }

    /**
     * @param array<string, mixed>              $p
     * @param array<int, array<string, mixed>> $fiches fiches matière rencontrées (id v2 → fiche), complétées ici
     *
     * @return array<string, mixed>
     */
    private function parcours(array $p, array &$fiches): array
    {
        $semestres = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.ordre, s.semestre_raccroche_id, sp.ordre AS sp_ordre FROM semestre_parcours sp
             JOIN semestre s ON s.id = sp.semestre_id WHERE sp.parcours_id = :id ORDER BY s.ordre, sp.ordre',
            ['id' => $p['id']],
        );
        foreach ($rows as $s) {
            $source = (int) $s['id'];
            // semestre mutualisé « raccroché » : son contenu est celui du semestre source
            if (null !== $s['semestre_raccroche_id'] && 0 === (int) $this->db->fetchOne('SELECT COUNT(*) FROM ue WHERE semestre_id = :id', ['id' => $source])) {
                $source = (int) $s['semestre_raccroche_id'];
            }
            $semestres[] = ['ordre' => (int) ($s['ordre'] ?? $s['sp_ordre']), 'ues' => $this->ues($source, null, $fiches)];
        }

        return [
            'id' => (int) $p['id'],
            'label' => self::text($p['libelle']) ?? 'Parcours',
            'defaut' => self::PARCOURS_DEFAUT === $p['libelle'],
            'parentId' => null !== $p['parcours_parent_id'] ? (int) $p['parcours_parent_id'] : null,
            'fiche' => [
                'organisation' => self::compact([
                    'localisations' => array_values(array_filter([self::text($p['ville_libelle'])])),
                    'composanteInscription' => self::text($p['composante_inscription_libelle']),
                    'regimes' => self::list($p['regime_inscription']),
                    'modalitesAlternance' => self::text($p['modalites_alternance']),
                    'modalitesEnseignement' => self::MODALITES[(int) ($p['modalites_enseignement'] ?? 99)] ?? null,
                    'stage' => self::yesNo($p['has_stage']),
                    'stageDescription' => self::text($p['stage_text']),
                    'stageHeures' => self::number($p['nb_heures_stages']),
                    'projetTutore' => self::yesNo($p['has_projet']),
                    'projetTutoreDescription' => self::text($p['projet_text']),
                    'projetTutoreHeures' => self::number($p['nb_heures_projet']),
                    'memoire' => self::yesNo($p['has_memoire']),
                    'memoireDescription' => self::text($p['memoire_text']),
                    'situationPro' => self::yesNo($p['has_situation_pro']),
                    'situationProDescription' => self::text($p['situation_pro_text']),
                    'situationProHeures' => self::number($p['nb_heures_situation_pro']),
                    'codeApogee' => self::text($p['code_apogee']),
                    'respParcours' => $this->person($p['resp_parcours_id']),
                    'coRespParcours' => $this->person($p['co_responsable_id']),
                ]),
                'presentation' => self::compact([
                    'objectif' => self::text($p['objectifs_parcours']),
                    'motsCles' => self::text($p['mots_cles']),
                    'resultats' => self::text($p['resultats_attendus']),
                    'contenu' => self::text($p['contenu_formation']),
                    'prerequis' => self::text($p['prerequis']),
                    'rythme' => self::text($p['rythme_libelle']),
                    'rythmePrecision' => self::text($p['rythme_formation_texte']),
                    'secretariat' => self::text($p['coord_secretariat']),
                    'poursuiteEtudes' => self::text($p['poursuites_etudes']),
                    'debouches' => self::text($p['debouches']),
                    'codesRome' => self::romeCodes($p['codes_rome']),
                ]),
            ],
            'semestres' => $semestres,
        ];
    }

    /**
     * UE d'un semestre (ou UE « enfants » d'une UE à choix), dans l'ordre.
     *
     * @param array<int, array<string, mixed>> $fiches
     *
     * @return list<array<string, mixed>>
     */
    private function ues(int $semestreId, ?int $parentId, array &$fiches): array
    {
        // options d'une UE à choix : rattachées à leur seule UE parente (dans v2, leur semestre peut manquer ou différer)
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.*, tu.libelle AS type_libelle, n.libelle AS nature_libelle, n.choix AS nature_choix, n.libre AS nature_libre
             FROM ue u LEFT JOIN type_ue tu ON tu.id = u.type_ue_id LEFT JOIN nature_ue_ec n ON n.id = u.nature_ue_ec_id
             WHERE '.(null === $parentId ? 'u.semestre_id = :id AND u.ue_parent_id IS NULL' : 'u.ue_parent_id = :id').' ORDER BY u.ordre, u.id',
            ['id' => $parentId ?? $semestreId],
        );
        $out = [];
        foreach ($rows as $u) {
            $source = (int) $u['id'];
            // UE mutualisée « raccrochée » : ses EC sont ceux de l'UE source
            if (null !== $u['ue_raccrochee_id'] && 0 === (int) $this->db->fetchOne('SELECT COUNT(*) FROM element_constitutif WHERE ue_id = :id', ['id' => $source])) {
                $source = (int) $u['ue_raccrochee_id'];
            }
            $out[] = [
                'id' => (int) $u['id'],
                'label' => self::text($u['libelle']) ?? self::text($u['description_ue_libre']) ?? '',
                'code' => self::text($u['code_apogee']),
                'ects' => self::number($u['ects']),
                'type' => self::text($u['type_libelle']),
                'nature' => self::nature($u['nature_libelle']),
                'choix' => (bool) $u['nature_choix'] || (bool) $u['nature_libre'],
                'children' => $this->ues($semestreId, (int) $u['id'], $fiches),
                'ecs' => $this->ecs($source, null, $fiches),
            ];
        }

        return $out;
    }

    /**
     * EC d'une UE (ou EC « enfants » d'un EC à choix, rattachés à leur seul parent : dans v2 leur UE peut manquer), dans l'ordre.
     *
     * @param array<int, array<string, mixed>> $fiches
     *
     * @return list<array<string, mixed>>
     */
    private function ecs(int $ueId, ?int $parentId, array &$fiches): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT ec.*, te.libelle AS type_libelle, n.libelle AS nature_libelle, n.choix AS nature_choix, n.libre AS nature_libre,
                    fm.libelle AS fiche_libelle
             FROM element_constitutif ec
             LEFT JOIN type_ec te ON te.id = ec.type_ec_id
             LEFT JOIN nature_ue_ec n ON n.id = ec.nature_ue_ec_id
             LEFT JOIN fiche_matiere fm ON fm.id = ec.fiche_matiere_id
             WHERE '.(null === $parentId ? 'ec.ue_id = :id AND ec.ec_parent_id IS NULL' : 'ec.ec_parent_id = :id').' ORDER BY ec.ordre, ec.id',
            ['id' => $parentId ?? $ueId],
        );
        $out = [];
        foreach ($rows as $ec) {
            $ficheId = null !== $ec['fiche_matiere_id'] ? (int) $ec['fiche_matiere_id'] : null;
            if (null !== $ficheId && !isset($fiches[$ficheId])) {
                $fiches[$ficheId] = $this->fiche($ficheId);
            }
            $out[] = [
                'id' => (int) $ec['id'],
                'label' => self::text($ec['libelle']) ?? self::text($ec['fiche_libelle']) ?? self::text($ec['texte_ec_libre']) ?? '',
                'code' => self::text($ec['code_apogee']) ?? self::text($ec['code']),
                'ects' => self::number($ec['ects']),
                'type' => self::text($ec['type_libelle']),
                'nature' => self::nature($ec['nature_libelle']),
                'choix' => (bool) $ec['nature_choix'] || (bool) $ec['nature_libre'],
                'hours' => self::hours($ec),
                'mccc' => $this->mccc('ec_id', (int) $ec['id'], $ec['type_mccc'] ?? null, [
                    'quitus' => (bool) ($ec['quitus'] ?? false),
                    'quitusTexte' => self::text($ec['quitus_text'] ?? null),
                    'assiduite' => (bool) ($ec['controle_assiduite'] ?? false),
                ]),
                'fiche' => $ficheId,
                'children' => $this->ecs($ueId, (int) $ec['id'], $fiches),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function fiche(int $id): array
    {
        $f = $this->db->fetchAssociative('SELECT * FROM fiche_matiere WHERE id = :id', ['id' => $id]) ?: [];

        return [
            'id' => $id,
            'parcoursId' => null !== ($f['parcours_id'] ?? null) ? (int) $f['parcours_id'] : null,
            'horsDiplome' => (bool) ($f['hors_diplome'] ?? false),
            'data' => self::compact([
                'libelle' => self::text($f['libelle'] ?? null) ?? sprintf('Fiche v2 n°%d', $id),
                'libelleEn' => self::text($f['libelle_anglais'] ?? null),
                'sigle' => self::text($f['sigle'] ?? null),
                'codeApogeeFiche' => self::text($f['code_apogee'] ?? null),
                'description' => self::text($f['description'] ?? null),
                'objectifsEnseignement' => self::text($f['objectifs'] ?? null),
                'modaliteEnseignement' => self::MODALITES[(int) ($f['modalite_enseignement'] ?? 99)] ?? null,
                'referent' => $this->person($f['responsable_fiche_matiere_id'] ?? null),
                'volumesImposes' => self::yesNo($f['volumes_horaires_impose'] ?? null),
                'ectsImposes' => self::yesNo($f['ects_impose'] ?? null),
                'mcccImposes' => self::yesNo($f['mccc_impose'] ?? null),
            ]),
            'defaults' => self::compact([
                'ects' => self::number($f['ects'] ?? null),
                'hours' => self::hours($f),
                'mccc' => $this->mccc('fiche_matiere_id', $id, $f['type_mccc'] ?? null, [
                    'quitus' => (bool) ($f['quitus'] ?? false),
                    'quitusTexte' => self::text($f['quitus_text'] ?? null),
                    'sansNote' => (bool) ($f['sans_note'] ?? false),
                ]),
            ]),
        ];
    }

    /**
     * MCCC d'un EC (`ec_id`) ou d'une fiche (`fiche_matiere_id`, lignes propres à la fiche), au format du prototype :
     * type, épreuves de la première session (type, pourcentage, durée en minutes, nombre), épreuves de seconde session
     * à part (`rattrapage`, que le prototype ne gère pas encore), options. Null sans épreuve.
     *
     * Données de v2 nettoyées : les lignes de modalités du BUT (codes `td_tp_oral`…) ne valent que pour un BUT, et seules
     * leurs lignes non nulles comptent (les autres sont le modèle vide créé pour chaque fiche) ; une ligne répétée
     * (copies d'une campagne à l'autre) n'est gardée qu'une fois — pour une modalité du BUT, la plus récente ; deux
     * générations d'une même épreuve qui dépassent ensemble 100 % ne gardent que la plus récente.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    private function mccc(string $column, int $id, mixed $type, array $options): ?array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM mccc WHERE '.$column.' = :id'.('fiche_matiere_id' === $column ? ' AND ec_id IS NULL' : '').' ORDER BY numero_session, numero_epreuve, id',
            ['id' => $id],
        );
        $type = self::MCCC_TYPES[strtolower(trim((string) $type))] ?? null;
        $first = [];
        $second = [];
        $but = false;
        $seen = [];
        $groups = [];
        $butRows = [];
        foreach ($rows as $row) {
            $codes = json_decode((string) ($row['type_epreuve'] ?? '[]'), true);
            $codes = \is_array($codes) ? array_values(array_filter($codes, static fn ($c) => null !== $c && '' !== $c)) : [];
            if ([] !== $codes && isset(self::BUT_MODALITES[(string) $codes[0]])) {
                // modalité du BUT : la plus récente par code (lignes triées par id plus bas)
                if ($this->but) {
                    $butRows[(string) $codes[0]] = $row;
                }
                continue;
            }
            $key = implode('|', [$row['numero_session'], $row['numero_epreuve'], $row['type_epreuve'], $row['pourcentage'], $row['libelle'], $row['duree']]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $groups[implode('|', [$row['numero_session'], $row['numero_epreuve'], $row['controle_continu'] ?? '', $row['examen_terminal'] ?? ''])][] = [$row, $codes];
        }
        // deux générations d'une même épreuve (ancienne ligne sans type, nouvelle typée…) : à elles deux plus de 100 %,
        // seule la plus récente compte
        foreach ($groups as $group) {
            $total = array_sum(array_map(static fn (array $g) => (float) ($g[0]['pourcentage'] ?? 0), $group));
            foreach ($total > 100 ? [end($group)] : $group as [$row, $codes]) {
                $this->addEvaluation($row, $codes, $type, false, $first, $second);
            }
        }
        foreach ($butRows as $code => $row) {
            $weight = self::number($row['pourcentage']);
            if (null !== $weight && 0 != $weight) {
                $but = true;
                $this->addEvaluation($row, [$code], $type, true, $first, $second);
            }
        }
        if ([] === $first && [] === $second) {
            return null;
        }

        return self::compact([
            'type' => $type ?? ($but ? 'CC' : null),
            'evaluations' => $first,
            'rattrapage' => $second,
        ] + array_filter($options));
    }

    /**
     * Ajoute une épreuve v2 à la première ou à la seconde session.
     *
     * @param array<string, mixed>       $row
     * @param list<mixed>                $codes types d'épreuve de la ligne
     * @param list<array<string, mixed>> $first
     * @param list<array<string, mixed>> $second
     */
    private function addEvaluation(array $row, array $codes, ?string $type, bool $isBut, array &$first, array &$second): void
    {
        $weight = self::number($row['pourcentage']);
        $label = implode(' / ', array_map(fn ($c) => $this->epreuve($c), $codes))
            ?: trim((string) preg_replace('/\s+\d+$/', '', (string) ($row['libelle'] ?? '')));
        $evaluation = array_filter([
            'type' => '' !== $label ? $label : null,
            'weight' => $weight,
            'duree' => self::minutes($row['duree'] ?? null),
            'nombre' => (int) ($row['nb_epreuves'] ?? 1) > 1 ? (int) $row['nb_epreuves'] : null,
        ], static fn ($v) => null !== $v);
        // CCI : `numero_session` y numérote les épreuves ; ailleurs, la session 2 est la seconde chance
        if ('CCI' !== $type && !$isBut && 2 === (int) $row['numero_session']) {
            $second[] = $evaluation;
        } else {
            $first[] = $evaluation;
        }
    }

    /** Libellé d'un type d'épreuve de v2 : identifiant de `type_epreuve`, ou code de modalité du BUT. */
    private function epreuve(mixed $code): string
    {
        if (\is_string($code) && isset(self::BUT_MODALITES[$code])) {
            return self::BUT_MODALITES[$code];
        }
        $this->epreuves ??= array_map('strval', array_column($this->db->fetchAllAssociative('SELECT id, libelle FROM type_epreuve'), 'libelle', 'id'));

        return $this->epreuves[(int) $code] ?? (string) $code;
    }

    /** Durée v2 (« 03:00:00 ») en minutes. */
    private static function minutes(mixed $duree): ?int
    {
        if (!\is_string($duree) || 1 !== preg_match('/^(\d+):(\d{2})/', $duree, $m)) {
            return null;
        }
        $minutes = (int) $m[1] * 60 + (int) $m[2];

        return $minutes > 0 ? $minutes : null;
    }

    private function person(mixed $userId): ?string
    {
        if (null === $userId) {
            return null;
        }
        $id = (int) $userId;
        if (!isset($this->people[$id])) {
            $u = $this->db->fetchAssociative('SELECT prenom, nom FROM user WHERE id = :id', ['id' => $id]);
            $name = false === $u ? '' : trim(sprintf('%s %s', $u['prenom'] ?? '', $u['nom'] ?? ''));
            $this->people[$id] = '' !== $name ? $name : sprintf('Utilisateur v2 n°%d', $id);
        }

        return $this->people[$id];
    }

    /**
     * Volumes horaires au format du prototype : présentiel / distanciel par CM-TD-TP, travail étudiant.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function hours(array $row): array
    {
        $part = static fn (string $mode): array => array_filter([
            'cm' => self::number($row['volume_cm_'.$mode] ?? null),
            'td' => self::number($row['volume_td_'.$mode] ?? null),
            'tp' => self::number($row['volume_tp_'.$mode] ?? null),
        ], static fn ($v) => null !== $v && $v > 0);

        return array_filter([
            'pres' => $part('presentiel'),
            'dist' => $part('distanciel'),
            'te' => self::number($row['volume_te'] ?? null),
        ], static fn ($v) => [] !== $v && null !== $v && 0 !== $v && 0.0 !== $v);
    }

    /** « UE à choix restreint » → « À choix restreint » (libellés du référentiel nature_elp). */
    private static function nature(mixed $label): ?string
    {
        $text = self::text($label);
        if (null === $text) {
            return null;
        }
        $text = (string) preg_replace('/^(UE|EC)\s+/u', '', $text);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    private static function name(mixed $mention, mixed $sigle): string
    {
        $name = self::text($mention) ?? '';
        $sigle = self::text($sigle);

        return null !== $sigle ? trim($name.' ('.$sigle.')') : $name;
    }

    /** Texte nettoyé ; le HTML des éditeurs de v2 (Trix : `<div><!--block-->…`) devient du texte simple (cf. plainText()). */
    private static function text(mixed $v): ?string
    {
        if (null === $v) {
            return null;
        }
        $v = trim(self::plainText((string) $v));

        return '' === $v ? null : $v;
    }

    /**
     * HTML de v2 → texte : un paragraphe, un bloc ou un saut de ligne deviennent un retour à la ligne, un élément de
     * liste « - … » ; balises, commentaires et entités (&nbsp;…) disparaissent. Un texte sans balise n'est que décodé.
     */
    public static function plainText(string $html): string
    {
        if (1 !== preg_match('/<[a-z!\/][^>]*>/i', $html)) {
            return trim(str_replace("\u{a0}", ' ', html_entity_decode($html, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
        }
        $text = (string) preg_replace('/<!--.*?-->/s', '', $html);
        $text = (string) preg_replace('/<li[^>]*>/i', "\n- ", $text);
        $text = (string) preg_replace('/<br\s*\/?>|<\/(div|p|h[1-6]|ul|ol|blockquote|tr)>/i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{a0}", ' ', $text);
        $lines = array_map(static fn (string $l) => trim((string) preg_replace('/[ \t]+/', ' ', $l)), explode("\n", $text));
        $text = implode("\n", $lines);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private static function number(mixed $v): int|float|null
    {
        if (null === $v || '' === $v || !is_numeric($v)) {
            return null;
        }
        $n = 0 + $v;

        return \is_float($n) && floor($n) === $n ? (int) $n : $n;
    }

    private static function yesNo(mixed $v): ?string
    {
        return null === $v ? null : ((bool) $v ? 'oui' : 'non');
    }

    /** @return list<string> liste JSON de v2 (tableau ou objet indexé) en libellés */
    private static function list(mixed $json): array
    {
        $data = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($data) ? array_values(array_filter(array_map(static fn ($v) => \is_string($v) ? trim($v) : null, $data))) : [];
    }

    /** @return list<string> codes ROME de v2 (`[{"code": "M1805 - …"}]`) */
    private static function romeCodes(mixed $json): array
    {
        $data = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($data) ? array_values(array_filter(array_map(static fn ($v) => \is_array($v) ? self::text($v['code'] ?? null) : null, $data))) : [];
    }

    /**
     * Retire les valeurs vides (null, '', []).
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function compact(array $values): array
    {
        return array_filter($values, static fn ($v) => null !== $v && '' !== $v && [] !== $v);
    }
}
