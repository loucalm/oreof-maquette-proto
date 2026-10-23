<?php

declare(strict_types=1);

namespace App\Offre;

use App\Repository\FormationRepository;

/**
 * Tableau de la gestion de l'offre : formations regroupées par composante, filtrées
 * (recherche, composante, type de diplôme, plateforme, statut ou anomalie), et indicateurs.
 */
final class OffreBoard
{
    public function __construct(
        private readonly FormationRepository $formations,
        private readonly OffreFormation $offre,
    ) {
    }

    /**
     * @param array{q?: string, composante?: string, type?: string, plateforme?: string, statut?: string} $filters
     *
     * @return array{groups: list<array<string, mixed>>, kpi: array<string, mixed>, composantes: list<string>, types: array<string, string>, plateformes: array<string, string>}
     */
    public function build(array $filters): array
    {
        $rows = array_map(fn ($f) => $this->offre->describe($f), $this->formations->findAllRecent());

        // listes des filtres : d'après toutes les formations, avant filtrage
        $composantes = array_values(array_unique(array_filter(array_column($rows, 'composante'))));
        $types = [];
        $plateformes = [];
        foreach ($rows as $row) {
            if (null !== $row['formation']->getTypeDiplome()) {
                $types[$row['formation']->getTypeDiplome()->getKey()] = $row['typeLabel'];
            }
            foreach ($row['plateformes'] as $p) {
                $plateformes[$p['key']] = $p['label'];
            }
        }
        $collator = new \Collator('fr_FR');
        usort($composantes, $collator->compare(...));
        uasort($types, $collator->compare(...));
        uasort($plateformes, $collator->compare(...));

        $rows = array_values(array_filter($rows, fn (array $row) => $this->matches($row, $filters)));

        $groups = [];
        foreach ($rows as $row) {
            $key = '' !== $row['composante'] ? $row['composante'] : 'Sans composante';
            $groups[$key] ??= ['libelle' => $key, 'formations' => [], 'nbParcours' => 0, 'nbOuverts' => 0, 'capacite' => 0, 'nbPv' => 0, 'nbNote' => 0];
            $groups[$key]['formations'][] = $row;
            $groups[$key]['nbParcours'] += \count($row['parcours']);
            $groups[$key]['nbOuverts'] += \count(array_filter($row['parcours'], static fn (array $p) => $p['ouvert']));
            $groups[$key]['capacite'] += $row['capacite'];
            $groups[$key]['nbPv'] += (int) $row['pv'];
            $groups[$key]['nbNote'] += (int) $row['note'];
        }
        uksort($groups, $collator->compare(...));
        foreach ($groups as &$group) {
            usort($group['formations'], static fn (array $a, array $b) => $collator->compare($a['formation']->getName(), $b['formation']->getName()));
        }
        unset($group);

        return [
            'groups' => array_values($groups),
            'kpi' => $this->kpi($rows),
            'composantes' => $composantes,
            'types' => $types,
            'plateformes' => $plateformes,
        ];
    }

    /** @param array<string, mixed> $row */
    private function matches(array $row, array $filters): bool
    {
        $q = trim((string) ($filters['q'] ?? ''));
        if ('' !== $q) {
            $labels = [$row['formation']->getName(), ...array_column($row['parcours'], 'label')];
            if ([] === array_filter($labels, static fn (string $l) => false !== mb_stripos($l, $q))) {
                return false;
            }
        }
        if ('' !== ($filters['composante'] ?? '') && $row['composante'] !== $filters['composante']) {
            return false;
        }
        if ('' !== ($filters['type'] ?? '') && $row['formation']->getTypeDiplome()?->getKey() !== $filters['type']) {
            return false;
        }
        if ('' !== ($filters['plateforme'] ?? '') && !\in_array($filters['plateforme'], array_column($row['plateformes'], 'key'), true)) {
            return false;
        }
        $statut = (string) ($filters['statut'] ?? '');

        return match ($statut) {
            '' => true,
            'anomalie' => [] !== $row['anomalies'],
            'consolidation' => 'consolidation' === $row['formation']->getPhase()->value,
            default => $row['statut'] === $statut,
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function kpi(array $rows): array
    {
        $kpi = ['formations' => \count($rows), 'parcours' => 0, 'ouverts' => 0, 'nonOuverts' => 0, 'nonOuverture' => 0, 'fermeture' => 0,
            'capacite' => 0, 'aConfirmer' => 0, 'anomalies' => []];
        foreach ($rows as $row) {
            $kpi['capacite'] += $row['capacite'];
            foreach ($row['parcours'] as $p) {
                ++$kpi['parcours'];
                if ($p['ouvert']) {
                    ++$kpi['ouverts'];
                    // offre pas encore validée par la CFVU
                    $kpi['aConfirmer'] += \in_array($row['statut'], ['attente_pv', 'publie'], true) ? 0 : 1;
                } else {
                    ++$kpi['nonOuverts'];
                    ++$kpi['fermeture' === $p['ouverture'] ? 'fermeture' : 'nonOuverture'];
                }
            }
            foreach ($row['anomalies'] as $a) {
                $kpi['anomalies'][] = $row['formation']->getName().' — '.$a;
            }
        }

        return $kpi;
    }
}
