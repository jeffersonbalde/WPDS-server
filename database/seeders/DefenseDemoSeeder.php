<?php

namespace Database\Seeders;

use App\Enums\AcademicLevel;
use App\Enums\UserRole;
use App\Models\Admission;
use App\Models\Announcement;
use App\Models\ClassSection;
use App\Models\EnrollmentSubject;
use App\Models\GradeChangeRequest;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Prepares the portal for a thesis defense demo.
 *
 * - Adds one account per role named after the presenter (aaron.*@westprime.edu),
 *   including a student enrolled in a class taught by the presenter's teacher account.
 * - Keeps the standard demo accounts (teacher@, registrar@, admin@, it@, stakeholder@)
 *   and every student exactly as they are.
 * - Removes every other non-student account, after handing its classes, grade change
 *   requests and announcements to the standard teacher / IT accounts.
 *
 * Safe to run repeatedly. Run: php artisan db:seed --class=DefenseDemoSeeder --force
 */
class DefenseDemoSeeder extends Seeder
{
    public const DEMO_NAME = 'Aaron N. Laride';

    /** Same demo password every other seeder in this project uses. */
    public const DEFAULT_PASSWORD = 'password';

    /** Set by tests; otherwise DEFENSE_DEMO_PASSWORD, falling back to the default. */
    public static ?string $passwordOverride = null;

    /** @var array<string, UserRole> standard demo accounts that are never modified or removed */
    private const CORE_STAFF = [
        'teacher@westprime.edu' => UserRole::Teacher,
        'registrar@westprime.edu' => UserRole::Registrar,
        'admin@westprime.edu' => UserRole::Admin,
        'it@westprime.edu' => UserRole::It,
        'stakeholder@westprime.edu' => UserRole::Stakeholder,
    ];

    /** @var array<string, array{0: UserRole, 1: string}> presenter's staff accounts: email => [role, employee no] */
    private const AARON_STAFF = [
        'aaron.registrar@westprime.edu' => [UserRole::Registrar, 'DEMO-REG'],
        'aaron.admin@westprime.edu' => [UserRole::Admin, 'DEMO-ADM'],
        'aaron.it@westprime.edu' => [UserRole::It, 'DEMO-IT'],
        'aaron.stakeholder@westprime.edu' => [UserRole::Stakeholder, 'DEMO-STK'],
    ];

    private const AARON_TEACHER_FALLBACK = 'aaron.teacher@westprime.edu';

    private const AARON_STUDENT = 'aaron.student@westprime.edu';

    public function run(): void
    {
        $password = $this->resolvePassword();

        DB::transaction(function () use ($password) {
            $core = $this->ensureCoreStaff($password);
            $aaronStaff = $this->upsertAaronStaff($password);
            $aaronTeacher = $this->resolveAaronTeacher($password);
            $student = $this->upsertAaronStudent($password);
            $this->seedAaronClass($aaronTeacher, $student);

            $keepIds = collect([...$core, ...$aaronStaff, $aaronTeacher, $student])->pluck('id')->all();
            $removed = $this->removeOtherStaff($keepIds, $core[UserRole::Teacher->value], $core[UserRole::It->value]);

            $this->command?->info('Presenter accounts (password: '.$password.'):');
            foreach ([...array_keys(self::AARON_STAFF), $aaronTeacher->email, self::AARON_STUDENT] as $email) {
                $this->command?->line("  {$email}");
            }
            $this->command?->info("Removed {$removed} other non-student account(s). Students and the standard demo accounts were not touched.");
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
     * The standard demo accounts already exist in every environment; they are only created
     * (never modified) when missing so removed-account data always has somewhere to go.
     *
     * @return array<string, User> keyed by role value
     */
    private function ensureCoreStaff(string $password): array
    {
        $users = [];

        foreach (self::CORE_STAFF as $email => $role) {
            $user = User::query()->where('email', $email)->first();

            if (! $user) {
                $user = $this->upsertStaff($role->label().' Account', $email, $role, strtoupper(substr($role->value, 0, 3)).'-001', $password);
            }

            $users[$role->value] = $user;
        }

        return $users;
    }

    /**
     * @return list<User>
     */
    private function upsertAaronStaff(string $password): array
    {
        $users = [];

        foreach (self::AARON_STAFF as $email => [$role, $employeeNo]) {
            $users[] = $this->upsertStaff(self::DEMO_NAME, $email, $role, $employeeNo, $password);
        }

        return $users;
    }

    /**
     * The presenter already has a teacher account on production; reuse it. Create one only if none exists.
     */
    private function resolveAaronTeacher(string $password): User
    {
        $existing = User::query()
            ->where('role', UserRole::Teacher->value)
            ->whereRaw('LOWER(name) = ?', [strtolower(self::DEMO_NAME)])
            ->orderBy('id')
            ->first();

        return $existing ?? $this->upsertStaff(self::DEMO_NAME, self::AARON_TEACHER_FALLBACK, UserRole::Teacher, 'DEMO-TCH', $password);
    }

    private function upsertAaronStudent(string $password): User
    {
        $user = User::query()->where('email', self::AARON_STUDENT)->first() ?? new User(['email' => self::AARON_STUDENT]);
        $user->forceFill([
            'name' => 'LARIDE, AARON N',
            'password' => Hash::make($password),
            'role' => UserRole::Student,
            'is_active' => true,
        ])->save();

        $program = Program::query()->where('code', 'BSIT')->first() ?? Program::query()->orderBy('id')->first();

        StudentProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'student_no' => 'DEMO-LARIDE',
                'academic_level' => AcademicLevel::College,
                'program_id' => $program?->id,
                'year_level' => 3,
                'section' => 'A',
                'last_name' => 'LARIDE',
                'first_name' => 'AARON',
                'middle_name' => 'N',
            ]
        );

        return $user;
    }

    /**
     * Gives the presenter's teacher a class this term with the presenter's student enrolled, so the
     * grade sheet, submission and change-request flows can be demonstrated end to end.
     */
    private function seedAaronClass(User $teacher, User $student): void
    {
        $term = SchoolTerm::query()->where('is_active', true)->first();
        $subject = Subject::query()->where('code', 'CS 232')->first() ?? Subject::query()->orderBy('id')->first();
        $profile = $student->studentProfile;

        if (! $term || ! $subject || ! $profile) {
            $this->command?->warn('No active term or subject found — skipped creating the demo class.');

            return;
        }

        $section = ClassSection::query()->firstOrCreate(
            ['school_term_id' => $term->id, 'subject_id' => $subject->id, 'section' => 'DEMO-A'],
            [
                'teacher_id' => $teacher->id,
                'schedule_time' => '9:00AM - 10:30AM',
                'schedule_day' => 'M-F',
                'room' => 'DEMO ROOM',
            ]
        );

        if ((int) $section->teacher_id !== (int) $teacher->id) {
            $section->update(['teacher_id' => $teacher->id]);
        }

        $admission = Admission::query()->firstOrCreate(
            ['admission_number' => 'DEMO-LARIDE-0001'],
            [
                'student_profile_id' => $profile->id,
                'school_term_id' => $term->id,
                'program_id' => $profile->program_id,
                'year_level' => 3,
                'section' => 'A',
                'status' => 'enrolled',
            ]
        );

        EnrollmentSubject::query()->firstOrCreate([
            'admission_id' => $admission->id,
            'class_section_id' => $section->id,
        ]);
    }

    private function upsertStaff(string $name, string $email, UserRole $role, string $employeeNo, string $password): User
    {
        $user = User::query()->where('email', $email)->first() ?? new User(['email' => $email]);

        $user->forceFill([
            'name' => $name,
            'password' => Hash::make($password),
            'role' => $role,
            'is_active' => true,
        ])->save();

        StaffProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['employee_no' => $employeeNo, 'department' => 'West Prime Horizon Institute', 'position' => $role->label()]
        );

        return $user;
    }

    /**
     * @param  list<int>  $keepIds
     */
    private function removeOtherStaff(array $keepIds, User $demoTeacher, User $demoIt): int
    {
        $doomed = User::query()
            ->where('role', '!=', UserRole::Student->value)
            ->whereNotIn('id', $keepIds)
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
