<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\V2\V2ExtractFile;
use App\Import\V2\V2Importer;
use App\Import\V2\V2Reader;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Import de données d'ORéOF v2 dans le prototype (mode d'emploi : docs/import-v2.md).
 *
 *   --list                  formations de la base v2, pour choisir
 *   --formation=ID (×n)     importe ces formations (et les référentiels liés)
 *   --save=fichier.json     enregistre l'extrait (anonymisé) au lieu d'importer : exemple rejouable sans la base
 *   --from=fichier.json     importe un extrait enregistré, sans base v2
 *
 * Sans --formation ni --from : seuls les référentiels liés sont importés.
 */
#[AsCommand(name: 'app:import-v2', description: 'Importe des formations et référentiels d’ORéOF v2 (base en lecture seule, ou extrait JSON).')]
final class ImportV2Command
{
    public function __construct(
        private readonly V2Importer $importer,
        #[Autowire('%env(default::OREOF_V2_DATABASE_URL)%')]
        private readonly ?string $defaultSource,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** @param list<string> $formation */
    public function __invoke(
        SymfonyStyle $io,
        #[Option('URL de la base v2 (sinon OREOF_V2_DATABASE_URL), ex. mysql://oreof:motdepasse@oreof-db:3306/oreof_v2')] ?string $source = null,
        #[Option('Liste les formations de la base v2 (filtre facultatif par type : L, LP, M, BUT…)')] string|bool $list = false,
        #[Option('Identifiant v2 d’une formation à importer (répétable)')] array $formation = [],
        #[Option('Enregistre l’extrait JSON anonymisé dans ce fichier au lieu d’importer')] ?string $save = null,
        #[Option('Importe un extrait JSON enregistré (pas besoin de la base v2)')] ?string $from = null,
    ): int {
        $ids = [];
        foreach ($formation as $id) {
            if (1 !== preg_match('/^\d+$/', (string) $id)) {
                $io->error(sprintf('--formation attend l’identifiant v2 d’une formation (un nombre), pas « %s ». Voir --list.', $id));

                return Command::INVALID;
            }
            $ids[] = (int) $id;
        }

        try {
            return $this->run($io, $source, $list, $ids, $save, $from);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
        } catch (DbalException $e) {
            $io->error(sprintf('Base ORéOF v2 injoignable ou inattendue : %s. Vérifiez OREOF_V2_DATABASE_URL (ou --source) et l’accès réseau (docs/import-v2.md).', $e->getMessage()));
        } catch (\JsonException $e) {
            $io->error(sprintf('Extrait JSON illisible : %s', $e->getMessage()));
        }

        return Command::FAILURE;
    }

    /** @param list<int> $formation */
    private function run(SymfonyStyle $io, ?string $source, string|bool|null $list, array $formation, ?string $save, ?string $from): int
    {
        if (null !== $from) {
            return $this->import($io, V2ExtractFile::load($this->path($from)), $from);
        }

        $dsn = $source ?? ('' !== (string) $this->defaultSource ? $this->defaultSource : null);
        if (null === $dsn) {
            $io->error('Aucune base v2 : passez --source=… ou réglez OREOF_V2_DATABASE_URL dans .env.local. Sans accès à la base, rejouez un exemple : --from=data/import-v2/exemple.json');

            return Command::INVALID;
        }
        $reader = V2Reader::fromDsn($dsn);

        if (false !== $list) {
            $rows = $reader->listFormations(\is_string($list) && '' !== $list ? $list : null);
            $io->table(['id', 'type', 'formation', 'parcours', 'campagne'], array_map(static fn (array $r) => [$r['id'], $r['type'], $r['name'], $r['parcours'], $r['campagne'] ?? '—'], $rows));

            return Command::SUCCESS;
        }

        $extract = $reader->extract($formation);
        if (null !== $save) {
            V2ExtractFile::save($extract, $this->path($save));
            $io->success(sprintf('Extrait anonymisé enregistré : %s (%d formation(s)). Rejouez-le avec --from=%s', $save, \count($extract['formations']), $save));

            return Command::SUCCESS;
        }

        return $this->import($io, $extract, 'la base v2');
    }

    /** @param array<string, mixed> $extract */
    private function import(SymfonyStyle $io, array $extract, string $origin): int
    {
        $io->title(sprintf('Import ORéOF v2 depuis %s', $origin));
        $report = $this->importer->import($extract);
        $io->listing([] === $report ? ['Référentiels à jour.'] : array_values(array_unique($report)));
        $io->success(sprintf('%d formation(s) traitée(s).', \count($extract['formations'] ?? [])));

        return Command::SUCCESS;
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir.'/'.$path;
    }
}
