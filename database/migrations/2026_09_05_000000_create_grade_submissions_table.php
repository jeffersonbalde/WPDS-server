<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_section_id')->constrained()->cascadeOnDelete();
            $table->string('period', 16); // prelim | midterm | semi_final | final
            $table->string('status', 16)->default('pending'); // pending | released | returned
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();

            $table->unique(['class_section_id', 'period'], 'grade_submissions_section_period_unique');
        });

        // Backfill: treat every already-locked grade as a released submission so
        // historical/seeded records stay visible to students under the new rule.
        $lockedGrades = DB::table('grades')
            ->join('enrollment_subjects', 'enrollment_subjects.id', '=', 'grades.enrollment_subject_id')
            ->where('grades.is_locked', true)
            ->get([
                'grades.prelim',
                'grades.midterm',
                'grades.semi_final',
                'grades.final',
                'grades.encoded_by',
                'grades.submitted_at',
                'enrollment_subjects.class_section_id',
            ]);

        $now = now();
        $seen = [];
        $rows = [];

        foreach ($lockedGrades as $grade) {
            foreach (['prelim', 'midterm', 'semi_final', 'final'] as $period) {
                if ($grade->{$period} === null) {
                    continue;
                }

                $key = $grade->class_section_id.'|'.$period;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $stamp = $grade->submitted_at ?? $now;
                $rows[] = [
                    'class_section_id' => $grade->class_section_id,
                    'period' => $period,
                    'status' => 'released',
                    'submitted_by' => $grade->encoded_by,
                    'submitted_at' => $stamp,
                    'reviewed_by' => null,
                    'reviewed_at' => $stamp,
                    'review_remarks' => 'Backfilled from previously locked grades.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('grade_submissions')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_submissions');
    }
};
