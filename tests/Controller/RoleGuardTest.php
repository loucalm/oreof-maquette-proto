<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\FormationFactory;
use App\Tests\Support\ResetsDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RoleGuardTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    private function switchRole(string $role): void
    {
        $this->client->request('POST', '/role/'.$role);
    }

    /** @return iterable<string, array{string, string}> */
    public static function adminOnlyRoutes(): iterable
    {
        yield 'édition type MCCC' => ['GET', '/administration/mcc-types/1/editer'];
        yield 'vider la structure' => ['POST', '/formations/{id}/reset'];
        yield 'enregistrer le squelette' => ['POST', '/formations/{id}/structure'];
        yield 'types d’ELP' => ['GET', '/node-types'];
    }

    #[DataProvider('adminOnlyRoutes')]
    public function testResponsableIsRedirectedFromAdminRoutes(string $method, string $path): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, withLeaves: true);
        $this->switchRole('responsable');

        $this->client->request($method, str_replace('{id}', (string) $formation->getId(), $path));

        self::assertResponseRedirects('/');
    }

    public function testResponsableCannotResetAFormation(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, withLeaves: true);
        $count = \count($formation->getArbre());
        $this->switchRole('responsable');

        $this->client->request('POST', '/formations/'.$formation->getId().'/reset');

        $this->em->clear();
        self::assertCount($count, $this->em->find($formation::class, $formation->getId())->getArbre());
    }

    public function testAdminCanReachAdminRoutes(): void
    {
        $this->switchRole('admin');

        $this->client->request('GET', '/node-types');

        self::assertResponseIsSuccessful();
    }

    public function testResponsableCannotChangeParcoursModeWhenATemplateIsImposed(): void
    {
        $formation = FormationFactory::create(static::getContainer())->fromType('L', 3, withLeaves: true);
        $this->switchRole('responsable');

        $this->client->request('POST', '/formations/'.$formation->getId().'/settings', [
            'name' => $formation->getName(),
            'diplome' => 'Licence',
            'avecParcours' => '1',
            'calendarSpan' => '9',
        ]);

        $this->em->clear();
        $fresh = $this->em->find($formation::class, $formation->getId());
        self::assertFalse($fresh->isAvecParcours());
        self::assertSame(3, $fresh->getCalendarSpan());
    }
}
