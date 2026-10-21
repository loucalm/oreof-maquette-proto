<?php

declare(strict_types=1);

namespace App\Formulaire\Fiche;

use App\Diplome\Spec\CanvasSpec;
use App\Entity\Formation;
use App\Formulaire\CanvasResolver;
use App\Formulaire\FormResolver;
use App\Formulaire\ResolvedField;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;

/**
 * Passage avec ↔ sans parcours : les données de la fiche parcours vont du parcours unique
 * (rangé sur la formation) au nœud parcours créé, et inversement, entrée par entrée (masquées
 * comprises). Un champ lié à l'entité d'un côté et rangé dans les données de l'autre (ex. le nom
 * du parcours, libellé du nœud) est reporté dans l'entrée dont le formulaire le contient.
 */
final class ParcoursFicheTransfer
{
    public function __construct(
        private readonly CanvasResolver $canvas,
        private readonly FormResolver $forms,
    ) {
    }

    public function toNode(Formation $formation, MaquetteDoc $doc, TreeNode $parcours): void
    {
        $mono = new MonoParcoursFiche($formation, $doc);
        $node = new ParcoursFiche($formation, $doc, $parcours);
        $nodeOnly = array_diff_key($node->bound(), $mono->bound());

        $bound = array_intersect_key($mono->bound(), $node->bound());
        foreach ($this->formEntries($formation) as $key) {
            $data = $mono->data($key);
            foreach (array_keys($nodeOnly) as $field) {
                if (\array_key_exists($field, $data)) {
                    $bound[$field] = $data[$field];
                    unset($data[$field]);
                }
            }
            if ([] !== $data) {
                $node->setData($key, $data);
            }
            unset($doc->parametres[MonoParcoursFiche::PREFIX.$key]);
        }
        $node->writeBound(array_filter($bound, static fn ($v) => null !== $v && '' !== $v));
    }

    public function fromNode(Formation $formation, MaquetteDoc $doc, TreeNode $parcours): void
    {
        $mono = new MonoParcoursFiche($formation, $doc);
        $node = new ParcoursFiche($formation, $doc, $parcours);
        $nodeOnly = array_diff_key($node->bound(), $mono->bound());

        foreach ($this->formEntries($formation) as $key) {
            $data = $node->data($key);
            $fields = $this->fieldKeys($formation, $key);
            foreach ($nodeOnly as $field => $value) {
                if (null !== $value && '' !== $value && \in_array($field, $fields, true)) {
                    $data[$field] = $value;
                    unset($nodeOnly[$field]);
                }
            }
            if ([] !== $data) {
                $mono->setData($key, $data);
            }
        }
        $mono->writeBound(array_filter(array_intersect_key($node->bound(), $mono->bound()), static fn ($v) => null !== $v && '' !== $v));
    }

    /** @return list<string> clés des entrées à formulaire de la fiche parcours, masquées comprises */
    private function formEntries(Formation $formation): array
    {
        $keys = [];
        foreach ($this->canvas->forFormation($formation)->entries(CanvasSpec::PARCOURS) as $entry) {
            if (!$entry->isBuiltin()) {
                $keys[] = $entry->key;
            }
        }

        return $keys;
    }

    /** @return list<string> champs du formulaire de l'entrée, masqués compris */
    private function fieldKeys(Formation $formation, string $key): array
    {
        $entry = $this->canvas->forFormation($formation)->entry(CanvasSpec::PARCOURS, $key);
        $form = null !== $entry ? $this->canvas->form($entry) : null;

        return null === $form ? [] : array_map(static fn (ResolvedField $f) => $f->getKey(), $this->forms->fields($form, true));
    }
}
