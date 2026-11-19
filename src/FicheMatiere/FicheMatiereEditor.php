<?php

declare(strict_types=1);

namespace App\FicheMatiere;

use App\Entity\FicheMatiere;
use App\Formulaire\ResolvedField;
use App\Maquette\AttributeReader;
use App\Validation\TabValidator;
use Doctrine\ORM\EntityManagerInterface;

/** Résultat d'un enregistrement : succès, et impacts d'une imposition en attente de confirmation. */
final readonly class SaveResult
{
    /**
     * @param list<array{formation: string, path: string, key: string}> $impacts
     */
    public function __construct(
        public bool $saved,
        public array $impacts = [],
    ) {
    }
}

/**
 * Écriture d'un onglet de fiche matière (spec §6.3, §7.1) : seuls les champs de l'onglet
 * posté sont réécrits. Les valeurs `hours`/`ects`/`mccc`/`competencies` vont dans `defaults`,
 * les cases d'imposition dans `imposed`, le reste dans `data`.
 *
 * Quand une imposition masquerait la valeur propre d'un EC, l'enregistrement est d'abord
 * refusé et les impacts sont renvoyés ; la valeur propre de l'EC n'est jamais modifiée.
 */
final class FicheMatiereEditor
{
    /** Cases d'imposition : champ du formulaire → clé de FicheMatiere::IMPOSABLE. */
    public const IMPOSING_FIELDS_MAP = [
        'volumesImposes' => 'hours',
        'ectsImposes' => 'ects',
        'mcccImposes' => 'mccc',
    ];

    public function __construct(
        private readonly FicheMatiereForms $forms,
        private readonly FicheMatiereUsage $usage,
        private readonly EcValueResolver $values,
        private readonly AttributeReader $reader,
        private readonly TabValidator $validator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Champs visibles de l'onglet (cases d'imposition comprises). @return list<ResolvedField> */
    public function fields(FicheMatiere $fiche, string $tab): array
    {
        return array_values(array_filter(
            $this->forms->fields($fiche),
            static fn (ResolvedField $f): bool => $tab === $f->tab,
        ));
    }

    /**
     * Erreurs de l'onglet (champs obligatoires et bornes), indexées par clé de champ.
     *
     * @return array<string, list<string>>
     */
    public function issues(FicheMatiere $fiche, array $fields, array $posted): array
    {
        $keys = array_map(static fn (ResolvedField $f): string => $f->getKey(), $fields);
        $result = $this->validator->check(
            $this->forms->formFor($fiche),
            static fn (string $k): mixed => $posted[$k] ?? null,
            $keys,
        );
        $out = [];
        foreach ($result['issues'] as $issue) {
            $out[$issue['key']][] = $issue['label'];
        }

        return $out;
    }

    public function save(FicheMatiere $fiche, string $tab, array $posted, bool $confirmImpact): SaveResult
    {
        $fields = $this->fields($fiche, $tab);
        $keys = array_map(static fn (ResolvedField $f): string => $f->getKey(), $fields);

        // valeurs du formulaire : on ne réécrit que les clés de l'onglet réellement postées
        $data = $fiche->getData();
        $defaults = $fiche->getDefaults();
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $posted) || isset(self::IMPOSING_FIELDS_MAP[$key])) {
                continue;
            }
            $raw = $posted[$key];
            if (\in_array($key, EcValueResolver::KEYS, true)) {
                $defaults[$key] = match ($key) {
                    'hours' => $this->reader->hours(\is_array($raw) ? $raw : []),
                    'mccc' => $this->reader->mccc(\is_array($raw) ? $raw : []),
                    'competencies' => $this->list($raw),
                    default => $this->reader->number($raw),
                };
            } else {
                $data[$key] = \is_string($raw) ? trim($raw) : $raw;
            }
        }
        $fiche->setData($data)->setDefaults($defaults);

        // imposition activée après coup : d'abord calculer les impacts, sans rien enregistrer
        $impacts = [];
        foreach (self::IMPOSING_FIELDS_MAP as $field => $key) {
            if (!\in_array($field, $keys, true) || !\array_key_exists($field, $posted)) {
                continue;
            }
            $on = $this->isOn($posted[$field]);
            if ($on && !$fiche->isImposed($key)) {
                foreach ($this->usage->usages($fiche) as $usage) {
                    if (EcValueResolver::SOURCE_EC === $this->values->requestedSource($usage['ec'], $key)) {
                        $impacts[] = [
                            'formation' => $usage['formation']->getName(),
                            'path' => $usage['path'],
                            'key' => $key,
                        ];
                    }
                }
            }
            $fiche->setImposed($key, $on);
        }

        if ([] !== $impacts && !$confirmImpact) {
            // l'entité est gérée : on annule les changements en mémoire pour ce refus
            $this->em->refresh($fiche);

            return new SaveResult(false, $impacts);
        }

        $this->em->flush();

        return new SaveResult(true);
    }

    /** @param mixed $v */
    private function isOn($v): bool
    {
        return \is_string($v) ? \in_array(strtolower(trim($v)), ['1', 'on', 'oui', 'true', 'yes'], true) : (bool) $v;
    }

    /** @return list<int|string> */
    private function list(mixed $v): array
    {
        return array_values(array_filter(
            \is_array($v) ? $v : (null === $v || '' === $v ? [] : [$v]),
            static fn ($x) => null !== $x && '' !== $x,
        ));
    }
}
