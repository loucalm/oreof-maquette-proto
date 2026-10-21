<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Diplome\Spec\CanvasEntry;
use App\Diplome\Spec\CanvasSpec;
use App\Diplome\Spec\VersionConfig;
use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Formulaire\CanvasResolver;

/** Modifie le canevas de la version d'une formation (tests). Requiert `$this->em` (ResetsDatabase). */
trait EditsCanvas
{
    private function canvasOf(Formation $formation): CanvasSpec
    {
        return static::getContainer()->get(CanvasResolver::class)->forFormation($formation);
    }

    private function setCanvas(Formation $formation, CanvasSpec $canvas): void
    {
        $version = static::getContainer()->get(TypeDiplomeResolver::class)->versionFor($formation);
        $version->setConfig(VersionConfig::fromArray(['canvas' => $canvas->toArray()] + $version->config()->toArray()));
        $this->em->flush();
    }

    /** @param callable(CanvasEntry): CanvasEntry $change */
    private function changeEntry(Formation $formation, string $fiche, string $key, callable $change): void
    {
        $canvas = $this->canvasOf($formation);
        $this->setCanvas($formation, $canvas->withEntries($fiche, array_map(
            static fn (CanvasEntry $e) => $e->key === $key ? $change($e) : $e,
            $canvas->entries($fiche),
        )));
    }

    private function hideEntry(Formation $formation, string $fiche, string $key): void
    {
        $this->changeEntry($formation, $fiche, $key, static fn (CanvasEntry $e) => new CanvasEntry($e->key, $e->form, $e->builtin, $e->label, true));
    }

    /** Insère l'entrée à la position `$at` (0 = en tête), à la fin si null. */
    private function addEntry(Formation $formation, string $fiche, CanvasEntry $entry, ?int $at = null): void
    {
        $canvas = $this->canvasOf($formation);
        $entries = $canvas->entries($fiche);
        array_splice($entries, $at ?? \count($entries), 0, [$entry]);
        $this->setCanvas($formation, $canvas->withEntries($fiche, $entries));
    }
}
