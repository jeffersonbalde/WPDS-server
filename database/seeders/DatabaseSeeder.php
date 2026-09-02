<?php

namespace Database\Seeders;

use App\Enums\AcademicLevel;
use App\Enums\UserRole;
use App\Models\Admission;
use App\Models\ClassSection;
use App\Models\CurriculumItem;
use App\Models\EnrollmentSubject;
use App\Models\Grade;
use App\Models\Program;
use App\Models\SchoolTerm;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use App\Services\GradeCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $programs = $this->seedPrograms();
        $subjects = $this->seedSubjects();
        $this->seedCurriculum($programs, $subjects);
        $term = SchoolTerm::create([
            'name' => 'FIRST SEMESTER, 2025-2026',
            'term_type' => 'first_semester',
            'school_year' => '2025-2026',
            'is_active' => true,
        ]);

        $users = $this->seedUsers($programs);
        $this->seedClassesAndGrades($term, $programs, $subjects, $users);
        $this->call(DemoStudentsSeeder::class);
    }

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
            $map[$code] = Subject::create([
                'code' => $code,
                'title' => $title,
                'units' => $units,
                'academic_level' => AcademicLevel::College,
            ]);
        }
        foreach ($shs as [$code, $title, $units]) {
            $map[$code] = Subject::create([
                'code' => $code,
                'title' => $title,
                'units' => $units,
                'academic_level' => AcademicLevel::Shs,
            ]);
        }

        return $map;
    }

    private function seedCurriculum(array $programs, array $subjects): void
    {
        $bsit = $programs['BSIT'];
        foreach (['ITE 111', 'ITE 112', 'GE 1'] as $i => $code) {
            CurriculumItem::create([
                'program_id' => $bsit->id,
                'subject_id' => $subjects[$code]->id,
                'year_level' => 1,
                'semester' => 1,
            ]);
        }
        foreach (['CS 116', 'CS 232', 'CSP 107'] as $code) {
            CurriculumItem::create([
                'program_id' => $bsit->id,
                'subject_id' => $subjects[$code]->id,
                'year_level' => 3,
                'semester' => 1,
            ]);
        }

        $stem = $programs['STEM'];
        foreach (['ENG 1', 'MATH 1', 'SCI 1'] as $code) {
            CurriculumItem::create([
                'program_id' => $stem->id,
                'subject_id' => $subjects[$code]->id,
                'year_level' => 1,
                'semester' => 1,
            ]);
        }
    }

    private function seedUsers(array $programs): array
    {
        $defs = [
            ['Teacher Demo', 'teacher@westprime.edu', UserRole::Teacher, 'TCH-001'],
            ['Registrar Demo', 'registrar@westprime.edu', UserRole::Registrar, 'REG-001'],
            ['Admin President', 'admin@westprime.edu', UserRole::Admin, 'ADM-001'],
            ['IT Support', 'it@westprime.edu', UserRole::It, 'IT-001'],
            ['Stakeholder Chairman', 'stakeholder@westprime.edu', UserRole::Stakeholder, 'STK-001'],
        ];

        $users = [];
        foreach ($defs as [$name, $email, $role, $emp]) {
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
            $users[$role->value] = $user;
        }

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

        $alumni = User::create([
            'name' => 'CRUZ, JUAN D',
            'email' => 'juan.cruz@westprime.edu',
            'password' => Hash::make('password'),
            'role' => UserRole::Alumni,
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

        // Alumni historical admission
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
