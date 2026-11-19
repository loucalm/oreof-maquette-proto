<?php

declare(strict_types=1);

namespace App\Maquette;

/**
 * Lecture des valeurs postées d'un formulaire d'attributs (heures, MCCC, nombres) : prend le tableau
 * brut posté, de sorte qu'un autre formulaire (ex. fiche matière) puisse réutiliser la même lecture.
 */
final class AttributeReader
{
    public function number(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public function hours(array $raw): array
    {
        if (!empty($raw['none'])) {
            return ['none' => true];
        }

        $out = [];
        $mutualise = [];
        foreach (array_keys(AttributeCatalog::HOUR_PLACES) as $p) {
            foreach (array_keys(AttributeCatalog::HOUR_MODALITIES) as $m) {
                $val = (float) ($raw[$p][$m] ?? 0);
                if ($val > 0) {
                    $out[$p][$m] = $val;
                }
                if (!empty($raw['mutualise'][$p][$m])) {
                    $mutualise[$p][$m] = true;
                }
            }
        }
        $te = (float) ($raw['te'] ?? 0);
        if ($te > 0) {
            $out['te'] = $te;
        }
        if ([] !== $mutualise) {
            $out['mutualise'] = $mutualise;
        }

        return $out;
    }

    /**
     * MCCC : le type choisi, et si ce type comporte des épreuves à pondérer, la liste des épreuves saisies
     * (`evaluations[][type|weight]`), plus les options `sansNote`, `assiduite`, `quitus` (et son texte,
     * gardé seulement avec `quitus`). Les lignes vides (ni type, ni coefficient) sont ignorées.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public function mccc(array $raw): array
    {
        $type = trim((string) ($raw['type'] ?? ''));
        if ('' === $type) {
            return [];
        }

        $out = ['type' => $type];
        $evaluations = [];
        foreach ((array) ($raw['evaluations'] ?? []) as $row) {
            $etype = \is_array($row) ? trim((string) ($row['type'] ?? '')) : '';
            $weight = \is_array($row) ? (float) str_replace(',', '.', (string) ($row['weight'] ?? '')) : 0.0;
            $dureeRaw = \is_array($row) ? trim((string) ($row['duree'] ?? '')) : '';
            if ('' === $etype && $weight <= 0 && '' === $dureeRaw) {
                continue;
            }
            $entry = ['type' => $etype, 'weight' => $weight];
            if ('' !== $dureeRaw) {
                $entry['duree'] = (float) str_replace(',', '.', $dureeRaw);
            }
            $evaluations[] = $entry;
        }
        if ([] !== $evaluations) {
            $out['evaluations'] = $evaluations;
        }
        foreach (['sansNote', 'assiduite', 'quitus'] as $option) {
            if (!empty($raw[$option])) {
                $out[$option] = true;
            }
        }
        $quitusTexte = trim((string) ($raw['quitusTexte'] ?? ''));
        if (!empty($out['quitus']) && '' !== $quitusTexte) {
            $out['quitusTexte'] = $quitusTexte;
        }

        return $out;
    }
}
