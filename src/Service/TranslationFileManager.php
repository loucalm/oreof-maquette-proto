<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Lit et réécrit les fichiers YAML de `translations/`, à plat (clé pointée
 * `a.b.c` = valeur), pour l'édition admin des textes de l'appli sans
 * intervention dans le code. Chaque écriture garde une sauvegarde horodatée
 * et vide le cache de traductions compilé pour que le changement soit visible
 * immédiatement.
 */
final class TranslationFileManager
{
    private readonly string $path;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $cacheDir,
    ) {
        $this->path = $projectDir.'/translations';
    }

    /** @return SplFileInfo[] */
    public function listFiles(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $finder = (new Finder())->files()->in($this->path)->name('*.yaml')->sortByName();

        return iterator_to_array($finder->getIterator(), false);
    }

    /** @return array<string,string> clé pointée => valeur */
    public function read(string $filename): array
    {
        $full = $this->path.'/'.$this->sanitize($filename);
        if (!is_file($full)) {
            throw new \RuntimeException(\sprintf('Fichier de traduction "%s" introuvable.', $filename));
        }

        $data = Yaml::parseFile($full);

        return $this->flatten($data ?? []);
    }

    /** @param array<string,mixed> $array */
    private function flatten(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (\is_array($value)) {
                $result += $this->flatten($value, $newKey);
            } else {
                $result[$newKey] = (string) $value;
            }
        }

        return $result;
    }

    public function writeSingle(string $filename, string $key, string $value): void
    {
        $data = $this->read($filename);
        $data[$key] = $value;
        $this->write($filename, $data);
    }

    public function deleteKey(string $filename, string $key): void
    {
        $data = $this->read($filename);
        unset($data[$key]);
        $this->write($filename, $data);
    }

    /** @param array<string,string> $translations */
    public function write(string $filename, array $translations): void
    {
        $full = $this->path.'/'.$this->sanitize($filename);
        if (is_file($full)) {
            copy($full, $full.'.bak_'.date('Ymd_His'));
        }
        ksort($translations);
        file_put_contents($full, Yaml::dump($translations, 2, 4));
        $this->invalidateTranslationCache();
    }

    private function sanitize(string $filename): string
    {
        $filename = basename($filename);
        if (!str_ends_with($filename, '.yaml') || str_contains($filename, '..')) {
            throw new \RuntimeException(\sprintf('Nom de fichier de traduction invalide : "%s".', $filename));
        }

        return $filename;
    }

    private function invalidateTranslationCache(): void
    {
        $translationCache = $this->cacheDir.'/translations';
        if (!is_dir($translationCache)) {
            return;
        }
        foreach (glob($translationCache.'/*') ?: [] as $file) {
            @unlink($file);
        }
    }
}
