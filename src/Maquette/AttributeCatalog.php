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

    /** Sous-champs du volume horaire : modalité x lieu (structurel). */
    public const HOUR_MODALITIES = ['cm' => 'CM', 'td' => 'TD', 'tp' => 'TP'];
    public const HOUR_PLACES = ['pres' => 'Présentiel', 'dist' => 'Distanciel', 'travail' => 'Travail étudiant'];

    /** Capacités qui ne sont pas des champs de saisie mais des drapeaux. */
    public const FLAGS = [
        'mutualisable' => 'Nœud mutualisable / raccrochable',
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
     * Somme des heures d'un sac d'attributs `hours`.
     *
     * @param array<string, mixed>|null $hours
     */
    public static function sumHours(mixed $hours): float
    {
        if (!\is_array($hours)) {
            return 0.0;
        }
        $total = 0.0;
        foreach (self::HOUR_MODALITIES as $m => $_) {
            foreach (self::HOUR_PLACES as $p => $_p) {
                $total += (float) ($hours[$m][$p] ?? 0);
            }
        }

        return $total;
    }

    /** Défauts pour amorcer les fixtures / restaurer le socle. */
    public const SEED = [
        ['ects', 'ECTS', 'props', 'Crédits', 'number', [], true, 'Crédits ECTS portés par ce nœud.'],
        ['ueType', "Type d'UE", 'props', 'Nature', 'choice', [
            'disciplinaire' => 'Disciplinaire', 'transversale' => 'Transversale',
            'langue' => 'Langue', 'projet' => 'Projet / stage', 'libre' => 'Ouverture / libre',
        ], false, null],
        ['nature', "Nature de l'élément", 'props', 'Nature', 'choice', [
            'obligatoire' => 'Obligatoire', 'choix_libre' => 'À choix libre',
            'choix_restreint' => 'À choix restreint', 'specifique_sante' => 'Spécifique santé facultative',
        ], true, null],
        ['competencies', 'Compétences associées', 'props', 'Compétences', 'competencies', [], false, 'Sélection de compétences du référentiel (BCC).'],
        ['ficheMatiere', 'Fiche matière', 'props', 'Compétences', 'text', [], false, 'Intitulé de la fiche matière obligatoire rattachée.'],
        ['hours', 'Volume horaire', 'volume_horaire', null, 'hours', [], true, null],
        ['mccc', 'MCCC', 'mccc', null, 'mccc', [], true, null],
    ];
}
