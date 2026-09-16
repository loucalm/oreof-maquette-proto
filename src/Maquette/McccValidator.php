<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Maquette\Doc\TreeNode;
use App\Repository\MccTypeRepository;
use App\Repository\StructureTemplateRepository;
use App\Rules\RuleEvaluator;
use App\Rules\RuleResult;

/**
 * Colle entre l'attribut `mccc` d'un nœud et le moteur de règles générique
 * (`App\Rules\RuleEvaluator`) — même forme que `Completion`/`Numbering` :
 * un petit service qui parcourt un `TreeNode` et calcule quelque chose.
 *
 * Le profil de règles appliqué dépend du diplôme de la formation : résolu via
 * le template de ce diplôme (`StructureTemplate::mcccProfiles[typeKey]`).
 * Sans template, ou sans profil mappé pour ce type, aucune règle n'est
 * appliquée (comportement permissif, cohérent avec le reste de l'appli).
 *
 * Calculée à l'affichage uniquement (informatif, non bloquant) : rien dans
 * cette application ne bloque déjà un enregistrement sur un champ requis
 * manquant.
 */
final class McccValidator
{
    public function __construct(
        private readonly MccTypeRepository $types,
        private readonly RuleEvaluator $evaluator,
        private readonly StructureTemplateRepository $templates,
    ) {
    }

    /** @return list<RuleResult> */
    public function forNode(TreeNode $node): array
    {
        $mccc = $node->getAttribute('mccc');
        if (!\is_array($mccc)) {
            return [];
        }

        $typeKey = (string) ($mccc['type'] ?? '');
        $type = $this->types->findOneByKey($typeKey);
        if (null === $type) {
            return [];
        }

        $diplome = $node->getFormation()?->getDiplome();
        $template = null !== $diplome ? $this->templates->findOneByDiplome($diplome) : null;
        $profileKey = $template?->getMcccProfiles()[$typeKey] ?? null;
        $rules = $type->getProfileRules($profileKey);
        if ([] === $rules) {
            return [];
        }

        $evaluations = \is_array($mccc['evaluations'] ?? null) ? array_values($mccc['evaluations']) : [];

        return $this->evaluator->evaluateAll($rules, ['evaluations' => $evaluations]);
    }
}
