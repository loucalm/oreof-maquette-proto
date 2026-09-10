<?php

declare(strict_types=1);

namespace App\Maquette\Doc;

use App\Entity\Formation;
use App\Entity\NodeType;
use App\Enum\NodeFamily;

/**
 * Nœud de l'arbre d'une maquette — value object mutable, hydraté depuis le
 * document JSON `Formation::arbre` par le service App\Maquette\Maquette.
 *
 * Expose volontairement la même surface de lecture que l'ancienne entité
 * App\Entity\Node (getId/getType/getLabel/getChildren/…) pour que les gabarits
 * et le moteur de statut n'aient presque rien à changer. La position d'un nœud
 * = son index dans le tableau `children` de son parent (ou dans `roots`).
 */
final class TreeNode
{
    /** @var list<TreeNode> */
    public array $children = [];

    public ?TreeNode $parent = null;

    public ?MaquetteDoc $doc = null;

    private ?NodeType $type = null;

    /**
     * @param array<string, mixed>       $attributes
     * @param array<string, bool>|null   $capabilityOverrides
     * @param list<string>               $locked
     */
    public function __construct(
        public string $nid,
        public string $typeKey,
        public string $label = '',
        public ?string $code = null,
        public array $attributes = [],
        public ?array $capabilityOverrides = null,
        public array $locked = [],
        public bool $mutualized = false,
        public ?string $mutualizedFrom = null,
    ) {
    }

    // ─── résolution du type (posée par Maquette à l'hydratation) ───

    public function bindType(NodeType $type): void
    {
        $this->type = $type;
    }

    public function getType(): NodeType
    {
        \assert($this->type instanceof NodeType, "Type « {$this->typeKey} » non résolu pour le nœud {$this->nid}.");

        return $this->type;
    }

    // ─── identité / libellé ───

    public function getId(): string
    {
        return $this->nid;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getDisplayLabel(): string
    {
        return $this->label !== '' ? $this->label : $this->getType()->getLabel();
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;

        return $this;
    }

    // ─── attributs ───

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param array<string, mixed> $attributes */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;

        return $this;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function setAttribute(string $key, mixed $value): self
    {
        if ($value === null || $value === '' || $value === []) {
            unset($this->attributes[$key]);
        } else {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    // ─── capacités ───

    /** @return array<string, bool>|null */
    public function getCapabilityOverrides(): ?array
    {
        return $this->capabilityOverrides;
    }

    /** @param array<string, bool>|null $overrides */
    public function setCapabilityOverrides(?array $overrides): self
    {
        $this->capabilityOverrides = $overrides;

        return $this;
    }

    /** @return array<string, bool> */
    public function effectiveCapabilities(): array
    {
        $caps = [];
        foreach ($this->getType()->getCapabilities() as $name => $value) {
            $caps[$name] = (bool) $value;
        }
        foreach ($this->capabilityOverrides ?? [] as $name => $value) {
            if (!$this->getType()->isCapabilityLocked($name)) {
                $caps[$name] = (bool) $value;
            }
        }

        return $caps;
    }

    public function can(string $capability): bool
    {
        return $this->effectiveCapabilities()[$capability] ?? false;
    }

    // ─── arbre ───

    /** @return list<TreeNode> */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function getFormation(): ?Formation
    {
        return $this->doc?->formation;
    }

    /** Position = index parmi les frères (0 si racine orpheline). */
    public function getPosition(): int
    {
        $siblings = $this->parent?->children ?? $this->doc?->roots ?? [];
        $i = array_search($this, $siblings, true);

        return $i === false ? 0 : $i;
    }

    public function getDepth(): int
    {
        $depth = 0;
        for ($c = $this->parent; $c !== null; $c = $c->parent) {
            ++$depth;
        }

        return $depth;
    }

    // ─── nature ───

    public function isParcours(): bool
    {
        return $this->typeKey === 'parcours';
    }

    public function isCompetenceNode(): bool
    {
        return $this->getType()->getFamily() === NodeFamily::Competence;
    }

    public function isBloc(): bool
    {
        return $this->typeKey === 'bloc_competences';
    }

    public function isTransversalBloc(): bool
    {
        return $this->isBloc() && (bool) ($this->attributes['transversal'] ?? false);
    }

    public function getDescription(): string
    {
        return trim((string) ($this->attributes['description'] ?? ''));
    }

    // ─── mutualisation ───

    public function isMutualized(): bool
    {
        return $this->mutualized;
    }

    public function setMutualized(bool $mutualized): self
    {
        $this->mutualized = $mutualized;

        return $this;
    }

    public function getMutualizedFrom(): ?string
    {
        return $this->mutualizedFrom;
    }

    public function setMutualizedFrom(?string $ref): self
    {
        $this->mutualizedFrom = $ref;

        return $this;
    }

    // ─── verrous « nœud imposé » (Phase 5 ; vide avant) ───

    /** @return list<string> */
    public function getLocked(): array
    {
        return $this->locked;
    }

    public function isLocked(string $action): bool
    {
        return \in_array($action, $this->locked, true);
    }

    // ─── ramification entre parcours ───

    public function getParcoursParent(): ?self
    {
        $ref = $this->attributes['parcoursParent'] ?? null;

        return \is_string($ref) ? $this->doc?->node($ref) : null;
    }

    public function setParcoursParent(?self $parent): self
    {
        if ($parent === null) {
            unset($this->attributes['parcoursParent']);
        } else {
            $this->attributes['parcoursParent'] = $parent->nid;
        }

        return $this;
    }

    /** Période de début du parcours sur l'axe temporel (défaut 1). */
    public function getPeriodeDebut(): int
    {
        return max(1, (int) ($this->attributes['periodeDebut'] ?? $this->attributes['anneeDebut'] ?? 1));
    }

    /** Période de fin ; par défaut, début + (nb de périodes enfants - 1). */
    public function getPeriodeFin(): int
    {
        $stored = (int) ($this->attributes['periodeFin'] ?? $this->attributes['anneeFin'] ?? 0);
        if ($stored > 0) {
            return max($stored, $this->getPeriodeDebut());
        }
        $periodeKey = $this->getFormation()?->getChildTypeKey('parcours');
        $nb = 0;
        foreach ($this->children as $c) {
            if ($c->typeKey === $periodeKey) {
                ++$nb;
            }
        }

        return $this->getPeriodeDebut() + max(0, $nb - 1);
    }

    /**
     * Règle de ramification : un parcours enfant se sépare du parent — il
     * commence après lui et se prolonge au moins jusqu'à sa fin.
     */
    public static function parcoursPeriodsAllowChild(int $parentDebut, int $parentFin, int $childDebut, int $childFin): bool
    {
        return $childDebut > $parentDebut
            && $childDebut <= $parentFin + 1
            && $childFin >= $parentFin;
    }

    // ─── BCC porté par un nœud (parcours en multi) ───

    /** @return list<TreeNode> blocs de compétences, transversal en tête */
    public function getBccBlocs(): array
    {
        $blocs = array_values(array_filter($this->children, static fn (TreeNode $n) => $n->isBloc()));
        usort($blocs, static fn (TreeNode $a, TreeNode $b): int => [!$a->isTransversalBloc(), $a->getPosition()] <=> [!$b->isTransversalBloc(), $b->getPosition()]);

        return $blocs;
    }

    public function hasTransversalBloc(): bool
    {
        foreach ($this->children as $c) {
            if ($c->isTransversalBloc()) {
                return true;
            }
        }

        return false;
    }

    // ─── sections « Paramètre du parcours » (document JSON 2/3) ───

    /** @return array<string, mixed> */
    public function getParametre(string $section): array
    {
        if (!$this->isParcours() || $this->doc === null) {
            return [];
        }

        return $this->doc->parcoursParam($this->nid, $section);
    }

    /** @param array<string, mixed> $data */
    public function setParametre(string $section, array $data): self
    {
        if ($this->isParcours() && $this->doc !== null) {
            $this->doc->setParcoursParam($this->nid, $section, $data);
        }

        return $this;
    }

    // ─── (dé)sérialisation ───

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            nid: (string) ($raw['nid'] ?? ''),
            typeKey: (string) ($raw['type'] ?? ''),
            label: (string) ($raw['label'] ?? ''),
            code: isset($raw['code']) && $raw['code'] !== '' ? (string) $raw['code'] : null,
            attributes: \is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [],
            capabilityOverrides: \is_array($raw['capabilityOverrides'] ?? null) ? $raw['capabilityOverrides'] : null,
            locked: array_values(array_filter((array) ($raw['locked'] ?? []), 'is_string')),
            mutualized: (bool) ($raw['mutualized'] ?? false),
            mutualizedFrom: isset($raw['mutualizedFrom']) && $raw['mutualizedFrom'] !== '' ? (string) $raw['mutualizedFrom'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'nid' => $this->nid,
            'type' => $this->typeKey,
            'label' => $this->label,
        ];
        if ($this->code !== null && $this->code !== '') {
            $out['code'] = $this->code;
        }
        if ($this->attributes !== []) {
            $out['attributes'] = $this->attributes;
        }
        if ($this->capabilityOverrides !== null && $this->capabilityOverrides !== []) {
            $out['capabilityOverrides'] = $this->capabilityOverrides;
        }
        if ($this->locked !== []) {
            $out['locked'] = array_values($this->locked);
        }
        if ($this->mutualized) {
            $out['mutualized'] = true;
        }
        if ($this->mutualizedFrom !== null) {
            $out['mutualizedFrom'] = $this->mutualizedFrom;
        }
        if ($this->children !== []) {
            $out['children'] = array_map(static fn (TreeNode $c) => $c->toArray(), $this->children);
        }

        return $out;
    }
}
