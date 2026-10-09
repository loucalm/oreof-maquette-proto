<?php

declare(strict_types=1);

namespace App\Maquette;

use App\Enum\FormationPhase;
use App\Maquette\Doc\TreeNode;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Presse-papiers de la saisie d'un élément pédagogique : « Copier » retient les données
 * remplies d'un nœud, « Coller » les applique sur un autre nœud du même type d'ELP
 * (UE → UE, EC → EC…). Ne passent ni le libellé ni le code (propres à chaque élément),
 * ni l'arborescence : seulement les champs du catalogue (ECTS, heures, MCCC…).
 * Rangé en session : valable d'une formation à l'autre, jusqu'à la prochaine copie.
 */
final class NodeClipboard
{
    private const SESSION_KEY = 'proto_node_clipboard';

    /** Raisons pour lesquelles on ne peut pas coller (clés de traduction `node.panel.paste_blocked_*`). */
    public const BLOCKED_EMPTY = 'empty';
    public const BLOCKED_TYPE = 'type';
    public const BLOCKED_PHASE = 'phase';
    public const BLOCKED_SELF = 'self';
    public const BLOCKED_NO_FIELD = 'no_field';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly AttributeCatalog $catalog,
    ) {
    }

    /**
     * @return array{typeKey: string, typeLabel: string, label: string, nodeId: string, formationId: ?int, attributes: array<string, mixed>}
     */
    public function copy(TreeNode $node): array
    {
        $attributes = [];
        foreach (array_keys($this->catalog->all()) as $key) {
            $value = $node->getAttribute($key);
            if (null !== $value && '' !== $value && [] !== $value) {
                $attributes[$key] = $value;
            }
        }

        $clip = [
            'typeKey' => $node->typeKey,
            'typeLabel' => $node->getType()->getLabel(),
            'label' => $node->getDisplayLabel(),
            'nodeId' => $node->getId(),
            'formationId' => $node->getFormation()?->getId(),
            'attributes' => $attributes,
        ];
        $this->requestStack->getSession()->set(self::SESSION_KEY, $clip);

        return $clip;
    }

    /** @return array{typeKey: string, typeLabel: string, label: string, nodeId: string, formationId: ?int, attributes: array<string, mixed>}|null */
    public function get(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return null;
        }
        $clip = $request->getSession()->get(self::SESSION_KEY);

        return \is_array($clip) ? $clip : null;
    }

    /** Raison du refus (constante BLOCKED_*), ou null si on peut coller sur ce nœud. */
    public function blockedReason(TreeNode $target): ?string
    {
        $clip = $this->get();
        if (null === $clip) {
            return self::BLOCKED_EMPTY;
        }
        if ($clip['typeKey'] !== $target->typeKey) {
            return self::BLOCKED_TYPE;
        }
        // consolidation : squelette seulement, le contenu attend la construction
        if (FormationPhase::Consolidation === $target->getFormation()?->getPhase()) {
            return self::BLOCKED_PHASE;
        }
        if ($clip['nodeId'] === $target->getId() && $clip['formationId'] === $target->getFormation()?->getId()) {
            return self::BLOCKED_SELF;
        }
        if ([] === $this->applicable($clip, $target)) {
            return self::BLOCKED_NO_FIELD;
        }

        return null;
    }

    /**
     * Applique les données copiées sur le nœud : seuls les champs remplis dans la copie
     * écrasent ceux du nœud (les autres restent), et seuls ceux que le nœud expose.
     *
     * @return int nombre de champs collés
     */
    public function paste(TreeNode $target): int
    {
        $clip = $this->get();
        if (null === $clip) {
            return 0;
        }
        $values = $this->applicable($clip, $target);
        $target->setAttributes($values + $target->getAttributes());

        return \count($values);
    }

    /**
     * @param array{attributes: array<string, mixed>, formationId: ?int} $clip
     *
     * @return array<string, mixed>
     */
    private function applicable(array $clip, TreeNode $target): array
    {
        $caps = $target->effectiveCapabilities();
        $sameFormation = $clip['formationId'] === $target->getFormation()?->getId();
        $out = [];
        foreach ($clip['attributes'] as $key => $value) {
            if (!($caps[$key] ?? false)) {
                continue;
            }
            // les compétences référencent des nœuds de la formation d'origine : sans sens ailleurs
            if ('competencies' === $key && !$sameFormation) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
