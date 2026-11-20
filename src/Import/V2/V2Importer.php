<?php

declare(strict_types=1);

namespace App\Import\V2;

use App\Diplome\Spec\EcType;
use App\Entity\FicheMatiere;
use App\Entity\FicheMatiereMutualisation;
use App\Entity\FieldDef;
use App\Entity\Formation;
use App\Entity\MccType;
use App\Entity\Referentiel;
use App\Entity\TypeDiplome;
use App\Enum\RefCategory;
use App\FicheMatiere\EcValueResolver;
use App\FicheMatiere\FicheMatiereLifecycle;
use App\Maquette\Doc\MaquetteDoc;
use App\Maquette\Doc\TreeNode;
use App\Maquette\Maquette;
use App\Repository\FieldDefRepository;
use App\Repository\FormationRepository;
use App\Repository\ReferentielRepository;
use App\Repository\TypeDiplomeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Traduit un extrait d'ORéOF v2 (V2Reader, ou fichier JSON d'exemple) dans le prototype :
 * - référentiels : les libellés v2 absents sont ajoutés (rien n'est retiré, la démo reste valable) ;
 * - formations : fiche formation, parcours (ou parcours par défaut), arbre année › semestre › UE › EC,
 *   UE et EC à choix en blocs de choix, fiches matière portées par la formation importée.
 *
 * C'est le seul endroit qui connaît le modèle fixe de v2 (semestres numérotés, UE, EC) : il le range dans la chaîne
 * de niveaux de la version du type de diplôme. Une formation déjà importée (même identifiant v2) est remplacée.
 * MCCC : type, épreuves de première session, options ; la seconde session est gardée à part (`rattrapage`).
 * Non repris à ce stade : compétences (BCC), workflows, historique.
 */
final class V2Importer
{
    /** Clé de la formation où l'import note l'identifiant v2 (pour remplacer au prochain import). */
    public const MARK = 'import.v2';

    /** @var list<string> */
    private array $report = [];

    /**
     * Libellés v2 qui désignent une valeur déjà présente sous un autre libellé dans le prototype
     * (référentiel → libellé v2 normalisé → clé du prototype).
     */
    private const ALIASES = [
        'regimes_inscription' => [
            'formation continue contrat professionnalisation' => 'FC_PRO',
            'formation continue en contrat de professionnalisation' => 'FC_PRO',
        ],
    ];

    /** @var array<string, ?FieldDef> */
    private array $fieldDefs = [];

    /** @var array<string, Referentiel> référentiels lus ou créés pendant l'import (un créé n'est pas encore en base) */
    private array $refs = [];

    /** Numéro du premier semestre du type de diplôme en cours d'import (LP : 5). */
    private int $firstSemestre = 1;

    /** @var array<string, true>|null */
    private ?array $mccTypeKeys = null;

    /** Parcours (nid) en cours de remplissage ; null pour une formation sans parcours. */
    private ?string $currentParcours = null;

    /** @var array<int, list<?string>> id de fiche du prototype → parcours (nid) des EC qui l'utilisent */
    private array $ficheUses = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReferentielRepository $referentiels,
        private readonly FieldDefRepository $fields,
        private readonly TypeDiplomeRepository $types,
        private readonly FormationRepository $formations,
        private readonly Maquette $maquette,
        private readonly FicheMatiereLifecycle $fiches,
    ) {
    }

    /**
     * @param array<string, mixed> $extract
     *
     * @return list<string> compte rendu, une ligne par fait notable
     */
    public function import(array $extract): array
    {
        $this->report = [];
        $this->refs = [];
        foreach ($extract['referentiels'] ?? [] as $key => $labels) {
            $this->addReferentielValues((string) $key, (array) $labels);
        }
        $this->em->flush();

        foreach ($extract['formations'] ?? [] as $formation) {
            $this->formation($formation);
        }

        return $this->report;
    }

    /** @param array<string, mixed> $f */
    private function formation(array $f): void
    {
        $id = (int) $f['id'];
        $type = $this->types->findOneByKey((string) $f['typeDiplome']);
        $version = $type?->latestVersion();
        if (null === $type || null === $version) {
            $this->report[] = sprintf('Formation v2 n°%d « %s » ignorée : type de diplôme « %s » absent du prototype.', $id, $f['name'], $f['typeDiplome']);

            return;
        }
        $structure = $version->config()->structure;
        $chain = array_map(static fn ($level) => $level->typeKey, $structure->levels);
        if (!\in_array('semestre', $chain, true) || \count($chain) < 3) {
            $this->report[] = sprintf('Formation v2 n°%d ignorée : la structure de « %s » n’a pas de semestres, l’import ne sait pas la remplir.', $id, $type->getLabel());

            return;
        }

        $this->removePrevious($id);

        $parcoursList = $f['parcours'] ?? [];
        $mono = 1 === \count($parcoursList) && ($parcoursList[0]['defaut'] ?? false);
        if (!$mono && !$structure->parcoursAllowed) {
            $this->report[] = sprintf('Formation v2 n°%d : « %s » n’autorise pas les parcours, seul le premier est importé.', $id, $type->getLabel());
            $parcoursList = \array_slice($parcoursList, 0, 1);
            $mono = true;
        }
        // semestre n de v2 → année du diplôme, à partir du premier semestre du type (LP : semestre 5 = année 1)
        $this->firstSemestre = 1;
        foreach ($structure->levels as $level) {
            if ('semestre' === $level->typeKey) {
                $this->firstSemestre = max(1, $level->startAt);
            }
        }
        $years = max(1, ...array_map(fn (array $p) => $this->yearSpan($p)[1], $parcoursList ?: [['semestres' => []]]));

        $formation = (new Formation((string) $f['name']))
            ->setTypeDiplome($type)
            ->setAnneeUniversitaire($version->getAnneeUniversitaire())
            ->setDomaine($this->value('domaine', $f['domaine'] ?? null))
            ->setComposante($f['composante'] ?? null)
            ->setCalendarSpan($years);
        $this->em->persist($formation);
        $this->em->flush();

        $doc = $this->maquette->open($formation);
        $doc->formation->setAvecParcours(!$mono)->setStructure($chain);

        // fiches matière d'abord : les EC les désignent par leur identifiant
        $nids = [];
        $ficheIds = [];
        $ficheRows = [];
        foreach ($f['fiches'] ?? [] as $fiche) {
            $entity = (new FicheMatiere())->setFormation($formation);
            $entity->setData($this->resolveAll($fiche['data'] ?? []));
            $defaults = $fiche['defaults'] ?? [];
            if (isset($defaults['mccc'])) {
                $defaults['mccc'] = $this->mccc($defaults['mccc']);
            }
            $entity->setDefaults($defaults);
            $this->em->persist($entity);
            $ficheRows[] = [$entity, $fiche['parcoursId'] ?? null];
        }
        $this->em->flush();
        foreach ($f['fiches'] ?? [] as $i => $fiche) {
            $ficheIds[(int) $fiche['id']] = $ficheRows[$i][0]->getId();
        }

        $natures = $structure->natures;
        $parents = [];
        $this->ficheUses = [];
        foreach ($parcoursList as $p) {
            $container = null;
            if (!$mono) {
                [$first, $last] = $this->yearSpan($p);
                $container = $doc->addNode(null, 'parcours', (string) $p['label']);
                $container->setAttribute('periodeDebut', $first)->setAttribute('periodeFin', $last);
                $nids[(int) $p['id']] = $container->nid;
                $parents[] = [$container, $p['parentId'] ?? null];
                foreach ($p['fiche'] ?? [] as $entry => $data) {
                    $doc->setParcoursParam($container->nid, (string) $entry, $this->resolveAll($data));
                }
            } else {
                // parcours par défaut : sa fiche est celle du parcours unique (sans nom)
                foreach ($p['fiche'] ?? [] as $entry => $data) {
                    $doc->setFormationParam('parcours.'.$entry, $this->resolveAll($data));
                }
            }
            $this->currentParcours = $container?->nid;
            $ordres = array_map(static fn (array $sem) => (int) $sem['ordre'], $p['semestres'] ?? []);
            if (\count($ordres) !== \count(array_unique($ordres))) {
                $this->report[] = sprintf('Formation v2 n°%d, parcours « %s » : semestres en double dans v2 (%s), importés tels quels.', $id, $p['label'], implode(', ', $ordres));
            }
            $this->semestres($doc, $container, $p['semestres'] ?? [], $chain, $natures, $ficheIds);
        }
        foreach ($parents as [$node, $parentId]) {
            if (null !== $parentId && isset($nids[$parentId])) {
                $node->setParcoursParent($doc->node($nids[$parentId]));
            }
        }
        // une fiche sert un parcours porteur (celui de v2, sinon le premier qui l'utilise) ; les autres parcours qui
        // l'utilisent la reçoivent par mutualisation, comme une fiche partagée de v2
        foreach ($ficheRows as [$entity, $parcoursId]) {
            $used = array_values(array_unique(array_filter($this->ficheUses[$entity->getId()] ?? [], static fn ($nid) => null !== $nid)));
            $porteur = null !== $parcoursId && isset($nids[$parcoursId]) ? $nids[$parcoursId] : ($used[0] ?? null);
            $entity->setParcoursNid($porteur);
            foreach ($used as $nid) {
                if ($nid !== $porteur) {
                    $this->em->persist(new FicheMatiereMutualisation($entity, $formation, $nid));
                }
            }
        }

        foreach ($f['fiche'] ?? [] as $entry => $data) {
            $doc->setFormationParam((string) $entry, $this->resolveAll($data));
        }
        $doc->setFormationParam(self::MARK, ['id' => $id, 'importedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM)]);

        $this->maquette->saveGenerated($formation, $doc, 'Import ORéOF v2');
        $this->report[] = sprintf('Formation v2 n°%d importée : « %s » (%s, %s, %d fiche(s) matière).', $id, $formation->getName(), $type->getLabel(),
            $mono ? 'sans parcours' : \count($parcoursList).' parcours', \count($ficheRows));
    }

    /**
     * Semestres d'un parcours rangés dans la chaîne : un nœud par année (si la chaîne en a), un par semestre, puis UE et EC.
     *
     * @param list<array<string, mixed>> $semestres
     * @param list<string>               $chain
     * @param list<EcType>               $natures
     * @param array<int, int|null>       $ficheIds id fiche v2 → id fiche du prototype
     */
    private function semestres(MaquetteDoc $doc, ?TreeNode $container, array $semestres, array $chain, array $natures, array $ficheIds): void
    {
        $withYears = \in_array('annee', $chain, true);
        $years = [];
        foreach ($semestres as $s) {
            $parent = $container;
            if ($withYears) {
                $year = $this->yearOf((int) $s['ordre']);
                $years[$year] ??= $doc->addNode($container?->nid, 'annee');
                $parent = $years[$year];
            }
            $semestre = $doc->addNode($parent?->nid, 'semestre');
            foreach ($s['ues'] ?? [] as $ue) {
                $this->ue($doc, $semestre, $ue, $natures, $ficheIds);
            }
        }
    }

    /**
     * @param array<string, mixed> $ue
     * @param list<EcType>         $natures
     * @param array<int, int|null> $ficheIds
     */
    private function ue(MaquetteDoc $doc, TreeNode $parent, array $ue, array $natures, array $ficheIds): void
    {
        // UE à choix : bloc de choix dont les options sont les UE « enfants »
        if ([] !== ($ue['children'] ?? [])) {
            $bloc = $this->node($doc, $parent, 'bloc_choix', ['label' => ($ue['label'] ?? '') ?: 'UE à choix'] + $ue, ['choiceCount' => 1]);
            foreach ($ue['children'] as $child) {
                $this->ue($doc, $bloc, $child, $natures, $ficheIds);
            }

            return;
        }
        $node = $this->node($doc, $parent, 'ue', $ue, ['ueType' => $this->value('ueType', $ue['type'] ?? null)]);
        foreach ($ue['ecs'] ?? [] as $ec) {
            $this->ec($doc, $node, $ec, $natures, $ficheIds);
        }
    }

    /**
     * @param array<string, mixed> $ec
     * @param list<EcType>         $natures
     * @param array<int, int|null> $ficheIds
     */
    private function ec(MaquetteDoc $doc, TreeNode $parent, array $ec, array $natures, array $ficheIds): void
    {
        if ([] !== ($ec['children'] ?? [])) {
            $bloc = $this->node($doc, $parent, 'bloc_choix', ['label' => ($ec['label'] ?? '') ?: 'EC à choix'] + $ec, ['choiceCount' => 1]);
            foreach ($ec['children'] as $child) {
                $this->ec($doc, $bloc, $child, $natures, $ficheIds);
            }

            return;
        }
        $ficheId = null !== ($ec['fiche'] ?? null) ? ($ficheIds[(int) $ec['fiche']] ?? null) : null;
        if (null !== $ficheId) {
            $this->ficheUses[$ficheId][] = $this->currentParcours;
        }
        $this->node($doc, $parent, 'ec', $ec, [
            'hours' => $ec['hours'] ?? null,
            'ecType' => $this->value('ecType', $ec['type'] ?? null),
            'natureElement' => self::natureElement($ec['type'] ?? null, $natures),
            'ficheMatiere' => $ficheId,
            'mccc' => null !== ($ec['mccc'] ?? null) ? $this->mccc($ec['mccc']) : null,
            // ECTS, heures et MCCC saisis sur l'EC dans v2 : ce sont eux qui comptent, pas ceux de la fiche (source par défaut)
            'sources' => null === $ficheId ? null : array_filter([
                'ects' => null !== ($ec['ects'] ?? null) ? EcValueResolver::SOURCE_EC : null,
                'hours' => [] !== ($ec['hours'] ?? []) ? EcValueResolver::SOURCE_EC : null,
                'mccc' => null !== ($ec['mccc'] ?? null) ? EcValueResolver::SOURCE_EC : null,
            ]),
        ]);
    }

    /**
     * Nœud avec libellé, code, ECTS, nature et attributs propres ; les attributs vides ne sont pas posés.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $attributes
     */
    private function node(MaquetteDoc $doc, TreeNode $parent, string $typeKey, array $source, array $attributes): TreeNode
    {
        $node = $doc->addNode($parent->nid, $typeKey, (string) ($source['label'] ?? ''), $source['code'] ?? null);
        $attributes += ['ects' => $source['ects'] ?? null, 'nature' => $this->value('nature', $source['nature'] ?? null)];
        foreach ($attributes as $key => $value) {
            if (null !== $value && '' !== $value && [] !== $value) {
                $node->setAttribute($key, $value);
            }
        }

        return $node;
    }

    /**
     * MCCC de l'extrait → valeur du champ `mccc` : le libellé de chaque type d'épreuve devient sa clé dans le référentiel
     * `types_epreuve` (ajoutée si absente). Le type de MCCC, s'il n'existe pas dans le prototype, est signalé.
     *
     * @param array<string, mixed> $mccc
     *
     * @return array<string, mixed>
     */
    private function mccc(array $mccc): array
    {
        foreach (['evaluations', 'rattrapage'] as $list) {
            foreach ($mccc[$list] ?? [] as $i => $evaluation) {
                if (isset($evaluation['type'])) {
                    $mccc[$list][$i]['type'] = $this->addReferentielValues('types_epreuve', [$evaluation['type']])[0] ?? $evaluation['type'];
                }
            }
        }
        $type = $mccc['type'] ?? null;
        if (null !== $type && !isset($this->mccTypes()[$type])) {
            $this->report[] = sprintf('Type de MCCC « %s » absent du prototype : MCCC importées sans type.', $type);
            unset($mccc['type']);
        }

        return $mccc;
    }

    /** @return array<string, true> clés des types de MCCC du prototype */
    private function mccTypes(): array
    {
        return $this->mccTypeKeys ??= array_fill_keys(array_map(static fn (MccType $t) => $t->getKey(), $this->em->getRepository(MccType::class)->findAll()), true);
    }

    /** Remplace une formation déjà importée depuis le même identifiant v2 (ses fiches et son historique avec). */
    private function removePrevious(int $id): void
    {
        foreach ($this->formations->findAll() as $existing) {
            if (($existing->getParametres()[self::MARK]['id'] ?? null) !== $id) {
                continue;
            }
            if ([] !== $this->fiches->formationDeletionBlockers($existing)) {
                throw new \RuntimeException(sprintf('La formation « %s » (v2 n°%d) ne peut pas être remplacée : ses fiches sont utilisées par d’autres formations.', $existing->getName(), $id));
            }
            $this->fiches->deleteFichesOf($existing);
            $this->maquette->deleteHistory($existing);
            $this->em->remove($existing);
            $this->em->flush();
            $this->report[] = sprintf('Formation v2 n°%d : import précédent remplacé.', $id);
        }
    }

    /**
     * Valeurs d'une fiche : chaque champ lié à un référentiel reçoit la clé de la valeur (ajoutée si absente).
     * Les clés inconnues du prototype sont laissées de côté (signalées une fois).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function resolveAll(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (null === $this->fieldDef((string) $key)) {
                $this->report[] = sprintf('Champ « %s » inconnu du prototype : valeur non reprise.', $key);
                continue;
            }
            $out[$key] = \is_array($value)
                ? array_values(array_filter(array_map(fn ($v) => $this->value((string) $key, $v), $value), static fn ($v) => null !== $v))
                : $this->value((string) $key, $value);
        }

        return array_filter($out, static fn ($v) => null !== $v && [] !== $v);
    }

    /** Valeur d'un champ : pour un champ lié à un référentiel, la clé de ce libellé dans le référentiel. */
    private function value(string $fieldKey, mixed $label): mixed
    {
        if (null === $label || '' === $label) {
            return null;
        }
        $refKey = $this->fieldDef($fieldKey)?->getReferentielKey();
        if (null === $refKey || !\is_string($label)) {
            return $label;
        }

        return $this->addReferentielValues($refKey, [$label])[0] ?? $label;
    }

    /**
     * Ajoute à un référentiel les libellés qu'il n'a pas (comparaison sans casse ni espaces), crée le référentiel au besoin.
     *
     * @param list<mixed> $labels
     *
     * @return list<string> clé de chaque libellé dans le référentiel
     */
    private function addReferentielValues(string $key, array $labels): array
    {
        $ref = $this->refs[$key] ??= $this->referentiels->findOneByKey($key);
        if (null === $ref) {
            $ref = (new Referentiel($key, ucfirst(str_replace('_', ' ', $key))))
                ->setCategory(RefCategory::Entite)
                ->setEntiteCible('ORéOF v2 (import)');
            $this->em->persist($ref);
            $this->refs[$key] = $ref;
            $this->report[] = sprintf('Référentiel « %s » créé.', $key);
        }
        $values = $ref->getValues();
        $index = [];
        foreach ($values as $k => $v) {
            $index[self::normalize((string) $v)] = (string) $k;
            $index[self::normalize((string) $k)] ??= (string) $k;
        }
        $keys = [];
        $added = 0;
        foreach ($labels as $label) {
            $label = trim((string) $label);
            if ('' === $label) {
                continue;
            }
            $n = self::normalize($label);
            $alias = self::ALIASES[$key][$n] ?? null;
            if (null !== $alias && \array_key_exists($alias, $values)) {
                $index[$n] = $alias;
            }
            if (!isset($index[$n])) {
                $values[$label] = $label;
                $index[$n] = $label;
                ++$added;
            }
            $keys[] = $index[$n];
        }
        if ($added > 0) {
            $ref->setValues($values);
        }

        return $keys;
    }

    private function fieldDef(string $key): ?FieldDef
    {
        if (!\array_key_exists($key, $this->fieldDefs)) {
            $this->fieldDefs[$key] = $this->fields->findOneByKey($key);
        }

        return $this->fieldDefs[$key];
    }

    /**
     * Type d'EC de v2 (« Ressource », « SAÉ »…) → type d'EC de la version (par libellé), sinon rien.
     *
     * @param list<EcType> $natures
     */
    private static function natureElement(mixed $label, array $natures): ?string
    {
        if (!\is_string($label)) {
            return null;
        }
        foreach ($natures as $nature) {
            if (self::normalize($nature->label) === self::normalize($label)) {
                return $nature->key;
            }
        }

        return null;
    }

    /**
     * Première et dernière année couvertes par les semestres d'un parcours.
     *
     * @param array<string, mixed> $parcours
     *
     * @return array{0: int, 1: int}
     */
    private function yearSpan(array $parcours): array
    {
        $years = array_map(fn (array $s) => $this->yearOf((int) $s['ordre']), $parcours['semestres'] ?? []);

        return [] === $years ? [1, 1] : [min($years), max($years)];
    }

    /** Année du diplôme d'un semestre v2 : deux semestres par année, à compter du premier semestre du type. */
    private function yearOf(int $semestre): int
    {
        return (int) ceil(max(1, $semestre - $this->firstSemestre + 1) / 2);
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c', '’' => "'"]);

        return (string) preg_replace('/\s+/u', ' ', $s);
    }
}
