<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Diplome\VersionMccc;
use App\Maquette\Doc\TreeNode;
use App\Repository\MccTypeRepository;
use App\Rules\RuleEvaluator;
use App\Rules\RuleResult;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Colle entre l'attribut `mccc` d'un nœud et le moteur de règles générique
 * (`App\Rules\RuleEvaluator`) — même forme que `Completion`/`Numbering` :
 * un petit service qui parcourt un `TreeNode` et calcule quelque chose.
 *
 * Le profil de règles appliqué vient de la version du type de diplôme de la
 * formation (`VersionConfig::mccc[type].profile`, cf. VersionMccc) ; sans
 * version, aucun profil.
 * Sans profil, aucune règle n'est appliquée (comportement permissif, cohérent
 * avec le reste de l'appli).
 *
 * Calculée à l'affichage uniquement (informatif, non bloquant) : rien dans
 * cette application ne bloque déjà un enregistrement sur un champ requis
 * manquant.
 */
final class McccValidator implements ResetInterface
{
    /** @var array<string, \App\Entity\MccType> mémo par requête (évite 2 requêtes par nœud affiché) */
    private array $typeCache = [];

    public function __construct(
        private readonly MccTypeRepository $types,
        private readonly RuleEvaluator $evaluator,
        private readonly VersionMccc $versionMccc,
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
        // seuls les résultats trouvés sont mémorisés : une absence peut cesser de l'être (fixtures, admin)
        $type = $this->typeCache[$typeKey] ??= $this->types->findOneByKey($typeKey);
        if (null === $type) {
            return [];
        }

        // profil : celui que la version du type de diplôme associe à ce type de MCCC ; sans version, aucun
        $profileKey = $this->versionMccc->profileFor($node, $typeKey);
        $rules = $type->getProfileRules($profileKey);
        if ([] === $rules) {
            return [];
        }

        $evaluations = \is_array($mccc['evaluations'] ?? null) ? array_values($mccc['evaluations']) : [];

        return $this->evaluator->evaluateAll($rules, ['evaluations' => $evaluations]);
    }

    /**
     * Les MCCC saisies violent-elles une règle de gravité « error » ? Une MCCC
     * non conforme ne compte pas comme remplie (statut, onglet, progression).
     */
    public function hasError(TreeNode $node): bool
    {
        foreach ($this->forNode($node) as $r) {
            if (!$r->valid && 'error' === $r->severity) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> messages des règles en erreur */
    public function errorMessages(TreeNode $node): array
    {
        $out = [];
        foreach ($this->forNode($node) as $r) {
            if (!$r->valid && 'error' === $r->severity) {
                $out[] = $r->message;
            }
        }

        return $out;
    }

    /** Vide le mémo entre deux requêtes (service « kernel.reset »). */
    public function reset(): void
    {
        $this->typeCache = [];
    }
}
