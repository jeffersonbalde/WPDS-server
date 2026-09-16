<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DualProgramStudentSeeder;
use Illuminate\Console\Command;

class SeedJeffersonDualProgramCommand extends Command
{
    protected $signature = 'wpds:seed-jefferson-dual-program
                            {--email=jefferson.balde@westprime.edu : Student login email}
                            {--status : Show current programs only; do not seed}';

    protected $description = 'Add a prior AIT admission for Jefferson so Course Curriculum shows BSIT + AIT.';

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $user = User::query()->where('email', $email)->with(['studentProfile.program', 'studentProfile.admissions.program'])->first();

        if (! $user) {
            $this->error("No user found with email {$email}.");
            $this->line('Create the student first, or pass --email=...');

            return self::FAILURE;
        }

        if (! $user->studentProfile) {
            $this->error("{$email} exists but has no student profile.");

            return self::FAILURE;
        }

        $this->printStatus($user);

        if ($this->option('status')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Running DualProgramStudentSeeder…');

        $this->call('db:seed', [
            '--class' => DualProgramStudentSeeder::class,
            '--force' => true,
        ]);

        $user->refresh()->load(['studentProfile.program', 'studentProfile.admissions.program']);
        $this->newLine();
        $this->info('After seed:');
        $this->printStatus($user);

        $programCount = $this->programCount($user);
        if ($programCount < 2) {
            $this->warn('Still only one program — check that AIT exists in programs table and re-run.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Done. Log in as the student, open Course Curriculum, and use the Course dropdown.');

        return self::SUCCESS;
    }

    private function printStatus(User $user): void
    {
        $profile = $user->studentProfile;
        $rows = [];

        foreach ($profile->admissions as $admission) {
            $rows[] = [
                $admission->program?->code ?? '—',
                $admission->admission_number,
                $admission->status,
                $admission->school_term_id,
            ];
        }

        $this->line("Student: {$user->name} ({$user->email})");
        $this->line('Current program: '.($profile->program?->code ?? '—'));
        $this->line('Programs on record (admissions + profile): '.$this->programCount($user));

        if ($rows === []) {
            $this->warn('No admissions found for this student.');
        } else {
            $this->table(['Program', 'Admission #', 'Status', 'Term ID'], $rows);
        }
    }

    private function programCount(User $user): int
    {
        $profile = $user->studentProfile;

        return $profile->admissions
            ->pluck('program_id')
            ->push($profile->program_id)
            ->filter()
            ->unique()
            ->count();
    }
}
