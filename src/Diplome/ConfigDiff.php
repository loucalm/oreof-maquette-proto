<?php

declare(strict_types=1);

namespace App\Diplome;

/** Différences entre deux configurations (formes tableau), par chemin pointé. */
final class ConfigDiff
{
    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $current
     *
     * @return array<string, array{from: string, to: string}> chemin → valeurs lisibles
     */
    public static function between(array $base, array $current): array
    {
        $a = self::flatten($base);
        $b = self::flatten($current);
        $diff = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $path) {
            $from = $a[$path] ?? null;
            $to = $b[$path] ?? null;
            if ($from !== $to) {
                $diff[$path] = ['from' => self::show($from), 'to' => self::show($to)];
            }
        }
        ksort($diff);

        return $diff;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, scalar|null>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            if (\is_array($value) && [] !== $value) {
                $out += self::flatten($value, $path);
            } else {
                $out[$path] = \is_array($value) ? '[]' : $value;
            }
        }

        return $out;
    }

    private static function show(mixed $v): string
    {
        return match (true) {
            null === $v => '—',
            \is_bool($v) => $v ? 'oui' : 'non',
            default => (string) $v,
        };
    }
}
