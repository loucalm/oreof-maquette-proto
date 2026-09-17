<?php

declare(strict_types=1);

namespace App\Maquette;

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
    /**
     * Volume horaire : deux lieux (présentiel / distanciel) déclinés en CM/TD/TP,
     * plus un volume de travail étudiant (TE) global. Stockage :
     * `hours = { none?: true, pres?: {cm,td,tp}, dist?: {cm,td,tp}, te?: number }`.
     */
    public const HOUR_MODALITIES = ['cm' => 'CM', 'td' => 'TD', 'tp' => 'TP'];
    public const HOUR_PLACES = ['pres' => 'Présentiel', 'dist' => 'Distanciel'];

    /** Capacités qui ne sont pas des champs du catalogue mais des drapeaux. */
    public const FLAGS = [
        'mutualisable' => 'ELP mutualisable / raccrochable',
        'code' => 'Code de l’ELP',
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
     *     options: array<string, string>, referentielKey: ?string, allowExtra: bool,
     *     quickAdd: bool, required: bool, help: ?string, min: ?float, max: ?float
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
                'referentielKey' => $f->getReferentielKey(),
                'allowExtra' => $f->isAllowExtra(),
                'quickAdd' => $f->isQuickAdd(),
                'required' => $f->isRequired(),
                'help' => $f->getHelp(),
                'min' => $f->getMin(),
                'max' => $f->getMax(),
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

    /**
     * Défauts pour amorcer les fixtures / restaurer le socle.
     *
     * [key, label, tab, category, type, options, required, help, referentielKey]
     */
    public const SEED = [
        ['ects', 'ECTS', 'props', null, 'number', [], true, 'ECTS associés à cet ELP.', null],
        ['choiceCount', 'Nombre à choisir', 'props', null, 'number', [], true, 'Combien de ces éléments doivent être choisis parmi les options de ce bloc (ex. 1 parmi 3).', null],
        ['ueType', "Type d'UE", 'props', null, 'choice', [], false, null, 'types_ue'],
        ['ecType', "Type d'EC", 'props', null, 'choice', [], false, null, 'types_ec'],
        ['nature', "Nature de l'élément", 'props', null, 'radio', [], true, null, 'nature_elp'],
        ['competencies', 'Compétence(s) associée(s)', 'props', null, 'competencies', [], false, 'Sélection de compétences du référentiel (BCC).', null],
        ['ficheMatiere', 'Fiche matière obligatoire', 'props', null, 'text', [], true, 'Fiche matière rattachée à l’EC.', 'fiches_matiere'],
        ['hours', 'Volume horaire', 'volume_horaire', null, 'hours', [], true, null, null],
        ['mccc', 'MCCC', 'mccc', null, 'mccc', [], true, null, null],
    ];
}
