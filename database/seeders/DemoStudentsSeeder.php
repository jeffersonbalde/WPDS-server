<?php

namespace Database\Seeders;

use App\Enums\AcademicLevel;
use App\Enums\UserRole;
use App\Models\Admission;
use App\Models\ClassSection;
use App\Models\EnrollmentSubject;
use App\Models\Grade;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\GradeCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoStudentsSeeder extends Seeder
{
    /**
     * Seed extra students for pagination / filter testing.
     * Also seeds SCC-style admissions (1st/2nd sem + occasional summer per year)
     * so Student Record → Admissions can be demoed.
     * Safe to re-run for students; rebuilds term admissions when called.
     */
    public function run(): void
    {
        $collegePrograms = Program::query()
            ->where('academic_level', AcademicLevel::College)
            ->with('majors')
            ->orderBy('code')
            ->get();
        $shsPrograms = Program::query()
            ->where('academic_level', AcademicLevel::Shs)
            ->with('majors')
            ->orderBy('code')
            ->get();

        if ($collegePrograms->isEmpty() && $shsPrograms->isEmpty()) {
            $this->command?->warn('No programs found. Run DatabaseSeeder first.');

            return;
        }

        $lastNames = [
            'REYES', 'GARCIA', 'MENDOZA', 'TORRES', 'RAMOS', 'FLORES', 'GONZALES',
            'CASTILLO', 'DOMINGO', 'VILLANUEVA', 'AQUINO', 'NAVARRO', 'SANTIAGO',
            'DELA CRUZ', 'FERNANDEZ', 'MORALES', 'PAGADUAN', 'LIM', 'TAN', 'UY',
            'ALVAREZ', 'BAUTISTA', 'CORPUZ', 'DIAZ', 'ESPIRITU', 'FRANCISCO',
            'GUTIERREZ', 'HERNANDEZ', 'IGNACIO', 'JAVIER', 'KATIGBAK', 'LOPEZ',
            'MARTINEZ', 'NUNEZ', 'ORTIZ', 'PEREZ', 'QUIJANO', 'RIVERA', 'SORIANO',
            'VALDEZ', 'WONG', 'XAVIER', 'YAP', 'ZAMORA',
        ];

        $firstNames = [
            'ANA', 'BEN', 'CARLA', 'DANIEL', 'ELENA', 'FRANCIS', 'GINA', 'HAROLD',
            'IRIS', 'JAKE', 'KAREN', 'LEO', 'MIA', 'NICO', 'OLGA', 'PAULO',
            'QUEENIE', 'RYAN', 'SARA', 'TONY', 'URSULA', 'VICTOR', 'WENDY', 'XANDER',
            'YNA', 'ZION', 'ANDREA', 'BRIAN', 'CHLOE', 'DIEGO', 'EMMA', 'FELIX',
            'GRACE', 'HENRY', 'IVY', 'JOSH', 'KYLA', 'LANCE', 'MARA', 'NOEL',
            'OWEN', 'PAT', 'QUINN', 'ROSE', 'SAM', 'TESS', 'URIEL', 'VERA',
            'WILL', 'ZOE',
        ];

        $middles = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'L', 'M', 'N', 'P', 'R', 'S'];
        $year = (int) now()->format('Y');
        $created = 0;
        $target = 45;

        DB::transaction(function () use (
            $collegePrograms,
            $shsPrograms,
            $lastNames,
            $firstNames,
            $middles,
            $year,
            &$created,
            $target
        ) {
            for ($i = 1; $i <= $target; $i++) {
                $isShs = $i % 4 === 0 && $shsPrograms->isNotEmpty();
                $level = $isShs ? AcademicLevel::Shs : AcademicLevel::College;
                $programs = $isShs ? $shsPrograms : $collegePrograms;
                if ($programs->isEmpty()) {
                    $level = AcademicLevel::College;
                    $programs = $collegePrograms;
                    $isShs = false;
                }

                $program = $programs[($i - 1) % $programs->count()];
                $activeMajors = $program->majors->where('is_active', true)->values();
                $major = $activeMajors->isNotEmpty()
                    ? $activeMajors[($i - 1) % $activeMajors->count()]
                    : null;
                $last = $lastNames[($i - 1) % count($lastNames)];
                $first = $firstNames[($i - 1) % count($firstNames)];
                $middle = $middles[($i - 1) % count($middles)];
                $seq = str_pad((string) (100 + $i), 4, '0', STR_PAD_LEFT);
                $studentNo = $isShs ? "SHS-{$year}-{$seq}" : "{$year}-{$seq}";
                $login = strtolower(str_replace(' ', '', $first)).'.'.strtolower(str_replace(' ', '', $last)).$i.'@westprime.edu';

                if (User::where('email', $login)->exists() || StudentProfile::where('student_no', $studentNo)->exists()) {
                    continue;
                }

                $user = User::create([
                    'name' => "{$last}, {$first} {$middle}",
                    'email' => $login,
                    'password' => Hash::make('password'),
                    'role' => UserRole::Student,
                    'is_active' => $i % 11 !== 0,
                ]);

                StudentProfile::create([
                    'user_id' => $user->id,
                    'student_no' => $studentNo,
                    'academic_level' => $level,
                    'program_id' => $program->id,
                    'program_major_id' => $major?->id,
                    'year_level' => $isShs ? 2 : 4,
                    'section' => chr(65 + (($i - 1) % 3)),
                    'last_name' => $last,
                    'first_name' => $first,
                    'middle_name' => $middle,
                    'date_of_birth' => sprintf('200%u-%02d-%02d', $i % 5, (($i - 1) % 12) + 1, (($i - 1) % 27) + 1),
                    'place_of_birth' => 'PAGADIAN CITY',
                    'gender' => $i % 2 === 0 ? 'Female' : 'Male',
                    'civil_status' => 'Single',
                    'address_line_1' => 'PAGADIAN CITY',
                    'address_line_2' => 'ZAMBOANGA DEL SUR',
                    'mobile' => '09'.str_pad((string) (100000000 + $i), 9, '0', STR_PAD_LEFT),
                    'telephone' => '062-'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT),
                    'contact_email' => $login,
                    'ethnic_origin' => 'Filipino',
                    'religion' => 'Roman Catholic',
                    'educational_background' => [
                        'primary_school' => 'Demo Elementary School',
                        'junior_high_school' => 'Demo Junior High School',
                        'senior_high_school' => 'Demo Senior High School',
                        'transferred_from' => 'N/A',
                    ],
                    'parents_guardian' => [
                        'father' => [
                            'name' => "FATHER OF {$first}",
                            'occupation' => 'Employee',
                            'company' => 'Demo Company',
                            'contact' => '09171110001',
                            'email' => 'father'.$i.'@example.com',
                        ],
                        'mother' => [
                            'name' => "MOTHER OF {$first}",
                            'occupation' => 'Employee',
                            'company' => 'Demo Company',
                            'contact' => '09171110002',
                            'email' => 'mother'.$i.'@example.com',
                        ],
                        'guardian' => [
                            'name' => "GUARDIAN OF {$first}",
                            'occupation' => 'Guardian',
                            'company' => 'N/A',
                            'contact' => '09171110003',
                            'email' => 'guardian'.$i.'@example.com',
                        ],
                    ],
                ]);

                $created++;
            }
        });

        $this->command?->info("Created {$created} demo students (password: password).");

        $admissionsCreated = $this->seedTermAdmissions();
        $this->command?->info("Created {$admissionsCreated} term admissions (1st/2nd sem + summer).");
    }

    /**
     * Rebuild SCC-style admissions:
     * College year 1–4 × (1st sem, 2nd sem, optional summer)
     * SHS year 1–2 × (1st sem, 2nd sem)
     */
    private function seedTermAdmissions(): int
    {
        $collegePrograms = Program::query()
            ->where('academic_level', AcademicLevel::College)
            ->with('majors')
            ->orderBy('code')
            ->get();
        $shsPrograms = Program::query()
            ->where('academic_level', AcademicLevel::Shs)
            ->with('majors')
            ->orderBy('code')
            ->get();

        if ($collegePrograms->isEmpty() && $shsPrograms->isEmpty()) {
            return 0;
        }

        $teacherId = User::query()->where('role', UserRole::Teacher)->value('id')
            ?? User::query()->where('role', 'teacher')->value('id');

        // School years mapped to year levels (demo ladder ending current SY).
        $schoolYears = [
            1 => '2021-2022',
            2 => '2022-2023',
            3 => '2023-2024',
            4 => '2024-2025',
        ];

        $termDefs = [
            ['type' => 'first_semester', 'label' => 'FIRST SEMESTER'],
            ['type' => 'second_semester', 'label' => 'SECOND SEMESTER'],
            ['type' => 'summer', 'label' => 'SUMMER'],
        ];

        $terms = []; // [yearLevel][termType] => SchoolTerm
        foreach ($schoolYears as $yearLevel => $sy) {
            foreach ($termDefs as $def) {
                $name = "{$def['label']}, {$sy}";
                $terms[$yearLevel][$def['type']] = SchoolTerm::query()->updateOrCreate(
                    [
                        'school_year' => $sy,
                        'term_type' => $def['type'],
                    ],
                    [
                        'name' => $name,
                        'is_active' => $yearLevel === 4 && $def['type'] === 'first_semester',
                    ]
                );
            }
        }

        SchoolTerm::query()->update(['is_active' => false]);
        $terms[4]['first_semester']->update(['is_active' => true]);

        $sourceSections = ClassSection::query()->with('subject')->orderBy('id')->get();
        if ($sourceSections->isEmpty()) {
            $this->command?->warn('No class sections found. Admissions will have no enrolled subjects.');
        }

        $sectionsByTermId = [];
        foreach ($terms as $yearLevel => $byType) {
            foreach ($byType as $term) {
                $existing = ClassSection::query()
                    ->with('subject')
                    ->where('school_term_id', $term->id)
                    ->orderBy('id')
                    ->get();

                if ($existing->isEmpty() && $sourceSections->isNotEmpty()) {
                    foreach ($sourceSections as $src) {
                        ClassSection::firstOrCreate(
                            [
                                'school_term_id' => $term->id,
                                'subject_id' => $src->subject_id,
                                'section' => $src->section ?: 'A',
                            ],
                            [
                                'teacher_id' => $src->teacher_id ?: $teacherId,
                                'schedule_time' => $src->schedule_time ?: '8:00AM - 9:30AM',
                                'schedule_day' => $src->schedule_day ?: 'MWF',
                                'room' => $src->room ?: 'ROOM 101',
                            ]
                        );
                    }
                    $existing = ClassSection::query()
                        ->with('subject')
                        ->where('school_term_id', $term->id)
                        ->orderBy('id')
                        ->get();
                }

                $sectionsByTermId[$term->id] = $existing;
            }
        }

        $created = 0;
        $calculator = app(GradeCalculator::class);

        DB::transaction(function () use (
            $collegePrograms,
            $shsPrograms,
            $terms,
            $sectionsByTermId,
            $teacherId,
            $calculator,
            &$created
        ) {
            // Clear previous admissions (demo rebuild). Keep grade change requests empty.
            Grade::query()->delete();
            EnrollmentSubject::query()->delete();
            Admission::query()->delete();

            $students = StudentProfile::query()->orderBy('id')->get();

            foreach ($students as $index => $student) {
                $isShs = $student->academic_level === AcademicLevel::Shs
                    || $student->academic_level === 'shs'
                    || (is_object($student->academic_level) && ($student->academic_level->value ?? null) === 'shs');

                $levelEnum = $isShs ? AcademicLevel::Shs : AcademicLevel::College;

                $programId = $student->program_id;
                $majorId = $student->program_major_id;
                if (! $programId) {
                    $fallback = $isShs ? $shsPrograms->first() : $collegePrograms->first();
                    $programId = $fallback?->id;
                    $majorId = $fallback?->majors->firstWhere('is_active', true)?->id
                        ?? $fallback?->majors->first()?->id;
                    if (! $programId) {
                        continue;
                    }
                    $student->update([
                        'program_id' => $programId,
                        'program_major_id' => $majorId,
                    ]);
                }

                $maxYear = $isShs ? 2 : 4;
                $student->update([
                    'year_level' => $maxYear,
                    'section' => $student->section ?: 'A',
                ]);

                $sectionLetter = $student->section ?: 'A';

                for ($yearLevel = 1; $yearLevel <= $maxYear; $yearLevel++) {
                    $termTypes = ['first_semester', 'second_semester'];
                    // ~every 3rd student took summer for this year (college only).
                    if (! $isShs && ($index + $yearLevel) % 3 === 0) {
                        $termTypes[] = 'summer';
                    }

                    foreach ($termTypes as $termType) {
                        $term = $terms[$yearLevel][$termType] ?? null;
                        if (! $term) {
                            continue;
                        }

                        $syStart = (int) explode('-', $term->school_year)[0];
                        $admissionNumber = sprintf('%d%04d', $syStart, ($student->id * 10) + ($yearLevel * 3) + (match ($termType) {
                            'first_semester' => 1,
                            'second_semester' => 2,
                            default => 3,
                        }));
                        while (Admission::where('admission_number', $admissionNumber)->exists()) {
                            $admissionNumber = (string) ((int) $admissionNumber + 17);
                        }

                        if ($yearLevel === $maxYear && $termType === 'second_semester') {
                            // Later term not opened yet — skip admission for demo data.
                            continue;
                        }

                        $isLatest = $yearLevel === $maxYear && $termType === 'first_semester';
                        $status = $isLatest ? 'enrolled' : 'completed';

                        $admission = Admission::create([
                            'admission_number' => $admissionNumber,
                            'student_profile_id' => $student->id,
                            'school_term_id' => $term->id,
                            'program_id' => $programId,
                            'program_major_id' => $majorId,
                            'year_level' => $yearLevel,
                            'section' => $sectionLetter,
                            'status' => $status,
                        ]);
                        $created++;

                        $sections = $sectionsByTermId[$term->id] ?? collect();
                        $levelSections = $sections->filter(function ($cs) use ($isShs) {
                            $level = $cs->subject?->academic_level;
                            $value = is_object($level) ? ($level->value ?? null) : $level;

                            return $isShs
                                ? ($value === 'shs' || $level === AcademicLevel::Shs)
                                : ($value === 'college' || $level === AcademicLevel::College);
                        })->values();

                        if ($levelSections->isEmpty()) {
                            $levelSections = $sections->values();
                        }

                        $take = min($levelSections->count(), $termType === 'summer' ? 1 : min(3, ($yearLevel % 3) + 1));
                        foreach ($levelSections->take($take) as $si => $classSection) {
                            $enrollment = EnrollmentSubject::create([
                                'admission_id' => $admission->id,
                                'class_section_id' => $classSection->id,
                            ]);

                            // Sample grades for completed terms only (full period + final grade + remarks).
                            if ($status === 'completed' && $teacherId) {
                                $failDemo = ($index + $yearLevel + $si) % 17 === 0;

                                if ($isShs) {
                                    $prelim = $failDemo ? 70.0 : (float) (85 + ($index % 10));
                                    $midterm = $failDemo ? 72.0 : (float) (88 + ($index % 8));
                                    $semi = $failDemo ? 68.0 : (float) (86 + ($index % 7));
                                    $final = $failDemo ? 71.0 : (float) (90 + ($index % 5));
                                } else {
                                    $prelim = $failDemo ? 3.50 : round(1.25 + (($index % 4) * 0.25), 2);
                                    $midterm = $failDemo ? 3.25 : round(1.50 + (($index % 3) * 0.25), 2);
                                    $semi = $failDemo ? 3.00 : round(1.25 + (($index % 5) * 0.25), 2);
                                    $final = $failDemo ? 3.50 : round(1.00 + (($index % 4) * 0.25), 2);
                                }

                                $grade = Grade::create([
                                    'enrollment_subject_id' => $enrollment->id,
                                    'prelim' => $prelim,
                                    'midterm' => $midterm,
                                    'semi_final' => $semi,
                                    'final' => $final,
                                    'is_locked' => true,
                                    'encoded_by' => $teacherId,
                                    'submitted_at' => now()->subMonths(max(1, 5 - $yearLevel)),
                                ]);
                                $grade->recalculate($calculator, $levelEnum);
                                $grade->save();
                            }
                        }
                    }
                }
            }
        });

        return $created;
    }
}
