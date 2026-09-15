<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Maquette\Doc\TreeNode;
use App\Repository\MccTypeRepository;
use App\Rules\RuleEvaluator;
use App\Rules\RuleResult;

/**
 * Colle entre l'attribut `mccc` d'un nœud et le moteur de règles générique
 * (`App\Rules\RuleEvaluator`) — même forme que `Completion`/`Numbering` :
 * un petit service qui parcourt un `TreeNode` et calcule quelque chose.
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
    ) {
    }

    /** @return list<RuleResult> */
    public function forNode(TreeNode $node): array
    {
        $mccc = $node->getAttribute('mccc');
        if (!\is_array($mccc)) {
            return [];
        }

        $type = $this->types->findOneByKey((string) ($mccc['type'] ?? ''));
        if (null === $type || [] === $type->getRules()) {
            return [];
        }

        $evaluations = \is_array($mccc['evaluations'] ?? null) ? array_values($mccc['evaluations']) : [];

        return $this->evaluator->evaluateAll($type->getRules(), ['evaluations' => $evaluations]);
    }
}
