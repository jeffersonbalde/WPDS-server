<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Assigns realistic portrait photos to portal users so User Management
 * and directories show real faces in demos / staging.
 *
 * Prefers bundled images under database/seeders/data/portraits/ (no network
 * needed — works on locked-down deploys). Falls back to randomuser.me only
 * when a bundled file is missing.
 *
 * Safe to re-run: overwrites previous demo-user-*.jpg avatars.
 *
 * Run:
 *   php artisan db:seed --class=UserAvatarDemoSeeder --force
 *   php artisan wpds:seed-demo-avatars
 */
class UserAvatarDemoSeeder extends Seeder
{
    private const MEN_COUNT = 40;

    private const WOMEN_COUNT = 40;

    public function run(): void
    {
        if (! Storage::disk('public')->exists('avatars')) {
            Storage::disk('public')->makeDirectory('avatars');
        }

        $users = User::query()
            ->with('studentProfile')
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->command?->warn('No users found — skip UserAvatarDemoSeeder.');

            return;
        }

        $pack = $this->portraitPackPath();
        $hasPack = is_dir($pack.'/men') && is_dir($pack.'/women');
        if (! $hasPack) {
            $this->command?->warn('Bundled portrait pack missing — will try network download.');
        }

        $ok = 0;
        $fail = 0;
        $manIndex = 0;
        $womanIndex = 0;

        $this->command?->info("Seeding portrait photos for {$users->count()} users…");
        $bar = $this->command?->getOutput()?->createProgressBar($users->count());
        $bar?->start();

        foreach ($users as $user) {
            $female = $this->looksFemale($user);
            if ($female) {
                $slot = $womanIndex % self::WOMEN_COUNT;
                $womanIndex++;
                $genderDir = 'women';
            } else {
                $slot = $manIndex % self::MEN_COUNT;
                $manIndex++;
                $genderDir = 'men';
            }

            $bytes = $this->loadPortraitBytes($pack, $genderDir, $slot);
            if ($bytes === null || strlen($bytes) < 500) {
                $fail++;
                $bar?->advance();

                continue;
            }

            $filename = 'avatars/demo-user-'.$user->id.'.jpg';
            Storage::disk('public')->put($filename, $bytes, [
                'visibility' => 'public',
            ]);

            $old = $user->getRawOriginal('avatar_path');
            if ($old && $old !== $filename && Storage::disk('public')->exists($old)) {
                // Only delete previous demo avatars, not manually uploaded photos
                // that happen to share the same user id pattern after a reset.
                if (str_starts_with((string) $old, 'avatars/demo-user-')) {
                    Storage::disk('public')->delete($old);
                }
            }

            $user->forceFill(['avatar_path' => $filename])->save();
            $ok++;
            $bar?->advance();
        }

        $bar?->finish();
        $this->command?->newLine(2);
        $this->command?->info("Done: {$ok} avatars saved".($fail ? ", {$fail} failed" : '').'.');
        $this->command?->line('Open User Management — cards should show real portraits.');
        $this->command?->line('Ensure `php artisan storage:link` has been run on this host.');
    }

    private function portraitPackPath(): string
    {
        return database_path('seeders/data/portraits');
    }

    private function loadPortraitBytes(string $pack, string $genderDir, int $slot): ?string
    {
        $local = $pack.DIRECTORY_SEPARATOR.$genderDir.DIRECTORY_SEPARATOR.$slot.'.jpg';
        if (is_file($local)) {
            $bytes = File::get($local);

            return is_string($bytes) && $bytes !== '' ? $bytes : null;
        }

        $url = "https://randomuser.me/api/portraits/{$genderDir}/{$slot}.jpg";

        try {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'WPDS-AvatarSeeder/1.0'])
                ->get($url);

            if (! $response->successful() || strlen($response->body()) < 500) {
                return null;
            }

            return $response->body();
        } catch (\Throwable) {
            return null;
        }
    }

    private function looksFemale(User $user): bool
    {
        $gender = strtolower((string) ($user->studentProfile?->gender ?? ''));
        if (str_contains($gender, 'f') || str_contains($gender, 'woman') || str_contains($gender, 'girl')) {
            return true;
        }
        if (str_contains($gender, 'm') || str_contains($gender, 'boy')) {
            return false;
        }

        $name = strtoupper($user->name ?? '');
        if (preg_match('/,\s*(MARIA|ANA|ANNA|KAREN|JANE|MARY|ROSE|ANGELA|SOFIA|CRISTINA|LIZA)\b/', $name)) {
            return true;
        }

        return ((int) $user->id % 2) === 0;
    }
}
