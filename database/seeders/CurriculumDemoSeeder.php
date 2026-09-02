<?php

namespace Database\Seeders;

use App\Enums\AcademicLevel;
use App\Models\CurriculumItem;
use App\Models\Program;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class CurriculumDemoSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = $this->ensureSubjects();

        $bsit = Program::query()->whereIn('code', ['BSIT', 'BSITO'])->orderBy('id')->first();
        $btvted = Program::query()->where('code', 'BTVTED')->first();
        $ait = Program::query()->where('code', 'AIT')->first();
        $stem = Program::query()->where('code', 'STEM')->first();
        $css = Program::query()->where('code', 'CSS')->first();

        if ($bsit) {
            $this->syncCurriculum($bsit, [
                [1, 1, ['ITE 111', 'GE 1']],
                [1, 2, ['ITE 112']],
                [2, 1, ['CS 232']],
                [3, 1, ['CS 116', 'CSP 107']],
            ], $subjects);
        }

        if ($btvted) {
            $this->syncCurriculum($btvted, [
                [1, 1, ['ITE 111', 'GE 1']],
                [1, 2, ['ITE 112']],
                [2, 1, ['CS 232']],
            ], $subjects);
        }

        if ($ait) {
            $this->syncCurriculum($ait, [
                [1, 1, ['ITE 111', 'GE 1']],
                [1, 2, ['ITE 112']],
                [2, 1, ['CS 232', 'CSP 107']],
            ], $subjects);
        }

        if ($stem) {
            $this->syncCurriculum($stem, [
                [1, 1, ['ENG 1', 'MATH 1', 'SCI 1']],
                [1, 2, ['ENG 1']],
                [2, 1, ['MATH 1', 'SCI 1']],
            ], $subjects);
        }

        if ($css) {
            $this->syncCurriculum($css, [
                [1, 1, ['CSS 1', 'ENG 1']],
                [1, 2, ['MATH 1']],
                [2, 1, ['CSS 1', 'SCI 1']],
            ], $subjects);
        }

        $this->command?->info('Curriculum demo seeded for existing programs.');
        $this->command?->info('Open Curriculum and select BSIT/BTVTED/STEM/AIT/CSS to preview the layout.');
    }

    /**
     * @return array<string, Subject>
     */
    private function ensureSubjects(): array
    {
        $defs = [
            ['ITE 111', 'INTRODUCTION TO COMPUTING', 3, AcademicLevel::College],
            ['ITE 112', 'COMPUTER PROGRAMMING 1', 3, AcademicLevel::College],
            ['GE 1', 'UNDERSTANDING THE SELF', 3, AcademicLevel::College],
            ['CS 116', 'NEURAL NETWORKS', 3, AcademicLevel::College],
            ['CS 232', 'SOFTWARE ENGINEERING 1', 3, AcademicLevel::College],
            ['CSP 107', 'GAME DEVELOPMENT', 3, AcademicLevel::College],
            ['ENG 1', 'ORAL COMMUNICATION', 0, AcademicLevel::Shs],
            ['MATH 1', 'GENERAL MATHEMATICS', 0, AcademicLevel::Shs],
            ['SCI 1', 'EARTH AND LIFE SCIENCE', 0, AcademicLevel::Shs],
            ['CSS 1', 'COMPUTER SYSTEMS SERVICING 1', 0, AcademicLevel::Shs],
        ];

        $map = [];
        foreach ($defs as [$code, $title, $units, $level]) {
            $map[$code] = Subject::updateOrCreate(
                [
                    'code' => $code,
                    'academic_level' => $level,
                ],
                [
                    'title' => $title,
                    'units' => $units,
                    'is_active' => true,
                ]
            );
        }

        return $map;
    }

    /**
     * @param  array<int, array{0:int,1:int,2:array<int,string>}>  $groups
     * @param  array<string, Subject>  $subjects
     */
    private function syncCurriculum(Program $program, array $groups, array $subjects): void
    {
        foreach ($groups as [$year, $semester, $codes]) {
            foreach ($codes as $code) {
                if (! isset($subjects[$code])) {
                    continue;
                }

                CurriculumItem::updateOrCreate(
                    [
                        'program_id' => $program->id,
                        'subject_id' => $subjects[$code]->id,
                        'year_level' => $year,
                        'semester' => $semester,
                    ],
                    []
                );
            }
        }
    }
}
