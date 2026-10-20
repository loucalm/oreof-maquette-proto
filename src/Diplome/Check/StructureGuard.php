<?php

declare(strict_types=1);

namespace App\Diplome\Check;

use App\Diplome\TypeDiplomeResolver;
use App\Entity\Formation;
use App\Maquette\Doc\MaquetteDoc;
use App\Repository\DerogationRequestRepository;

/**
 * Refuse une modification de maquette qui créerait un écart bloquant
 * (nouveau, ou aggravé) non couvert par une dérogation accordée. Les écarts
 * déjà présents avant la modification ne bloquent pas les modifications sans rapport.
 */
final class StructureGuard
{
    public function __construct(
        private readonly StructureChecker $checker,
        private readonly TypeDiplomeResolver $resolver,
        private readonly DerogationRequestRepository $derogations,
    ) {
    }

    /** @throws StructureViolationException */
    public function assertAllowed(Formation $formation, ?MaquetteDoc $before, MaquetteDoc $after): void
    {
        $config = $this->resolver->configFor($formation);
        if (null === $config) {
            return;
        }

        $previous = [];
        if (null !== $before) {
            foreach ($this->checker->check($before, $config) as $v) {
                if ($v->blocking) {
                    $previous[$v->ruleKey."\0".$v->scopeNid] = $v;
                }
            }
        }

        $refused = [];
        foreach ($this->checker->check($after, $config) as $v) {
            if (!$v->blocking) {
                continue;
            }
                $old = $previous[$v->ruleKey."\0".$v->scopeNid] ?? null;
            if (null !== $old && !self::isWorse($old, $v)) {
                continue;
            }
            if (null !== $this->derogations->grantedCovering($formation, $v->ruleKey, $v->scopeNid, $v->actual)) {
                continue;
            }
            $refused[] = $v;
        }

        if ([] !== $refused) {
            throw new StructureViolationException($formation, $refused);
        }
    }

    /** L'écart à la borne a-t-il augmenté ? Sans valeur chiffrée (élément imposé, règle simple) : non. */
    private static function isWorse(StructureViolation $old, StructureViolation $new): bool
    {
        if (null === $old->actual || null === $new->actual) {
            return false;
        }
        if (str_ends_with($new->ruleKey, ':max')) {
            return $new->actual > $old->actual;
        }
        if (str_ends_with($new->ruleKey, ':min')) {
            return $new->actual < $old->actual;
        }

        return false;
    }
}
