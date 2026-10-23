<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\ClassSection;
use App\Models\GradeChangeRequest;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Prepares the portal for a thesis defense demo: one staff account per role,
 * all named after the presenter, and every other non-student account removed.
 *
 * Students are never touched. Classes, change requests and announcements owned
 * by a removed account are handed to the demo teacher / IT account first, so no
 * academic data is lost. Safe to run repeatedly.
 *
 * Run: php artisan db:seed --class=DefenseDemoSeeder --force
 */
class DefenseDemoSeeder extends Seeder
{
    public const DEMO_NAME = 'Aaron N. Laride';

    /** Same demo password every other seeder in this project uses. */
    public const DEFAULT_PASSWORD = 'password';

    /** Set by tests; otherwise DEFENSE_DEMO_PASSWORD, falling back to the default. */
    public static ?string $passwordOverride = null;

    /** @var array<string, array{0: UserRole, 1: string}> email => [role, employee no] */
    private const STAFF = [
        'teacher@westprime.edu' => [UserRole::Teacher, 'TCH-001'],
        'registrar@westprime.edu' => [UserRole::Registrar, 'REG-001'],
        'admin@westprime.edu' => [UserRole::Admin, 'ADM-001'],
        'it@westprime.edu' => [UserRole::It, 'IT-001'],
        'stakeholder@westprime.edu' => [UserRole::Stakeholder, 'STK-001'],
    ];

    public function run(): void
    {
        $password = $this->resolvePassword();

        DB::transaction(function () use ($password) {
            $demo = $this->upsertDemoStaff($password);
            $removed = $this->removeOtherStaff($demo[UserRole::Teacher->value], $demo[UserRole::It->value]);

            $this->command?->info('Demo accounts ready:');
            foreach (self::STAFF as $email => [$role]) {
                $this->command?->line("  {$role->value}: {$email}");
            }
            $this->command?->info("Removed {$removed} other non-student account(s). Students were not touched.");
        });
    }

    private function resolvePassword(): string
    {
        $password = self::$passwordOverride
            ?? (string) env('DEFENSE_DEMO_PASSWORD', '')
            ?: self::DEFAULT_PASSWORD;

        if (strlen($password) < 8) {
            throw new \RuntimeException('The demo password must be at least 8 characters.');
        }

        return $password;
    }

    /**
     * @return array<string, User> keyed by role value
     */
    private function upsertDemoStaff(string $password): array
    {
        $users = [];

        foreach (self::STAFF as $email => [$role, $employeeNo]) {
            $user = User::query()->where('email', $email)->first() ?? new User(['email' => $email]);

            $user->forceFill([
                'name' => self::DEMO_NAME,
                'password' => Hash::make($password),
                'role' => $role,
                'is_active' => true,
            ])->save();

            $profile = StaffProfile::query()->where('user_id', $user->id)->first();
            if ($profile) {
                $profile->update(['employee_no' => $profile->employee_no ?: $employeeNo]);
            } else {
                StaffProfile::create([
                    'user_id' => $user->id,
                    'employee_no' => $employeeNo,
                    'department' => 'West Prime Horizon Institute',
                    'position' => $role->label(),
                ]);
            }

            $users[$role->value] = $user;
        }

        return $users;
    }

    private function removeOtherStaff(User $demoTeacher, User $demoIt): int
    {
        $doomed = User::query()
            ->where('role', '!=', UserRole::Student->value)
            ->whereNotIn('email', array_keys(self::STAFF))
            ->get();

        if ($doomed->isEmpty()) {
            return 0;
        }

        $ids = $doomed->pluck('id')->all();

        ClassSection::whereIn('teacher_id', $ids)->update(['teacher_id' => $demoTeacher->id]);
        GradeChangeRequest::whereIn('requested_by', $ids)->update(['requested_by' => $demoTeacher->id]);
        Announcement::whereIn('created_by', $ids)->update(['created_by' => $demoIt->id]);

        DB::table('notifications')
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $ids)
            ->delete();

        foreach ($doomed as $user) {
            if ($user->getRawOriginal('avatar_path')) {
                Storage::disk('public')->delete($user->getRawOriginal('avatar_path'));
            }
            $user->tokens()->delete();
            $user->delete();
        }

        return $doomed->count();
    }
}
