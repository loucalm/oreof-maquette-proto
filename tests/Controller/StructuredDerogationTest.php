<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DerogationRequest;
use App\Entity\Formation;
use App\Enum\DerogationStatus;
use App\Repository\DerogationRequestRepository;
use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StructuredDerogationTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function formation(): Formation
    {
        return FormationFactory::create(static::getContainer())->fromTemplate('licence_lmd');
    }

    private function repo(): DerogationRequestRepository
    {
        return static::getContainer()->get(DerogationRequestRepository::class);
    }

    private function granted(Formation $f, string $ruleKey, string $scope, ?float $value): DerogationRequest
    {
        $d = (new DerogationRequest($f, 'Besoin'))->withDeviation($ruleKey, $scope, $value);
        $d->resolve(DerogationStatus::Approved, null);
        $this->em->persist($d);
        $this->em->flush();

        return $d;
    }

    public function testRequestCarriesTheExactDeviation(): void
    {
        $formation = $this->formation();

        $this->client->request('POST', '/formations/'.$formation->getId().'/derogations', [
            'message' => 'Une UE de plus', 'ruleKey' => 'level:ue:count:max', 'scopeNid' => 'S1', 'allowedValue' => '7',
        ]);

        $this->em->clear();
        $all = $this->repo()->findByFormation($formation);
        self::assertCount(1, $all);
        self::assertSame('level:ue:count:max', $all[0]->getRuleKey());
        self::assertSame('S1', $all[0]->getScopeNid());
        self::assertSame(7.0, $all[0]->getAllowedValue());
        self::assertTrue($all[0]->isPending());
    }

    public function testFreeTextRequestStillWorks(): void
    {
        $formation = $this->formation();

        $this->client->request('POST', '/formations/'.$formation->getId().'/derogations', ['message' => 'Libre']);

        $this->em->clear();
        $d = $this->repo()->findByFormation($formation)[0];
        self::assertNull($d->getRuleKey());
        self::assertNull($d->getAllowedValue());
    }

    public function testPendingOrRejectedDerogationCoversNothing(): void
    {
        $f = $this->formation();
        $pending = (new DerogationRequest($f, 'x'))->withDeviation('level:ue:count:max', 'S1', 7.0);
        $rejected = (new DerogationRequest($f, 'y'))->withDeviation('level:ue:count:max', 'S1', 9.0);
        $rejected->resolve(DerogationStatus::Rejected, null);
        $this->em->persist($pending);
        $this->em->persist($rejected);
        $this->em->flush();

        self::assertNull($this->repo()->grantedCovering($f, 'level:ue:count:max', 'S1'));
    }

    public function testGrantedDerogationCoversUpToAllowedValueOnly(): void
    {
        $f = $this->formation();
        $this->granted($f, 'level:ue:count:max', 'S1', 7.0);

        self::assertNotNull($this->repo()->grantedCovering($f, 'level:ue:count:max', 'S1', 7));
        self::assertNotNull($this->repo()->grantedCovering($f, 'level:ue:count:max', 'S1', 6));
        self::assertNull($this->repo()->grantedCovering($f, 'level:ue:count:max', 'S1', 8));
        self::assertNull($this->repo()->grantedCovering($f, 'level:ue:count:max', 'S2', 7), 'autre nœud');
        self::assertNull($this->repo()->grantedCovering($f, 'level:ue:ects:max', 'S1', 7), 'autre règle');
    }

    public function testMinimumImposedAndSinglePartRules(): void
    {
        $f = $this->formation();
        $this->granted($f, 'level:ue:count:min', 'S1', 3.0);
        $this->granted($f, 'imposed:abc', 'S1', null);
        $this->granted($f, 'rule:r1', 'S1', 5.0);
        $this->granted($f, 'rule:r2', 'S1', null);

        self::assertNotNull($this->repo()->grantedCovering($f, 'level:ue:count:min', 'S1', 3));
        self::assertNull($this->repo()->grantedCovering($f, 'level:ue:count:min', 'S1', 2));
        self::assertNotNull($this->repo()->grantedCovering($f, 'imposed:abc', 'S1', 0));
        self::assertNotNull($this->repo()->grantedCovering($f, 'rule:r1', 'S1', 5));
        self::assertNull($this->repo()->grantedCovering($f, 'rule:r1', 'S1', 6));
        self::assertNotNull($this->repo()->grantedCovering($f, 'rule:r2', 'S1', 42));
    }

    public function testAdminResolutionIsTimestampedAndShownInFormationHistory(): void
    {
        $f = $this->formation();
        $d = (new DerogationRequest($f, 'Une UE de plus'))->withDeviation('level:ue:count:max', 'S1', 7.0);
        $this->em->persist($d);
        $this->em->flush();

        $this->client->request('POST', '/administration/derogations/'.$d->getId().'/resoudre', ['status' => 'approved']);

        $this->em->clear();
        $d = $this->em->find(DerogationRequest::class, $d->getId());
        self::assertTrue($d->isApproved());
        self::assertNotNull($d->getResolvedAt());

        $this->client->request('GET', '/administration/derogations');
        self::assertSelectorTextContains('body', 'level:ue:count:max');
        self::assertSelectorTextContains('body', 'valeur accordée : 7');
    }

    public function testOnlyAdminCanResolve(): void
    {
        $f = $this->formation();
        $d = (new DerogationRequest($f, 'x'))->withDeviation('level:ue:count:max', 'S1', 7.0);
        $this->em->persist($d);
        $this->em->flush();
        $id = $d->getId();

        $this->client->request('POST', '/role/responsable');
        $this->client->request('POST', '/administration/derogations/'.$id.'/resoudre', ['status' => 'approved']);

        self::assertResponseRedirects('/');
        $this->em->clear();
        self::assertTrue($this->em->find(DerogationRequest::class, $id)->isPending());
    }
}
