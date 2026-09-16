<?php

namespace Database\Seeders;

use App\Enums\AcademicLevel;
use App\Enums\UserRole;
use App\Models\CurriculumItem;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StaffProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Safe to run against a real school deployment: master data (programs,
 * subjects, curriculum, one active school term) plus one placeholder staff
 * account per non-student role. Creates NO students, class sections, or
 * sample grades — the Registrar enrolls real students through the portal
 * after go-live.
 *
 * Run with: `php artisan db:seed --class=ProductionSeeder`
 *
 * IMPORTANT: every account below uses the password "password". Log in as
 * each one immediately after seeding and change the name, email, and
 * password via the portal (IT → User Management for staff, or each user's
 * own profile once self-service password change exists).
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $programs = $this->seedPrograms();
        $subjects = $this->seedSubjects();
        $this->seedCurriculum($programs, $subjects);
        $this->seedInitialTerm();
        $this->seedStaffAccounts();

        $this->command?->info('Production seed complete: programs, subjects, curriculum, one active term, and 5 staff accounts.');
        $this->command?->warn('All staff accounts use the password "password" — change every one of them now.');
    }

    /**
     * @return array<string, Program>
     */
    private function seedPrograms(): array
    {
        $defs = [
            ['GAS', 'General Academic Strand', 'shs', 'academic', 2, []],
            ['HUMSS', 'Humanities and Social Sciences', 'shs', 'academic', 2, []],
            ['ABM', 'Accountancy, Business and Management', 'shs', 'academic', 2, []],
            ['STEM', 'Science, Technology, Engineering and Mathematics', 'shs', 'academic', 2, []],
            ['CSS', 'Computer Systems Servicing (NC II)', 'shs', 'tvl', 2, []],
            ['SMAW', 'Shielded Metal Arc Welding (NC II)', 'shs', 'tvl', 2, []],
            ['EIM', 'Electrical Installation and Maintenance (NC II)', 'shs', 'tvl', 2, []],
            ['AS', 'Automotive Servicing (NC II)', 'shs', 'tvl', 2, []],
            ['BSIT', 'Bachelor of Science in Information Technology', 'college', 'degree', 4, []],
            ['BTVTED', 'Bachelor of Technical Vocational Teacher Education', 'college', 'degree', 4, [
                ['CHS', 'Computer Hardware Servicing'],
                ['WAFT', 'Welding and Fabrication Technology'],
            ]],
            ['BAELS', 'Bachelor of Arts in English Language Studies', 'college', 'degree', 4, []],
            ['AIT', 'Associate in Information Technology', 'college', 'associate', 2, []],
        ];

        $map = [];
        foreach ($defs as [$code, $name, $level, $track, $years, $majors]) {
            $program = Program::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'academic_level' => $level,
                    'track_type' => $track,
                    'duration_years' => $years,
                    'is_active' => true,
                ]
            );

            foreach ($majors as $index => [$majorCode, $majorName]) {
                $program->majors()->updateOrCreate(
                    ['name' => $majorName],
                    [
                        'code' => $majorCode,
                        'sort_order' => $index,
                        'is_active' => true,
                    ]
                );
            }

            $map[$code] = $program->load('majors');
        }

        return $map;
    }

    /**
     * @return array<string, Subject>
     */
    private function seedSubjects(): array
    {
        $college = [
            ['ITE 111', 'INTRODUCTION TO COMPUTING', 3],
            ['ITE 112', 'COMPUTER PROGRAMMING 1', 3],
            ['GE 1', 'UNDERSTANDING THE SELF', 3],
            ['CS 116', 'NEURAL NETWORKS', 3],
            ['CS 232', 'SOFTWARE ENGINEERING 1', 3],
            ['CSP 107', 'GAME DEVELOPMENT', 3],
        ];

        $shs = [
            ['ENG 1', 'ORAL COMMUNICATION', 0],
            ['MATH 1', 'GENERAL MATHEMATICS', 0],
            ['SCI 1', 'EARTH AND LIFE SCIENCE', 0],
            ['CSS 1', 'COMPUTER SYSTEMS SERVICING 1', 0],
        ];

        $map = [];
        foreach ($college as [$code, $title, $units]) {
            $map[$code] = Subject::updateOrCreate(
                ['code' => $code, 'academic_level' => AcademicLevel::College],
                ['title' => $title, 'units' => $units]
            );
        }
        foreach ($shs as [$code, $title, $units]) {
            $map[$code] = Subject::updateOrCreate(
                ['code' => $code, 'academic_level' => AcademicLevel::Shs],
                ['title' => $title, 'units' => $units]
            );
        }

        return $map;
    }

    /**
     * @param  array<string, Program>  $programs
     * @param  array<string, Subject>  $subjects
     */
    private function seedCurriculum(array $programs, array $subjects): void
    {
        $bsit = $programs['BSIT'];
        foreach (['ITE 111', 'ITE 112', 'GE 1'] as $code) {
            CurriculumItem::firstOrCreate([
                'program_id' => $bsit->id,
                'subject_id' => $subjects[$code]->id,
                'year_level' => 1,
                'semester' => 1,
            ]);
        }
        foreach (['CS 116', 'CS 232', 'CSP 107'] as $code) {
            CurriculumItem::firstOrCreate([
                'program_id' => $bsit->id,
                'subject_id' => $subjects[$code]->id,
                'year_level' => 3,
                'semester' => 1,
            ]);
        }

        $stem = $programs['STEM'];
        foreach (['ENG 1', 'MATH 1', 'SCI 1'] as $code) {
            CurriculumItem::firstOrCreate([
                'program_id' => $stem->id,
                'subject_id' => $subjects[$code]->id,
                'year_level' => 1,
                'semester' => 1,
            ]);
        }
    }

    /**
     * One active term for the current Philippine school year (roughly June
     * to May). The Registrar can rename it or add more terms afterward.
     */
    private function seedInitialTerm(): void
    {
        $startYear = now()->month >= 6 ? (int) now()->format('Y') : (int) now()->format('Y') - 1;
        $schoolYear = "{$startYear}-".($startYear + 1);

        SchoolTerm::query()->update(['is_active' => false]);

        SchoolTerm::updateOrCreate(
            ['term_type' => 'first_semester', 'school_year' => $schoolYear],
            ['name' => "FIRST SEMESTER, {$schoolYear}", 'is_active' => true]
        );
    }

    private function seedStaffAccounts(): void
    {
        $defs = [
            ['Teacher Account', 'teacher@westprime.edu', UserRole::Teacher, 'TCH-001'],
            ['Registrar Account', 'registrar@westprime.edu', UserRole::Registrar, 'REG-001'],
            ['Admin Account', 'admin@westprime.edu', UserRole::Admin, 'ADM-001'],
            ['IT Account', 'it@westprime.edu', UserRole::It, 'IT-001'],
            ['Stakeholder Account', 'stakeholder@westprime.edu', UserRole::Stakeholder, 'STK-001'],
        ];

        foreach ($defs as [$name, $email, $role, $emp]) {
            if (User::where('email', $email)->exists()) {
                continue;
            }

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]);

            StaffProfile::create([
                'user_id' => $user->id,
                'employee_no' => $emp,
                'department' => 'West Prime Horizon Institute',
                'position' => $role->label(),
            ]);
        }
    }
}
