<?php

namespace App\Console\Commands;

use Database\Seeders\UserAvatarDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Assigns bundled demo portrait photos to all users (deploy / staging friendly).
 */
class SeedDemoAvatarsCommand extends Command
{
    protected $signature = 'wpds:seed-demo-avatars {--force : Required in production}';

    protected $description = 'Seed realistic portrait photos for User Management (uses bundled images, no internet required).';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run in production without --force.');

            return self::FAILURE;
        }

        $storageLink = public_path('storage');
        if (! file_exists($storageLink)) {
            $this->warn('public/storage link missing — creating it now…');
            Artisan::call('storage:link');
            $this->line(trim(Artisan::output()));
        }

        $this->call('db:seed', [
            '--class' => UserAvatarDemoSeeder::class,
            '--force' => true,
        ]);

        return self::SUCCESS;
    }
}
