<?php

namespace DaveJamesMiller\MigrationsUI\Controllers;

use DaveJamesMiller\MigrationsUI\Exceptions\SeederException;
use DaveJamesMiller\MigrationsUI\Migrator;
use DaveJamesMiller\MigrationsUI\Models\Migration;
use DaveJamesMiller\MigrationsUI\Repositories\MigrationsRepository;
use DaveJamesMiller\MigrationsUI\Responses\OverviewResponse;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use LogicException;

class RunMigrationsController
{
    /** @var \DaveJamesMiller\MigrationsUI\Repositories\MigrationsRepository */
    private $migrations;

    /** @var \DaveJamesMiller\MigrationsUI\Migrator */
    private $migrator;

    /** @var \DaveJamesMiller\MigrationsUI\Responses\OverviewResponse */
    private $response;

    /** @var float */
    private $startTime;

    public function __construct(MigrationsRepository $migrations, Migrator $migrator)
    {
        $this->migrations = $migrations;
        $this->migrator = $migrator;
        $this->response = OverviewResponse::make();
        $this->startTime = microtime(true);
    }

    private function load(Collection $migrations): void
    {
        $files = $migrations->pluck('file')->all();

        $this->migrator->requireFiles($files);
    }

    private function migrate(Collection $migrations): int
    {
        $this->load($migrations);

        $this->migrator->runPending($migrations->pluck('name')->all());

        return count($migrations);
    }

    private function migrateAndRespond(Collection $migrations)
    {
        $count = $this->migrate($migrations);

        if ($migrations->count() === 1) {
            $migration = $migrations->first();

            return $this->response->withSuccess('Migrated', $migration->name, $this->runtime());
        }

        return $this->response->withSuccess('Migrated', "Ran $count migrations.", $this->runtime());
    }

    public function migrateSingle(Migration $migration)
    {
        return $this->migrateAndRespond(collect([$migration]));
    }

    public function migrateAll()
    {
        $migrations = $this->migrations->pending();

        if ($migrations->isEmpty()) {
            return OverviewResponse::make()->withError('Cannot Run Migrations', 'No migrations are pending.');
        }

        return $this->migrateAndRespond($migrations);
    }

    private function rollback(Collection $migrations): int
    {
        $this->load($migrations);

        $this->migrator->rollbackMigrations(
            $migrations->map(static function (Migration $migration) {
                return ['migration' => $migration->name];
            })->all(),
            $this->migrator->allPaths(),
            []
        );

        return count($migrations);
    }

    private function rollbackAndRespond(Collection $migrations)
    {
        $count = $this->rollback($migrations);

        if ($count === 1) {
            $migration = $migrations->first();

            return $this->response->withSuccess('Rolled Back', $migration->name, $this->runtime());
        }

        return $this->response->withSuccess('Rolled Back', "Rolled back $count migrations.", $this->runtime());
    }

    public function rollbackSingle(Migration $migration)
    {
        return $this->rollbackAndRespond(collect([$migration]));
    }

    public function rollbackBatch(int $batch)
    {
        $migrations = $this->migrations->batch($batch);

        if ($migrations->isEmpty()) {
            return OverviewResponse::make()->withError('Cannot Roll Back', "No migrations found in batch $batch.");
        }

        return $this->rollbackAndRespond($migrations);
    }

    public function rollbackAll()
    {
        $migrations = $this->migrations->applied();

        if ($migrations->isEmpty()) {
            return OverviewResponse::make()->withError('Cannot Roll Back', "No applied migrations found.");
        }

        return $this->rollbackAndRespond($migrations);
    }

    public function fresh(DatabaseManager $db)
    {
        $builder = $db->getSchemaBuilder();

        /** @see \Illuminate\Database\Console\WipeCommand::handle() */
        $types = ['tables'];

        if (config('migrations-ui.fresh.views')) {
            try {
                $builder->dropAllViews();
                $types[] = 'views';
            } catch (LogicException $e) {
                Session::flash('migrations-ui::warning-title', 'Cannot Drop Views');
                Session::flash('migrations-ui::warning', $e->getMessage());
            }
        }

        $builder->dropAllTables();

        if (config('migrations-ui.fresh.types')) {
            try {
                $builder->dropAllTypes();
                $types[] = 'types';
            } catch (LogicException $e) {
                Session::flash('migrations-ui::warning-title', 'Cannot Drop Types');
                Session::flash('migrations-ui::warning', $e->getMessage());
            }
        }

        $this->migrator->getRepository()->createRepository();

        $message = 'Dropped all ' . collect($types)->join(', ', ' & ') . '.';
        return $this->finishRefresh('Fresh', $message);
    }

    public function refresh()
    {
        $count = $this->rollback($this->migrations->applied());

        $message = $count === 1 ? 'Rolled back 1 migration.' : "Rolled back $count migrations.";
        return $this->finishRefresh('Refresh', $message);
    }

    private function finishRefresh($type, $message)
    {
        $messages = [$message];

        $count = $this->migrate($this->migrations->pending());
        $messages[] = $count === 1 ? 'Ran 1 migration.' : "Ran $count migrations.";

        if (Request::get('seed', false) && $this->runSeeder()) {
            $messages[] = 'Seeded the database.';
        }

        return $this->response->withSuccess($type, implode("\n", $messages), $this->runtime());
    }

    private function runSeeder()
    {
        /** @see \DatabaseSeeder */
        $class = config('migrations-ui.seeder');

        if (!class_exists($class)) {
            $this->response->withError('Cannot Seed Database', "Unable to find $class class.");
            return false;
        }

        /** @see \Illuminate\Database\Console\Seeds\SeedCommand::getSeeder() */
        /** @var \Illuminate\Database\Seeder $seeder */
        $seeder = app($class);

        /** @see \Illuminate\Database\Console\Seeds\SeedCommand::handle() */
        $seeder->setContainer(app())->__invoke();

        return true;
    }

    public function seed()
    {
        if ($this->runSeeder()) {
            $this->response->withSuccess('Seed', 'Database seeded', $this->runtime());
        }

        return $this->response;
    }

    private function runtime()
    {
        return round(microtime(true) - $this->startTime, 2);
    }
}
