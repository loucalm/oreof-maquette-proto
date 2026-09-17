<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MccType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Admin des types de MCCC — identité, collection d'épreuves pondérées et
 * profils de règles nommés (ex. « Licence », « Master ») : un même type peut
 * se comporter différemment selon le diplôme sans dupliquer sa fiche. Le
 * rattachement d'un profil à un diplôme se décide au niveau du template
 * (`StructureTemplate::mcccProfiles`), pas ici — un type et ses profils
 * restent génériques et réutilisables.
 *
 * Sur le même principe que `ReferentielController` : livré par les fixtures,
 * puis entièrement modifiable, avec suppression protégée pour les types
 * « socle ».
 *
 * Un profil se pilote via 4 clauses togglables (nombre d'épreuves / pondération
 * / durée / rattrapage) plutôt qu'un ajout de règle libre : `clauseState()`
 * dérive l'état des cases depuis les règles déjà stockées, `profileClauses()`
 * reconstruit entièrement `rules[]` à la sauvegarde. `buildRuleNode()` traduit
 * une clause en AST (`App\Rules\RuleEvaluator`) — le moteur de règles lui-même
 * n'a pas changé.
 */
final class MccTypeController extends AbstractController
{
    /** Gabarits de règle proposés à l'admin (clé => libellé). */
    public const RULE_KINDS = [
        'count' => "Nombre d'épreuves",
        'sum' => 'Somme des coefficients',
        'each_weight' => 'Chaque coefficient',
    ];

    public const RULE_OPS = ['==' => 'égal à', '>=' => 'au moins', '<=' => 'au plus'];

    public const SEVERITIES = ['error' => 'Erreur', 'warning' => 'Avertissement', 'info' => 'Information'];

    #[Route('/administration/mcc-types/nouveau', name: 'mcctype_new', methods: ['GET', 'POST'])]
    #[Route('/administration/mcc-types/{id}/editer', name: 'mcctype_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em, ?MccType $type = null): Response
    {
        $isNew = null === $type;

        if ($request->isMethod('POST')) {
            $label = trim((string) $request->request->get('label')) ?: 'Type de MCCC';
            $key = trim((string) $request->request->get('key'))
                ?: (new AsciiSlugger())->slug($label)->upper()->toString();

            if ($isNew) {
                $type = new MccType($key, $label);
                $em->persist($type);
            } else {
                $type->setKey($key)->setLabel($label);
            }

            $hasEvaluations = $request->request->getBoolean('hasEvaluations');
            $evaluationsLabel = trim((string) $request->request->get('evaluationsLabel')) ?: 'Épreuves';

            $type
                ->setShortLabel(trim((string) $request->request->get('shortLabel')) ?: $label)
                ->setDescription($request->request->get('description'))
                ->setSchema($hasEvaluations ? ['collections' => ['evaluations' => ['label' => $evaluationsLabel]]] : [])
                ->setPosition($request->request->getInt('position'));

            $em->flush();
            $this->addFlash('success', 'Type de MCCC enregistré.');

            return $this->redirectToRoute('mcctype_edit', ['id' => $type->getId()]);
        }

        $clauseStates = [];
        if (!$isNew) {
            foreach ($type->getProfiles() as $profile) {
                $clauseStates[$profile['key']] = $this->clauseState($profile);
            }
        }

        return $this->render('mcctype/edit.html.twig', [
            'type' => $type,
            'isNew' => $isNew,
            'ruleOps' => self::RULE_OPS,
            'clauseStates' => $clauseStates,
        ]);
    }

    #[Route('/administration/mcc-types/{id}/supprimer', name: 'mcctype_delete', methods: ['POST'])]
    public function delete(MccType $type, EntityManagerInterface $em): Response
    {
        if ($type->isSystem()) {
            $this->addFlash('warning', sprintf('« %s » est un type du socle : il ne peut pas être supprimé.', $type->getLabel()));

            return $this->redirectToRoute('admin_mcctypes');
        }

        $em->remove($type);
        $em->flush();
        $this->addFlash('info', 'Type de MCCC supprimé.');

        return $this->redirectToRoute('admin_mcctypes');
    }

    #[Route('/administration/mcc-types/{id}/profils', name: 'mcctype_profile_add', methods: ['POST'])]
    public function profileAdd(MccType $type, Request $request, EntityManagerInterface $em): Response
    {
        $label = trim((string) $request->request->get('label')) ?: 'Profil';
        $key = (new AsciiSlugger())->slug($label)->lower()->toString() ?: 'profil-'.bin2hex(random_bytes(3));

        $profiles = $type->getProfiles();
        if (null !== $type->getProfile($key)) {
            $key .= '-'.bin2hex(random_bytes(2));
        }
        $profiles[] = [
            'key' => $key,
            'label' => $label,
            'description' => trim((string) $request->request->get('description')) ?: null,
            'rules' => [],
        ];
        $type->setProfiles($profiles);
        $em->flush();
        $this->addFlash('success', 'Profil ajouté.');

        return $this->redirectToRoute('mcctype_edit', ['id' => $type->getId()]);
    }

    #[Route('/administration/mcc-types/{id}/profils/{profileKey}/dupliquer', name: 'mcctype_profile_duplicate', methods: ['POST'])]
    public function profileDuplicate(MccType $type, string $profileKey, EntityManagerInterface $em): Response
    {
        $source = $type->getProfile($profileKey);
        if (null === $source) {
            $this->addFlash('warning', 'Profil introuvable.');

            return $this->redirectToRoute('mcctype_edit', ['id' => $type->getId()]);
        }

        $newKey = $profileKey.'-copie-'.bin2hex(random_bytes(2));
        $copy = $source;
        $copy['key'] = $newKey;
        $copy['label'] = $source['label'].' (copie)';

        $profiles = $type->getProfiles();
        $profiles[] = $copy;
        $type->setProfiles($profiles);
        $em->flush();
        $this->addFlash('success', 'Profil dupliqué.');

        return $this->redirectToRoute('mcctype_edit', ['id' => $type->getId()]);
    }

    #[Route('/administration/mcc-types/{id}/profils/{profileKey}/supprimer', name: 'mcctype_profile_delete', methods: ['POST'])]
    public function profileDelete(MccType $type, string $profileKey, EntityManagerInterface $em): Response
    {
        $type->setProfiles(array_values(array_filter(
            $type->getProfiles(),
            static fn (array $p): bool => ($p['key'] ?? null) !== $profileKey,
        )));
        $em->flush();
        $this->addFlash('info', 'Profil supprimé.');

        return $this->redirectToRoute('mcctype_edit', ['id' => $type->getId()]);
    }

    /**
     * Reconstruit entièrement les règles d'un profil depuis 4 clauses togglables
     * (nombre d'épreuves / pondération / durée / rattrapage) — remplace l'ajout
     * de règle unitaire par une sauvegarde atomique de tout le profil.
     */
    #[Route('/administration/mcc-types/{id}/profils/{profileKey}/clauses', name: 'mcctype_profile_clauses', methods: ['POST'])]
    public function profileClauses(MccType $type, string $profileKey, Request $request, EntityManagerInterface $em): Response
    {
        $num = static fn (string $key, string $default = '0') => (float) str_replace(',', '.', (string) $request->request->get($key, $default));
        $op = static function (string $key, string $default) use ($request): string {
            $value = (string) $request->request->get($key, $default);

            return \array_key_exists($value, self::RULE_OPS) ? $value : $default;
        };

        $profiles = $type->getProfiles();
        foreach ($profiles as &$profile) {
            if (($profile['key'] ?? null) !== $profileKey) {
                continue;
            }

            $rules = [];

            if ($request->request->getBoolean('count_on')) {
                $rules[] = $this->rule('count', $op('count_op', '>='), $num('count_value'), 'error');
            }

            if ($request->request->getBoolean('ponderation_on')) {
                $rules[] = $this->rule('sum', '==', 100.0, 'error');
                if ($request->request->getBoolean('ponderation_egale')) {
                    $rules[] = $this->rule('each_weight', '<=', $num('ponderation_max'), 'error');
                }
            }

            if ($request->request->getBoolean('duree_on')) {
                $rules[] = [
                    'key' => 'duree_'.bin2hex(random_bytes(3)),
                    'label' => 'Chaque épreuve a une durée renseignée',
                    'severity' => 'error',
                    'node' => ['kind' => 'each', 'collection' => 'evaluations', 'field' => 'duree', 'op' => '>', 'value' => ['kind' => 'literal', 'value' => 0]],
                ];
            }

            $profile['rules'] = $rules;
            $profile['secondChance'] = $request->request->getBoolean('rattrapage_on');
            break;
        }
        unset($profile);
        $type->setProfiles($profiles);
        $em->flush();
        $this->addFlash('success', 'Profil mis à jour.');

        return $this->redirectToRoute('mcctype_edit', ['id' => $type->getId()]);
    }

    /**
     * État des 4 clauses, dérivé des règles déjà stockées (pour pré-remplir les cases à l'édition).
     *
     * @param array{rules?: list<array<string, mixed>>, secondChance?: bool} $profile
     *
     * @return array{count: array{on: bool, op: string, value: float}, ponderation: array{on: bool, egale: bool, max: float}, duree: array{on: bool}, rattrapage: bool}
     */
    private function clauseState(array $profile): array
    {
        $state = [
            'count' => ['on' => false, 'op' => '>=', 'value' => 0.0],
            'ponderation' => ['on' => false, 'egale' => false, 'max' => 0.0],
            'duree' => ['on' => false],
            'rattrapage' => (bool) ($profile['secondChance'] ?? false),
        ];

        foreach ($profile['rules'] ?? [] as $r) {
            $node = (array) ($r['node'] ?? []);
            $left = (array) ($node['left'] ?? []);
            if ('comparison' === ($node['kind'] ?? null) && 'COUNT' === ($left['fn'] ?? null)) {
                $state['count'] = ['on' => true, 'op' => (string) $node['op'], 'value' => (float) ($node['right']['value'] ?? 0)];
            } elseif ('comparison' === ($node['kind'] ?? null) && 'SUM' === ($left['fn'] ?? null)) {
                $state['ponderation']['on'] = true;
            } elseif ('each' === ($node['kind'] ?? null) && 'weight' === ($node['field'] ?? null)) {
                $state['ponderation']['egale'] = true;
                $state['ponderation']['max'] = (float) ($node['value']['value'] ?? 0);
            } elseif ('each' === ($node['kind'] ?? null) && 'duree' === ($node['field'] ?? null)) {
                $state['duree']['on'] = true;
            }
        }

        return $state;
    }

    private function rule(string $kind, string $op, float $value, string $severity): array
    {
        return [
            'key' => $kind.'_'.bin2hex(random_bytes(3)),
            'label' => $this->defaultRuleLabel($kind, $op, $value),
            'severity' => $severity,
            'node' => $this->buildRuleNode($kind, $op, $value),
        ];
    }

    /** @return array<string, mixed> */
    private function buildRuleNode(string $kind, string $op, float $value): array
    {
        $literal = ['kind' => 'literal', 'value' => $value];

        return match ($kind) {
            'count' => [
                'kind' => 'comparison', 'op' => $op,
                'left' => ['kind' => 'aggregate', 'fn' => 'COUNT', 'collection' => 'evaluations'],
                'right' => $literal,
            ],
            'sum' => [
                'kind' => 'comparison', 'op' => $op,
                'left' => ['kind' => 'aggregate', 'fn' => 'SUM', 'collection' => 'evaluations', 'field' => 'weight'],
                'right' => $literal,
            ],
            'each_weight' => [
                'kind' => 'each', 'collection' => 'evaluations', 'field' => 'weight', 'op' => $op, 'value' => $literal,
            ],
            default => ['kind' => 'comparison', 'op' => '==', 'left' => $literal, 'right' => $literal],
        };
    }

    private function defaultRuleLabel(string $kind, string $op, float $value): string
    {
        $opLabel = self::RULE_OPS[$op] ?? $op;
        $valueStr = rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');

        return match ($kind) {
            'count' => "Nombre d'épreuves {$opLabel} {$valueStr}",
            'sum' => "Somme des coefficients {$opLabel} {$valueStr} %",
            'each_weight' => "Chaque coefficient {$opLabel} {$valueStr} %",
            default => 'Règle',
        };
    }
}
