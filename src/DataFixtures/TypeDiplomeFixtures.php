<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Diplome\Spec\VersionConfig;
use App\Entity\TypeDiplome;
use App\Entity\TypeDiplomeVersion;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Types de diplôme de démonstration (Licence, LP, Master, M2E, BUT), chacun avec
 * une version 2026-2027, par configuration seulement. Les valeurs BUT (ratio SAÉ,
 * stages) sont indicatives, à confirmer par le métier.
 */
final class TypeDiplomeFixtures extends Fixture implements DependentFixtureInterface
{
    public const REF_PREFIX = 'type-diplome-';
    public const YEAR = '2026-2027';

    public function getDependencies(): array
    {
        return [NodeTypeFixtures::class, MccTypeFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $licence = $this->licence();
        $lp = $this->lp($licence);
        $master = $this->master($licence);

        $defs = [
            ['L', 'Licence', 'L', null, $licence],
            ['LP', 'Licence professionnelle', 'LP', 'L', $lp],
            ['M', 'Master', 'M', 'L', $master],
            ['M2E', 'Master M2E', 'M2E', null, $this->m2e()],
            ['BUT', 'BUT', 'BUT', null, $this->but()],
        ];
        foreach ($defs as [$key, $label, $sigle, $basedOn, $config]) {
            $type = new TypeDiplome($key, $label, $sigle);
            $version = (new TypeDiplomeVersion($type, self::YEAR))
                ->setConfig(VersionConfig::fromArray($config))
                ->setBasedOn($basedOn);
            $type->addVersion($version);
            $manager->persist($type);
            $this->addReference(self::REF_PREFIX.$key, $type);
        }
        $manager->flush();
    }

    /** @return array<string, mixed> */
    private function licence(): array
    {
        return [
            'structure' => [
                'levels' => [
                    ['typeKey' => 'annee'],
                    ['typeKey' => 'semestre', 'count' => ['min' => 2, 'max' => 2], 'ectsSum' => ['min' => 30, 'max' => 30]],
                    ['typeKey' => 'ue', 'count' => ['min' => 3, 'max' => 6]],
                    ['typeKey' => 'ec'],
                ],
                'parcoursAllowed' => true,
                'duration' => ['min' => 3, 'max' => 4],
                'natures' => ['ec', 'stage'],
            ],
            'mccc' => [
                'CT' => ['profile' => 'licence'],
                'CC_CT' => ['profile' => 'licence'],
                'CC' => ['profile' => 'licence'],
                'CCI' => ['profile' => 'licence'],
            ],
            'options' => ['quitus' => false, 'assiduite' => false, 'sansNote' => false],
            'bccLevels' => ['Bloc', 'Compétence'],
            'bccLinkLevel' => 1,
        ];
    }

    /**
     * @param array<string, mixed> $licence
     *
     * @return array<string, mixed>
     */
    private function lp(array $licence): array
    {
        $c = $licence;
        $c['structure']['duration'] = ['min' => 1, 'max' => 2];
        $c['structure']['levels'][1]['startAt'] = 5;
        // le dernier semestre porte au moins un projet tutoré et un stage (règles d'obligation, cf. spec §6.5)
        $c['structure']['natures'] = [
            ['key' => 'ec', 'label' => 'EC'],
            ['key' => 'projet_tutore', 'label' => 'Projet tutoré'],
            ['key' => 'stage', 'label' => 'Stage'],
        ];
        $c['structure']['rules'] = [
            self::minElementType('lp-projet-tutore', 'projet_tutore', 'last'),
            self::minElementType('lp-stage', 'stage', 'last'),
        ];
        $c['mccc'] = array_map(static fn (array $m) => ['profile' => 'master'], $c['mccc']);

        return $c;
    }

    /**
     * @param array<string, mixed> $licence
     *
     * @return array<string, mixed>
     */
    private function master(array $licence): array
    {
        $c = $licence;
        $c['structure']['duration'] = ['min' => 2, 'max' => 2];
        $c['mccc'] = array_map(static fn (array $m) => ['profile' => 'master'], $c['mccc']);

        return $c;
    }

    /** @return array<string, mixed> */
    private function m2e(): array
    {
        return [
            'structure' => [
                'levels' => [
                    ['typeKey' => 'annee'],
                    ['typeKey' => 'bloc_formation', 'count' => ['min' => 1, 'max' => 4]],
                    ['typeKey' => 'ec'],
                ],
                'parcoursAllowed' => false,
                'duration' => ['min' => 2, 'max' => 2],
                'natures' => ['ec'],
            ],
            'mccc' => [
                'CT' => ['profile' => 'master'],
                'CC_CT' => ['profile' => 'master'],
                'CC' => ['profile' => 'master'],
                'CCI' => ['profile' => 'master'],
            ],
            'options' => ['quitus' => false, 'assiduite' => false, 'sansNote' => false],
            'bccLevels' => ['Bloc', 'Objectif', 'Attendu'],
            'bccLinkLevel' => 2,
        ];
    }

    /** @return array<string, mixed> */
    private function but(): array
    {
        return [
            'structure' => [
                'levels' => [
                    ['typeKey' => 'annee'],
                    ['typeKey' => 'semestre', 'count' => ['min' => 2, 'max' => 2], 'ectsSum' => ['min' => 30, 'max' => 30]],
                    // 5 UE par semestre, figées ; les EC y sont rattachés par coefficient
                    ['typeKey' => 'ue', 'count' => ['min' => 5, 'max' => 5], 'locked' => true, 'ecLink' => 'coefficient'],
                    ['typeKey' => 'ec'],
                ],
                'parcoursAllowed' => true,
                'duration' => ['levelKey' => 'semestre', 'bound' => ['min' => 6, 'max' => 6]],
                'natures' => [
                    ['key' => 'ressource', 'label' => 'Ressource'],
                    ['key' => 'sae', 'label' => 'SAÉ'],
                    ['key' => 'stage', 'label' => 'Stage'],
                    ['key' => 'portfolio', 'label' => 'Portfolio'],
                ],
                'rules' => [
                    [
                        'uid' => 'but-ratio-sae',
                        'template' => 'ratio',
                        'params' => ['attribute' => 'ects', 'numeratorNature' => 'sae', 'op' => '>=', 'percent' => 40],
                        'scopeLevel' => 'semestre',
                        'severity' => 'error',
                    ],
                    // valeurs de démonstration (spec §6.5) : un stage dans les semestres 2, 4 et 6
                    self::minElementType('but-stage-s2', 'stage', '2'),
                    self::minElementType('but-stage-s4', 'stage', '4'),
                    self::minElementType('but-stage-s6', 'stage', '6'),
                ],
            ],
            'mccc' => [
                'CC' => ['profile' => null],
                'CT' => ['profile' => null],
            ],
            'options' => ['quitus' => false, 'assiduite' => false, 'sansNote' => false],
            'bccLevels' => ['Compétence', 'Niveau', 'Apprentissage critique'],
            'bccLinkLevel' => 2,
        ];
    }

    /**
     * Règle « Au moins 1 EC d'un type » dans le semestre désigné (`first`, `last` ou numéro affiché).
     *
     * @return array<string, mixed>
     */
    private static function minElementType(string $uid, string $nature, string $position): array
    {
        return [
            'uid' => $uid,
            'template' => 'min_element_type',
            'params' => ['nature' => $nature, 'min' => 1],
            'scopeLevel' => 'semestre',
            'scopePosition' => $position,
            'severity' => 'error',
        ];
    }
}
