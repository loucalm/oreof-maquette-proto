<?php

declare(strict_types=1);

namespace App\Import\V2;

/**
 * Extrait v2 enregistré en JSON : sert d'exemple rejouable par qui n'a pas accès à la base v2
 * (cf. docs/import-v2.md, `data/import-v2/exemple.json`). À l'enregistrement, les noms des personnes
 * sont remplacés par « Responsable 1 », « Responsable 2 »…, les coordonnées (secrétariat, contacts) retirées, et les
 * e-mails et téléphones des textes libres masqués : un extrait versionné ne contient pas de donnée personnelle.
 */
final class V2ExtractFile
{
    /** Champs qui désignent une personne (responsables, référent de fiche). */
    private const PERSON_FIELDS = ['respMention', 'coRespMention', 'respParcours', 'coRespParcours', 'referent'];

    /** Champs de coordonnées (secrétariat, contacts) : retirés de l'extrait. */
    private const CONTACT_FIELDS = ['secretariat', 'contacts', 'contactsParcours'];

    /** @param array<string, mixed> $extract */
    public static function save(array $extract, string $path): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Dossier « %s » impossible à créer.', $dir));
        }
        $json = json_encode(self::anonymize($extract), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        file_put_contents($path, $json."\n");
    }

    /** @return array<string, mixed> */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException(sprintf('Fichier d’extrait « %s » introuvable.', $path));
        }
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || !isset($data['formations'])) {
            throw new \InvalidArgumentException(sprintf('« %s » n’est pas un extrait ORéOF v2 (clé « formations » absente).', $path));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $extract
     *
     * @return array<string, mixed>
     */
    public static function anonymize(array $extract): array
    {
        $aliases = [];
        foreach ($extract['referentiels']['responsables'] ?? [] as $i => $name) {
            $aliases[(string) $name] = sprintf('Responsable %d', $i + 1);
        }
        if (isset($extract['referentiels']['responsables'])) {
            $extract['referentiels']['responsables'] = array_values($aliases);
        }
        $walk = static function (array $data) use (&$walk, $aliases): array {
            foreach ($data as $key => $value) {
                if (\in_array($key, self::CONTACT_FIELDS, true)) {
                    unset($data[$key]);
                } elseif (\is_array($value)) {
                    $data[$key] = $walk($value);
                } elseif (\is_string($value) && \in_array($key, self::PERSON_FIELDS, true)) {
                    $data[$key] = $aliases[$value] ?? 'Responsable';
                } elseif (\is_string($value)) {
                    // e-mails et téléphones glissés dans les textes libres
                    $value = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', 'contact@exemple.fr', $value);
                    $data[$key] = (string) preg_replace('/(?:\+33\s?|0)[1-9](?:[\s.-]?\d{2}){4}/', '03 00 00 00 00', $value);
                }
            }

            return $data;
        };
        $extract['formations'] = $walk($extract['formations'] ?? []);
        $extract['anonymized'] = true;

        return $extract;
    }
}
