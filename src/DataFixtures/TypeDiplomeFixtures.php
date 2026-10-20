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
 * une version 2026-2027, par configuration seulement. Les valeurs BUT (ratio SAÉ)
 * sont indicatives, à confirmer par le métier.
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

    /** @return array<string, string> */
    private function forms(): array
    {
        return [
            'formation.organisation' => 'param_organisation_formation',
            'formation.presentation' => 'param_presentation_formation',
            'parcours.organisation' => 'param_organisation_parcours',
            'parcours.presentation' => 'param_presentation_parcours',
        ];
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
            'forms' => $this->forms(),
            'mccc' => ['ec' => ['types' => ['CT', 'CC_CT', 'CC', 'CCI'], 'profile' => 'licence']],
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
        $c['structure']['imposed'] = [[
            'uid' => 'lp-projet-stage',
            'typeKey' => 'ue',
            'label' => 'Projet tutoré et stage',
            'parentLevel' => 'semestre',
            'position' => 'last',
            'locked' => ['delete', 'duplicate'],
        ]];
        $c['mccc']['ec']['profile'] = 'master';

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
        $c['mccc']['ec']['profile'] = 'master';

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
            'forms' => $this->forms(),
            'mccc' => ['ec' => ['types' => ['CT', 'CC_CT', 'CC', 'CCI'], 'profile' => 'master']],
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
                    ['typeKey' => 'ue'],
                    ['typeKey' => 'ec'],
                ],
                'parcoursAllowed' => true,
                'duration' => ['min' => 3, 'max' => 3],
                'natures' => ['ressource', 'sae'],
                'imposed' => [[
                    'uid' => 'but-stage',
                    'typeKey' => 'ec',
                    'label' => 'Stage',
                    'parentLevel' => 'ue',
                    'position' => 'last',
                    'nature' => 'stage',
                    'locked' => ['delete'],
                ]],
                'rules' => [[
                    'uid' => 'but-ratio-sae',
                    'template' => 'ratio',
                    'params' => ['attribute' => 'ects', 'numeratorNature' => 'sae', 'op' => '>=', 'percent' => 40],
                    'scopeLevel' => 'semestre',
                    'severity' => 'error',
                ]],
            ],
            'forms' => $this->forms(),
            'mccc' => [
                'ressource' => ['types' => ['CC'], 'profile' => null],
                'sae' => ['types' => ['CC'], 'profile' => null],
            ],
            'options' => ['quitus' => false, 'assiduite' => false, 'sansNote' => false],
            'bccLevels' => ['Compétence', 'Niveau', 'Apprentissage critique'],
            'bccLinkLevel' => 2,
        ];
    }
}
