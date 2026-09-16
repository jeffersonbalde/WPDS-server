<?php

namespace Database\Seeders;

use App\Enums\AcademicLevel;
use App\Enums\UserRole;
use App\Models\Admission;
use App\Models\Announcement;
use App\Models\ClassSection;
use App\Models\EnrollmentSubject;
use App\Models\Grade;
use App\Models\GradeSubmission;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\GradeCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development / demo seed: real master data (via ProductionSeeder)
     * PLUS sample students, class sections, and grades for showing the UI/UX
     * or running the test suite.
     *
     * Never run this against a real school deployment — use
     * `php artisan db:seed --class=ProductionSeeder` there instead, which
     * seeds only the master data and staff accounts, with no fake students.
     */
    public function run(): void
    {
        $this->call(ProductionSeeder::class);

        $programs = Program::all()->keyBy('code')->all();
        $subjects = Subject::all()->keyBy('code')->all();
        $term = SchoolTerm::where('is_active', true)->firstOrFail();

        $users = $this->seedDemoUsers($programs);
        $this->seedClassesAndGrades($term, $programs, $subjects, $users);
        $this->call(DemoStudentsSeeder::class);
        $this->seedActiveTermGrades();
        $this->seedAnnouncements();
        $this->seedNotifications();
    }

    /**
     * Sample teacher <-> registrar notifications so the bell, badges and the
     * Notifications page have realistic content in demos.
     */
    private function seedNotifications(): void
    {
        // Decorative demo data only — keep it out of the test database so
        // notification assertions start from a clean slate.
        if (app()->runningUnitTests()) {
            return;
        }

        $teacher = User::query()->where('email', 'teacher@westprime.edu')->first();
        $registrar = User::query()->where('email', 'registrar@westprime.edu')->first();

        if (! $teacher || ! $registrar) {
            return;
        }

        $rows = [];
        $add = function (User $user, string $type, string $title, string $body, ?string $url, int $minutesAgo, bool $read) use (&$rows) {
            $createdAt = now()->subMinutes($minutesAgo);
            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => SystemNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'action_url' => $url,
                    'meta' => [],
                ]),
                'read_at' => $read ? $createdAt->copy()->addMinutes(3) : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        };

        $add($teacher, 'grade_submission.released', 'Grades released',
            'The registrar released your Prelim grades for CS 116. Students can now see them.',
            '/grade-submissions', 12, false);
        $add($teacher, 'grade_submission.returned', 'Grades returned for correction',
            'The registrar returned your Midterm grades for CS 232. Correct them and submit again. Remarks: "Row 4 average looks off — please recheck."',
            '/classes', 95, false);
        $add($teacher, 'grade_change.approved', 'Grade change approved',
            'Your Final grade change for CS 116 was approved and applied.',
            '/grade-changes', 260, true);
        $add($teacher, 'grade_change.rejected', 'Grade change rejected',
            'Your Prelim grade change for CS 232 was rejected. Remarks: "No supporting document attached."',
            '/grade-changes', 1580, true);

        $add($registrar, 'grade_submission.pending', 'Grades submitted for review',
            'Teacher Account submitted Prelim grades for CS 232 (A) — FIRST SEMESTER, 2024-2025. Review and release or return them.',
            '/grade-submissions-review', 8, false);
        $add($registrar, 'grade_submission.pending', 'Grades submitted for review',
            'Teacher Account submitted Semi-Final grades for CS 116 (A). Review and release or return them.',
            '/grade-submissions-review', 47, false);
        $add($registrar, 'grade_change.pending', 'Grade change request',
            'Teacher Account requested a Final grade change for REYES, SAM in CS 116 (3.00 to 1.25). Approve or reject it.',
            '/grade-approvals', 320, true);

        DB::table('notifications')->insert($rows);
    }

    /**
     * A couple of sample dashboard announcements so the student portal
     * has something to show in demos.
     */
    private function seedAnnouncements(): void
    {
        $registrarId = User::query()->where('role', UserRole::Registrar)->value('id');

        $samples = [
            [
                'title' => 'Grades now available online',
                'body' => 'Grades and academic records are available online. Contact the Registrar for confirmation of official records.',
                'is_published' => true,
                'published_at' => now()->subDays(2),
            ],
            [
                'title' => 'Grade correction requests',
                'body' => 'For grade correction requests, please coordinate first with your subject teacher, then proceed to the Registrar for processing.',
                'is_published' => true,
                'published_at' => now()->subDay(),
            ],
            [
                'title' => 'System maintenance schedule (draft)',
                'body' => 'The portal may be briefly unavailable this weekend for scheduled maintenance. Final schedule to be announced.',
                'is_published' => false,
                'published_at' => null,
            ],
        ];

        foreach ($samples as $sample) {
            Announcement::create($sample + ['created_by' => $registrarId]);
        }
    }

    /**
     * Sets up grade-submission state after the demo ladder is built:
     *  - every locked (historical) grade becomes a released submission so
     *    students keep seeing their past records;
     *  - "FIRST SEMESTER, 2024-2025" is forced active (the model-instance update
     *    in DemoStudentsSeeder can silently no-op);
     *  - the active term gets one fully-released class and one class pending
     *    registrar review, so the queue has something to show on first login.
     */
    private function seedActiveTermGrades(): void
    {
        $registrarId = User::query()->where('role', UserRole::Registrar)->value('id');
        $teacherId = User::query()->where('role', UserRole::Teacher)->value('id');
        $calculator = app(GradeCalculator::class);

        $this->releaseLockedGrades($registrarId, $teacherId);

        $activeTerm = SchoolTerm::query()
            ->where('term_type', 'first_semester')
            ->where('school_year', '2024-2025')
            ->first();

        if (! $activeTerm) {
            return;
        }

        SchoolTerm::query()->update(['is_active' => false]);
        SchoolTerm::query()->whereKey($activeTerm->id)->update(['is_active' => true]);

        $sections = ClassSection::query()
            ->where('school_term_id', $activeTerm->id)
            ->whereHas('enrollmentSubjects')
            ->with(['subject', 'enrollmentSubjects.grade'])
            ->orderBy('id')
            ->get();

        foreach ($sections as $index => $section) {
            if ($index > 1) {
                break;
            }

            $level = $section->subject->academic_level;
            $periods = $index === 0 ? GradeSubmission::PERIODS : ['prelim', 'midterm'];

            foreach ($section->enrollmentSubjects as $offset => $enrollment) {
                $grade = Grade::firstOrCreate(['enrollment_subject_id' => $enrollment->id]);

                foreach ($periods as $periodIndex => $period) {
                    $grade->{$period} = $this->demoPeriodValue($level, (int) $offset, $periodIndex);
                }

                $grade->encoded_by = $teacherId;
                $grade->recalculate($calculator, $level);
                $grade->save();
            }

            $status = $index === 0 ? 'released' : 'pending';

            foreach ($periods as $period) {
                GradeSubmission::updateOrCreate(
                    ['class_section_id' => $section->id, 'period' => $period],
                    [
                        'status' => $status,
                        'submitted_by' => $teacherId,
                        'submitted_at' => now(),
                        'reviewed_by' => $status === 'released' ? $registrarId : null,
                        'reviewed_at' => $status === 'released' ? now() : null,
                        'review_remarks' => null,
                    ]
                );
            }
        }
    }

    private function releaseLockedGrades(?int $registrarId, ?int $teacherId): void
    {
        $lockedGrades = Grade::query()
            ->where('is_locked', true)
            ->with('enrollmentSubject:id,class_section_id')
            ->get(['id', 'prelim', 'midterm', 'semi_final', 'final', 'encoded_by', 'submitted_at', 'enrollment_subject_id']);

        $bySection = [];
        foreach ($lockedGrades as $grade) {
            $sectionId = $grade->enrollmentSubject?->class_section_id;
            if (! $sectionId) {
                continue;
            }

            foreach (GradeSubmission::PERIODS as $period) {
                if ($grade->{$period} === null) {
                    continue;
                }

                $bySection[$sectionId][$period] ??= [
                    'submitted_by' => $grade->encoded_by ?? $teacherId,
                    'submitted_at' => $grade->submitted_at,
                ];
            }
        }

        foreach ($bySection as $sectionId => $periods) {
            foreach ($periods as $period => $meta) {
                GradeSubmission::updateOrCreate(
                    ['class_section_id' => $sectionId, 'period' => $period],
                    [
                        'status' => 'released',
                        'submitted_by' => $meta['submitted_by'],
                        'submitted_at' => $meta['submitted_at'] ?? now(),
                        'reviewed_by' => $registrarId,
                        'reviewed_at' => $meta['submitted_at'] ?? now(),
                        'review_remarks' => null,
                    ]
                );
            }
        }
    }

    private function demoPeriodValue(AcademicLevel $level, int $offset, int $periodIndex): float
    {
        if ($level === AcademicLevel::Shs) {
            return (float) (86 + (($offset + $periodIndex) % 10));
        }

        $scale = [1.00, 1.25, 1.50, 1.75, 2.00, 2.25];

        return $scale[($offset + $periodIndex) % count($scale)];
    }

    /**
     * Sample students only (staff accounts already exist via ProductionSeeder).
     *
     * @param  array<string, Program>  $programs
     * @return array<string, User>
     */
    private function seedDemoUsers(array $programs): array
    {
        $users = ['teacher' => User::where('email', 'teacher@westprime.edu')->firstOrFail()];

        $collegeStudent = User::create([
            'name' => 'BALDE, JEFFERSON S',
            'email' => 'jefferson.balde@westprime.edu',
            'password' => Hash::make('password'),
            'role' => UserRole::Student,
            'is_active' => true,
        ]);
        StudentProfile::create([
            'user_id' => $collegeStudent->id,
            'student_no' => '195367',
            'academic_level' => AcademicLevel::College,
            'program_id' => $programs['BSIT']->id,
            'year_level' => 3,
            'section' => 'A',
            'last_name' => 'BALDE',
            'first_name' => 'JEFFERSON',
            'middle_name' => 'SABANAL',
            'date_of_birth' => '2003-05-13',
            'place_of_birth' => 'DACANAY, SIAY, ZAMBOANGA SIBUGAY',
            'gender' => 'Male',
            'civil_status' => 'Single',
            'address_line_1' => 'LUMBIA, PAGADIAN CITY',
            'mobile' => '09187305985',
        ]);
        $users['student'] = $collegeStudent;

        $shsStudent = User::create([
            'name' => 'SANTOS, MARIA A',
            'email' => 'maria.santos@westprime.edu',
            'password' => Hash::make('password'),
            'role' => UserRole::Student,
            'is_active' => true,
        ]);
        StudentProfile::create([
            'user_id' => $shsStudent->id,
            'student_no' => 'SHS-1001',
            'academic_level' => AcademicLevel::Shs,
            'program_id' => $programs['STEM']->id,
            'year_level' => 1,
            'section' => 'A',
            'last_name' => 'SANTOS',
            'first_name' => 'MARIA',
            'middle_name' => 'A',
            'gender' => 'Female',
            'mobile' => '09171234567',
        ]);
        $users['shs_student'] = $shsStudent;

        // Graduated student — keeps the Student role and read access to records.
        $alumni = User::create([
            'name' => 'CRUZ, JUAN D',
            'email' => 'juan.cruz@westprime.edu',
            'password' => Hash::make('password'),
            'role' => UserRole::Student,
            'is_active' => true,
        ]);
        StudentProfile::create([
            'user_id' => $alumni->id,
            'student_no' => 'ALU-0901',
            'academic_level' => AcademicLevel::College,
            'program_id' => $programs['BSIT']->id,
            'year_level' => 4,
            'section' => 'A',
            'last_name' => 'CRUZ',
            'first_name' => 'JUAN',
            'middle_name' => 'D',
        ]);
        $users['alumni'] = $alumni;

        return $users;
    }

    private function seedClassesAndGrades(SchoolTerm $term, array $programs, array $subjects, array $users): void
    {
        $teacher = $users['teacher'];
        $calculator = app(GradeCalculator::class);

        $collegeSections = [];
        foreach (['CS 116', 'CS 232', 'CSP 107'] as $code) {
            $collegeSections[$code] = ClassSection::create([
                'school_term_id' => $term->id,
                'subject_id' => $subjects[$code]->id,
                'teacher_id' => $teacher->id,
                'section' => 'A',
                'schedule_time' => '1:00PM - 2:50PM',
                'schedule_day' => 'M-F',
                'room' => 'PC LAB-A',
            ]);
        }

        $student = $users['student']->studentProfile;
        $admission = Admission::create([
            'admission_number' => '20253190',
            'student_profile_id' => $student->id,
            'school_term_id' => $term->id,
            'program_id' => $programs['BSIT']->id,
            'year_level' => 3,
            'section' => 'A',
            'status' => 'enrolled',
        ]);

        $sampleGrades = [
            'CS 116' => [1.25, 1.50, 1.25, 1.50],
            'CS 232' => [1.00, 1.00, 1.25, 1.00],
            'CSP 107' => [1.75, 2.00, 1.50, 1.75],
        ];

        foreach ($collegeSections as $code => $section) {
            $enrollment = EnrollmentSubject::create([
                'admission_id' => $admission->id,
                'class_section_id' => $section->id,
            ]);
            [$p, $m, $s, $f] = $sampleGrades[$code];
            $grade = Grade::create([
                'enrollment_subject_id' => $enrollment->id,
                'prelim' => $p,
                'midterm' => $m,
                'semi_final' => $s,
                'final' => $f,
                'is_locked' => true,
                'encoded_by' => $teacher->id,
                'submitted_at' => now(),
            ]);
            $grade->recalculate($calculator, AcademicLevel::College);
            $grade->save();
        }

        $shsSection = ClassSection::create([
            'school_term_id' => $term->id,
            'subject_id' => $subjects['MATH 1']->id,
            'teacher_id' => $teacher->id,
            'section' => 'A',
            'schedule_time' => '8:00AM - 9:00AM',
            'schedule_day' => 'MWF',
            'room' => 'ROOM 201',
        ]);

        $shsAdmission = Admission::create([
            'admission_number' => 'SHS-2025001',
            'student_profile_id' => $users['shs_student']->studentProfile->id,
            'school_term_id' => $term->id,
            'program_id' => $programs['STEM']->id,
            'year_level' => 1,
            'section' => 'A',
            'status' => 'enrolled',
        ]);

        $shsEnrollment = EnrollmentSubject::create([
            'admission_id' => $shsAdmission->id,
            'class_section_id' => $shsSection->id,
        ]);
        $shsGrade = Grade::create([
            'enrollment_subject_id' => $shsEnrollment->id,
            'prelim' => 88,
            'midterm' => 90,
            'semi_final' => 85,
            'final' => 92,
            'is_locked' => false,
            'encoded_by' => $teacher->id,
        ]);
        $shsGrade->recalculate($calculator, AcademicLevel::Shs);
        $shsGrade->save();

        // Graduated student's historical admission (full 1st–4th year record).
        $alumniAdmission = Admission::create([
            'admission_number' => '20201234',
            'student_profile_id' => $users['alumni']->studentProfile->id,
            'school_term_id' => $term->id,
            'program_id' => $programs['BSIT']->id,
            'year_level' => 4,
            'section' => 'A',
            'status' => 'completed',
        ]);
        $alumniEnrollment = EnrollmentSubject::create([
            'admission_id' => $alumniAdmission->id,
            'class_section_id' => $collegeSections['CS 116']->id,
        ]);
        $alumniGrade = Grade::create([
            'enrollment_subject_id' => $alumniEnrollment->id,
            'prelim' => 1.50,
            'midterm' => 1.25,
            'semi_final' => 1.50,
            'final' => 1.25,
            'is_locked' => true,
            'encoded_by' => $teacher->id,
            'submitted_at' => now(),
        ]);
        $alumniGrade->recalculate($calculator, AcademicLevel::College);
        $alumniGrade->save();
    }
}
