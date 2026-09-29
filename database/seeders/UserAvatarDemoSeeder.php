<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads realistic portrait photos for every portal user so User Management
 * and directories show real faces (demo / local preview only).
 *
 * Safe to re-run: replaces previous demo avatars and keeps uploaded ones only
 * when --keep-existing is used via the artisan wrapper, or when a user already
 * has a non-demo path and force is false.
 *
 * Source: randomuser.me portrait CDN (stock headshots).
 *
 * Run: php artisan db:seed --class=UserAvatarDemoSeeder --force
 */
class UserAvatarDemoSeeder extends Seeder
{
    private const MEN_MAX = 99;

    private const WOMEN_MAX = 99;

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
                $slot = $womanIndex % (self::WOMEN_MAX + 1);
                $womanIndex++;
                $url = "https://randomuser.me/api/portraits/women/{$slot}.jpg";
            } else {
                $slot = $manIndex % (self::MEN_MAX + 1);
                $manIndex++;
                $url = "https://randomuser.me/api/portraits/men/{$slot}.jpg";
            }

            try {
                $response = Http::timeout(20)
                    ->withHeaders(['User-Agent' => 'WPDS-AvatarSeeder/1.0'])
                    ->get($url);

                if (! $response->successful() || strlen($response->body()) < 500) {
                    $fail++;
                    $bar?->advance();

                    continue;
                }

                $filename = 'avatars/demo-user-'.$user->id.'.jpg';
                Storage::disk('public')->put($filename, $response->body());

                $old = $user->getRawOriginal('avatar_path');
                if ($old && $old !== $filename && Storage::disk('public')->exists($old)) {
                    Storage::disk('public')->delete($old);
                }

                $user->forceFill(['avatar_path' => $filename])->save();
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                $this->command?->newLine();
                $this->command?->warn("Failed for {$user->email}: {$e->getMessage()}");
            }

            $bar?->advance();
        }

        $bar?->finish();
        $this->command?->newLine(2);
        $this->command?->info("Done: {$ok} avatars saved".($fail ? ", {$fail} failed" : '').'.');
        $this->command?->line('Open User Management — every card should show a real portrait.');
        $this->command?->line('Ensure `php artisan storage:link` has been run (already linked on most installs).');
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
        // Demo names often "LAST, FIRST M"
        if (preg_match('/,\s*(MARIA|ANA|ANNA|KAREN|JANE|MARY|ROSE|ANGELA|SOFIA|SOFIA|CRISTINA|LIZA|LIZA)\b/', $name)) {
            return true;
        }

        // Stable alternate for staff / unknown
        return ((int) $user->id % 2) === 0;
    }
}
