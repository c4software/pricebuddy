<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InitDatabase extends Command
{
    const COMMAND = 'buddy:init-db';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND;

    /**
     * The console command description.
     */
    protected $description = 'Initialize the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->getOutput()->info('Checking database connection');

        try {
            DB::connection()->getPdo();
            $hasTables = Schema::hasTable('migrations');
        } catch (Exception $e) {
            $this->getOutput()->error('Database connection failed, check environment settings');

            return self::FAILURE;
        }

        if ($hasTables) {
            $this->getOutput()->info('Database already initialized');

            $this->components->task('Applying database updates', function () {
                $this->callSilent('migrate', ['--force' => true]);
            });

            $this->createDefaultUser();
            $this->createDefaultStores();
        } else {
            $this->getOutput()->info('Database exists, but not initialized');

            $this->components->task(
                'Setting up the database',
                fn() => $this
                    ->callSilent('migrate:fresh', ['--force' => true])
            );

            $this->createDefaultStores();
            $this->createDefaultUser();
        }

        $this->getOutput()->success('Database init complete');

        return self::SUCCESS;
    }

    private function createDefaultStores(): void
    {
        // If there is already stores, do nothing
        $storeCount = DB::table('stores')->count();
        if ($storeCount > 0) {
            return;
        }

        // @phpstan-ignore-next-line
        $storeCountry = env('DEFAULT_STORES_COUNTRY', 'all');
        $this->components->task(
            'Creating stores for country: ' . $storeCountry,
            fn() => $this
                ->callSilent(CreateStores::COMMAND, ['country' => $storeCountry])
        );
    }

    private function createDefaultUser(): void
    {
        // @phpstan-ignore-next-line
        $email = env('APP_USER_EMAIL', 'admin@example.com');
        // @phpstan-ignore-next-line
        $password = env('APP_USER_PASSWORD', 'admin');

        if (!$email || !$password) {
            return;
        }

        # Check if there is 0 users
        $userCount = DB::table('users')->count();
        if ($userCount > 0) {
            return;
        }

        $this->components->task('Creating the default user', fn() => $this
            ->callSilent('make:filament-user', [
                // @phpstan-ignore-next-line
                '--name' => env('APP_USER_NAME', 'Admin'),
                '--email' => $email,
                '--password' => $password,
            ]));
    }
}
