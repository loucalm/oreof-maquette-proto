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
 * Sur le même principe que `ReferentielController`/`FieldController` : livré
 * par les fixtures, puis entièrement modifiable, avec suppression protégée
 * pour les types « socle ».
 *
 * Les règles d'un profil sont ajoutées via un choix parmi 3 gabarits fixes
 * (nombre d'épreuves / somme des coefficients / chaque coefficient) — pas de
 * canevas AST, pas de JSON à taper à la main. `buildRuleNode()` traduit ce
 * choix en AST (`App\Rules\RuleEvaluator`).
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

        return $this->render('mcctype/edit.html.twig', [
            'type' => $type,
            'isNew' => $isNew,
            'ruleKinds' => self::RULE_KINDS,
            'ruleOps' => self::RULE_OPS,
            'severities' => self::SEVERITIES,
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

    #[Route('/administration/mcc-types/{id}/profils/{profileKey}/regles', name: 'mcctype_rule_add', methods: ['POST'])]
    public function ruleAdd(MccType $type, string $profileKey, Request $request, EntityManagerInterface $em): Response
    {
        $kind = (string) $request->request->get('kind', '');
        $op = (string) $request->request->get('op', '==');
        $value = (float) str_replace(',', '.', (string) $request->request->get('value', '0'));
        $severity = \in_array($request->request->get('severity'), ['info', 'warning', 'error'], true)
            ? $request->request->get('severity') : 'error';
        $customLabel = trim((string) $request->request->get('label'));

        if (!\array_key_exists($kind, self::RULE_KINDS) || !\array_key_exists($op, self::RULE_OPS)) {
            $this->addFlash('warning', 'Règle invalide.');

            return $this->redirectToRoute('mcctype_edit', ['id' => $type->getId()]);
        }

        $profiles = $type->getProfiles();
        foreach ($profiles as &$profile) {
            if (($profile['key'] ?? null) === $profileKey) {
                $profile['rules'][] = [
                    'key' => $kind.'_'.bin2hex(random_bytes(3)),
                    'label' => $customLabel !== '' ? $customLabel : $this->defaultRuleLabel($kind, $op, $value),
                    'severity' => $severity,
                    'node' => $this->buildRuleNode($kind, $op, $value),
                ];
                break;
            }
        }
        unset($profile);
        $type->setProfiles($profiles);
        $em->flush();
        $this->addFlash('success', 'Règle ajoutée.');

        return $this->redirectToRoute('mcctype_edit', ['id' => $type->getId()]);
    }

    #[Route('/administration/mcc-types/{id}/profils/{profileKey}/regles/{key}/supprimer', name: 'mcctype_rule_delete', methods: ['POST'])]
    public function ruleDelete(MccType $type, string $profileKey, string $key, EntityManagerInterface $em): Response
    {
        $profiles = $type->getProfiles();
        foreach ($profiles as &$profile) {
            if (($profile['key'] ?? null) === $profileKey) {
                $profile['rules'] = array_values(array_filter(
                    $profile['rules'] ?? [],
                    static fn (array $r): bool => ($r['key'] ?? null) !== $key,
                ));
                break;
            }
        }
        unset($profile);
        $type->setProfiles($profiles);
        $em->flush();
        $this->addFlash('info', 'Règle supprimée.');

        return $this->redirectToRoute('mcctype_edit', ['id' => $type->getId()]);
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
