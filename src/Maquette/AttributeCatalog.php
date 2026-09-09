<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Entity\FieldDef;
use App\Repository\FieldDefRepository;

/**
 * Catalogue des champs de formulaire des nœuds.
 *
 * La liste des champs (onglet, catégorie, type, requis…) est désormais pilotée
 * par les données : entité FieldDef, éditable dans /champs. Ce service la
 * projette dans la forme attendue par les gabarits et le moteur de statut.
 *
 * Un champ n'est saisissable sur un nœud que si la *capacité* du même nom (= sa
 * clé) est active (NodeType::capabilities, surchargée éventuellement par le nœud).
 */
final class AttributeCatalog
{
    public const DOMAIN_PROPS = 'props';
    public const DOMAIN_HOURS = 'volume_horaire';
    public const DOMAIN_MCCC = 'mccc';

    /**
     * Volume horaire : deux lieux (présentiel / distanciel) déclinés en CM/TD/TP,
     * plus un volume de travail étudiant (TE) global. Stockage :
     * `hours = { none?: true, pres?: {cm,td,tp}, dist?: {cm,td,tp}, te?: number }`.
     */
    public const HOUR_MODALITIES = ['cm' => 'CM', 'td' => 'TD', 'tp' => 'TP'];
    public const HOUR_PLACES = ['pres' => 'Présentiel', 'dist' => 'Distanciel'];

    /** Types de MCCC d'un EC (clé => libellé court + intitulé). */
    public const MCCC_TYPES = [
        'CCI' => ['short' => 'CCI', 'label' => 'Contrôle continu intégral'],
        'CC_CT' => ['short' => 'CC + CT', 'label' => 'Contrôle continu & contrôle terminal'],
        'CT' => ['short' => 'CT', 'label' => 'Contrôle terminal'],
        'CC' => ['short' => 'CC', 'label' => 'Contrôle continu'],
    ];

    /** Capacités qui ne sont pas des champs du catalogue mais des drapeaux. */
    public const FLAGS = [
        'mutualisable' => 'Nœud mutualisable / raccrochable',
        'code' => 'Code du nœud',
    ];

    /** Libellés par défaut des onglets connus. */
    private const TAB_LABELS = [
        'props' => 'Propriétés',
        'volume_horaire' => 'Volume horaire',
        'mccc' => 'MCCC',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(private readonly FieldDefRepository $fields)
    {
    }

    /**
     * @return array<string, array{
     *     domain: string, label: string, field: string, category: ?string,
     *     options: array<string, string>, required: bool, help: ?string
     * }>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = [];
        foreach ($this->fields->allOrdered() as $f) {
            $out[$f->getKey()] = [
                'domain' => $f->getTab(),
                'label' => $f->getLabel(),
                'field' => $f->getType(),
                'category' => $f->getCategory(),
                'options' => $f->getOptions(),
                'required' => $f->isRequired(),
                'help' => $f->getHelp(),
            ];
        }

        return $this->cache = $out;
    }

    /**
     * Onglets présents (clé => libellé). « Propriétés » est toujours en tête.
     *
     * @return array<string, string>
     */
    public function domains(): array
    {
        $tabs = ['props' => self::TAB_LABELS['props']];
        foreach ($this->fields->allOrdered() as $f) {
            $tabs[$f->getTab()] ??= self::TAB_LABELS[$f->getTab()]
                ?? ucfirst(str_replace('_', ' ', $f->getTab()));
        }

        return $tabs;
    }

    /** @return list<string> clés de champ connues */
    public function knownKeys(): array
    {
        return array_keys($this->all());
    }

    /**
     * Somme des heures d'un sac d'attributs `hours` (présentiel + distanciel + TE).
     * « EC sans volume horaire » (`none`) compte pour 0.
     *
     * @param array<string, mixed>|null $hours
     */
    public static function sumHours(mixed $hours): float
    {
        if (!\is_array($hours) || !empty($hours['none'])) {
            return 0.0;
        }
        $total = (float) ($hours['te'] ?? 0);
        foreach (self::HOUR_PLACES as $p => $_p) {
            foreach (self::HOUR_MODALITIES as $m => $_m) {
                $total += (float) ($hours[$p][$m] ?? 0);
            }
        }

        return $total;
    }

    /**
     * Le volume horaire est-il renseigné ? Vrai si « sans volume horaire » est
     * coché, ou si au moins une heure est saisie.
     *
     * @param array<string, mixed>|null $hours
     */
    public static function hoursProvided(mixed $hours): bool
    {
        return \is_array($hours) && (!empty($hours['none']) || self::sumHours($hours) > 0);
    }

    /** Défauts pour amorcer les fixtures / restaurer le socle. */
    public const SEED = [
        ['ects', 'ECTS', 'props', null, 'number', [], true, 'ECTS associés à ce nœud.'],
        ['ueType', "Type d'UE", 'props', null, 'choice', [
            'disciplinaire' => 'Disciplinaire', 'transversale' => 'Transversale',
            'langue' => 'Langue', 'projet' => 'Projet / stage', 'libre' => 'Ouverture / libre',
        ], false, null],
        ['ecType', "Type d'EC", 'props', null, 'choice', [
            'cm' => 'Cours magistral', 'td' => 'Travaux dirigés', 'tp' => 'Travaux pratiques',
            'projet' => 'Projet', 'stage' => 'Stage', 'autre' => 'Autre',
        ], false, null],
        ['nature', "Nature de l'élément", 'props', null, 'radio', [
            'obligatoire' => 'Obligatoire', 'choix_libre' => 'À choix libre',
            'choix_restreint' => 'À choix restreint', 'specifique_sante' => 'Spécifique santé facultative',
        ], true, null],
        ['competencies', 'Compétence(s) associée(s)', 'props', null, 'competencies', [], false, 'Sélection de compétences du référentiel (BCC).'],
        ['ficheMatiere', 'Fiche matière obligatoire', 'props', null, 'text', [], true, 'Fiche matière rattachée à l’EC.'],
        ['hours', 'Volume horaire', 'volume_horaire', null, 'hours', [], true, null],
        ['mccc', 'MCCC', 'mccc', null, 'mccc', [], true, null],
    ];
}
