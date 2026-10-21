<?php

declare(strict_types=1);

namespace App\Formulaire;

use App\Entity\Formation;
use App\Entity\NodeType;
use App\FieldType\FieldTypeRegistry;
use App\Formulaire\Fiche\FicheTarget;
use App\Validation\TabValidator;

/**
 * Champs, statut et anomalies d'une entrée à formulaire d'une fiche : le formulaire désigné
 * par le canevas, ses champs résolus (FormResolver) et les valeurs de la fiche. Statut :
 * « empty » si rien de ce qui est attendu n'est fait, « ok » si tout l'est, « error » si une
 * valeur saisie est invalide (bornes), « incomplete » sinon. Attendu = champs obligatoires
 * résolus + règles d'onglet déclenchées.
 */
final class EntryCompletion
{
    public function __construct(
        private readonly CanvasResolver $canvas,
        private readonly FormResolver $forms,
        private readonly TabValidator $validator,
        private readonly FieldTypeRegistry $fieldTypes,
    ) {
    }

    /** Formulaire d'une entrée visible de la fiche, null si l'entrée n'existe pas, est masquée ou est un écran intégré. */
    public function form(Formation $formation, string $fiche, string $entryKey): ?NodeType
    {
        $entry = $this->canvas->formEntry($formation, $fiche, $entryKey);

        return null === $entry ? null : $this->canvas->form($entry);
    }

    /** @return list<ResolvedField> */
    public function formFields(Formation $formation, string $fiche, string $entryKey): array
    {
        $form = $this->form($formation, $fiche, $entryKey);

        return null === $form ? [] : $this->forms->fields($form);
    }

    /** @return list<ResolvedField> */
    public function fields(FicheTarget $fiche, string $entryKey): array
    {
        return $this->formFields($fiche->formation(), $fiche->fiche(), $entryKey);
    }

    /** @return list<ResolvedField> champs qui portent une valeur (séparateurs exclus) */
    public function inputFields(FicheTarget $fiche, string $entryKey): array
    {
        return array_values(array_filter($this->fields($fiche, $entryKey), fn (ResolvedField $f) => $this->fieldTypes->get($f->getType())->isInput()));
    }

    public function status(FicheTarget $fiche, string $entryKey): string
    {
        $form = $this->form($fiche->formation(), $fiche->fiche(), $entryKey);
        if (null === $form) {
            return 'empty';
        }
        $fields = $this->forms->fields($form);
        $value = $this->valueReader($fiche, $entryKey);
        $required = array_values(array_filter($fields, static fn (ResolvedField $f) => $f->required));
        $check = $this->check($form, $fields, $value);

        $expected = \count($required) + $check['units'];
        $done = $check['units'] - \count($check['issues']);
        foreach ($required as $f) {
            $v = $value($f->getKey());
            $done += null === $v || '' === $v || [] === $v ? 0 : 1;
        }

        return match (true) {
            [] !== array_filter($check['issues'], static fn (array $i) => 'invalid' === $i['kind']) => 'error',
            $done === $expected => 'ok',
            0 === $done => 'empty',
            default => 'incomplete',
        };
    }

    /** @return array<string, list<string>> messages par clé de champ */
    public function issues(FicheTarget $fiche, string $entryKey): array
    {
        $form = $this->form($fiche->formation(), $fiche->fiche(), $entryKey);
        if (null === $form) {
            return [];
        }
        $out = [];
        foreach ($this->check($form, $this->forms->fields($form), $this->valueReader($fiche, $entryKey))['issues'] as $issue) {
            $out[$issue['key']][] = $issue['label'];
        }

        return $out;
    }

    /** @return callable(string): mixed */
    private function valueReader(FicheTarget $fiche, string $entryKey): callable
    {
        $data = $fiche->data($entryKey);

        return static fn (string $k): mixed => $fiche->value($k, $data);
    }

    /**
     * @param list<ResolvedField>     $fields
     * @param callable(string): mixed $value
     *
     * @return array{units: int, issues: list<array{key: string, label: string, tab: string, kind: string}>}
     */
    private function check(NodeType $form, array $fields, callable $value): array
    {
        return $this->validator->check(
            $form,
            $value,
            array_map(static fn (ResolvedField $f) => $f->getKey(), $fields),
            array_values(array_map(static fn (ResolvedField $f) => $f->getKey(), array_filter($fields, static fn (ResolvedField $f) => $f->required))),
        );
    }
}
