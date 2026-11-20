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
            'defaults' => self::compact(['ects' => self::number($f['ects'] ?? null), 'hours' => self::hours($f)]),
        ];
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
