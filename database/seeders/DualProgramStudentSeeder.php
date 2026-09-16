<?php

namespace Database\Seeders;

use App\Enums\AcademicLevel;
use App\Models\Admission;
use App\Models\ClassSection;
use App\Models\CurriculumItem;
use App\Models\EnrollmentSubject;
use App\Models\Grade;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\Subject;
use App\Models\User;
use App\Services\GradeCalculator;
use Illuminate\Database\Seeder;

/**
 * Gives jefferson.balde@westprime.edu a prior AIT admission in addition to
 * current BSIT so Course Curriculum can demonstrate the multi-course picker.
 * Safe to re-run (idempotent).
 */
class DualProgramStudentSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', 'jefferson.balde@westprime.edu')->first();
        if (! $user?->studentProfile) {
            $this->command?->warn('jefferson.balde@westprime.edu not found — skip DualProgramStudentSeeder.');

            return;
        }

        $profile = $user->studentProfile;
        $bsit = Program::query()->where('code', 'BSIT')->first();
        $ait = Program::query()->where('code', 'AIT')->first();

        if (! $bsit || ! $ait) {
            $this->command?->warn('BSIT/AIT programs missing — skip DualProgramStudentSeeder.');

            return;
        }

        // Keep BSIT as the current program on the profile.
        if ((int) $profile->program_id !== (int) $bsit->id) {
            $profile->update(['program_id' => $bsit->id, 'academic_level' => AcademicLevel::College]);
        }

        $this->ensureAitCurriculum($ait);
        $priorTerm = $this->ensurePriorTerm();

        $alreadyHasAit = $profile->admissions()
            ->where('program_id', $ait->id)
            ->exists();

        if ($alreadyHasAit) {
            $this->command?->info('jefferson.balde already has an AIT admission — curriculum picker ready.');

            return;
        }

        $teacher = User::query()->where('email', 'teacher@westprime.edu')->first();
        $calculator = app(GradeCalculator::class);

        $admission = Admission::create([
            'admission_number' => 'AIT-2020-BALDE',
            'student_profile_id' => $profile->id,
            'school_term_id' => $priorTerm->id,
            'program_id' => $ait->id,
            'year_level' => 1,
            'section' => 'A',
            'status' => 'completed',
        ]);

        foreach (['ITE 111', 'GE 1'] as $i => $code) {
            $subject = Subject::query()
                ->where('code', $code)
                ->where('academic_level', AcademicLevel::College)
                ->first();
            if (! $subject) {
                continue;
            }

            $section = ClassSection::firstOrCreate(
                [
                    'school_term_id' => $priorTerm->id,
                    'subject_id' => $subject->id,
                    'section' => 'AIT-A',
                ],
                [
                    'teacher_id' => $teacher?->id,
                    'schedule_time' => '9:00AM - 10:30AM',
                    'schedule_day' => 'TTH',
                    'room' => 'LAB-AIT',
                ]
            );

            $enrollment = EnrollmentSubject::firstOrCreate([
                'admission_id' => $admission->id,
                'class_section_id' => $section->id,
            ]);

            if (! $enrollment->grade()->exists()) {
                $grade = Grade::create([
                    'enrollment_subject_id' => $enrollment->id,
                    'prelim' => 1.50 + ($i * 0.25),
                    'midterm' => 1.25 + ($i * 0.25),
                    'semi_final' => 1.50,
                    'final' => 1.25 + ($i * 0.25),
                    'is_locked' => true,
                    'encoded_by' => $teacher?->id,
                    'submitted_at' => now()->subYears(2),
                ]);
                $grade->recalculate($calculator, AcademicLevel::College);
                $grade->save();
            }
        }

        $this->command?->info('Seeded prior AIT admission for jefferson.balde@westprime.edu (current program remains BSIT).');
        $this->command?->info('Login as student and open Course Curriculum — use the Course dropdown to switch BSIT / AIT.');
    }

    private function ensureAitCurriculum(Program $ait): void
    {
        $map = [
            [1, 1, 'ITE 111'],
            [1, 1, 'GE 1'],
            [1, 2, 'ITE 112'],
            [2, 1, 'CS 232'],
            [2, 1, 'CSP 107'],
        ];

        foreach ($map as [$year, $sem, $code]) {
            $subject = Subject::query()
                ->where('code', $code)
                ->where('academic_level', AcademicLevel::College)
                ->first();
            if (! $subject) {
                continue;
            }

            CurriculumItem::firstOrCreate([
                'program_id' => $ait->id,
                'subject_id' => $subject->id,
                'year_level' => $year,
                'semester' => $sem,
            ]);
        }
    }

    private function ensurePriorTerm(): SchoolTerm
    {
        return SchoolTerm::firstOrCreate(
            [
                'term_type' => 'first_semester',
                'school_year' => '2020-2021',
            ],
            [
                'name' => 'FIRST SEMESTER, 2020-2021',
                'is_active' => false,
            ]
        );
    }
}
