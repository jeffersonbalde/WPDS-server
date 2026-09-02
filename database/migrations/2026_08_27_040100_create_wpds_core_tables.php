<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('academic_level', 16); // shs | college
            $table->string('track_type', 32)->nullable(); // academic | tvl | degree | associate
            $table->string('major')->nullable();
            $table->unsignedTinyInteger('duration_years')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32);
            $table->string('title');
            $table->decimal('units', 4, 2)->default(3);
            $table->string('academic_level', 16);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['code', 'academic_level']);
        });

        Schema::create('curriculum_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('year_level');
            $table->unsignedTinyInteger('semester'); // 1, 2, or 3=summer
            $table->timestamps();

            $table->unique(['program_id', 'subject_id', 'year_level', 'semester'], 'curriculum_unique');
        });

        Schema::create('school_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // FIRST SEMESTER, 2025-2026
            $table->string('term_type', 32); // first_semester | second_semester | summer
            $table->string('school_year', 16); // 2025-2026
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('employee_no')->nullable()->unique();
            $table->string('department')->nullable();
            $table->string('position')->nullable();
            $table->string('mobile')->nullable();
            $table->timestamps();
        });

        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('student_no')->unique();
            $table->string('academic_level', 16);
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('year_level')->default(1);
            $table->string('section')->nullable();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('civil_status', 32)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('mobile')->nullable();
            $table->string('telephone')->nullable();
            $table->string('ethnic_origin')->nullable();
            $table->string('religion')->nullable();
            $table->text('educational_background')->nullable();
            $table->text('parents_guardian')->nullable();
            $table->timestamps();
        });

        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->string('admission_number')->unique();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('year_level');
            $table->string('section')->nullable();
            $table->string('status', 32)->default('enrolled'); // enrolled | withdrawn | completed
            $table->timestamps();
        });

        Schema::create('class_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('section', 32)->default('A');
            $table->string('schedule_time')->nullable();
            $table->string('schedule_day')->nullable();
            $table->string('room')->nullable();
            $table->timestamps();
        });

        Schema::create('enrollment_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_section_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['admission_id', 'class_section_id']);
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_subject_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('prelim', 5, 2)->nullable();
            $table->decimal('midterm', 5, 2)->nullable();
            $table->decimal('semi_final', 5, 2)->nullable();
            $table->decimal('final', 5, 2)->nullable();
            $table->decimal('final_grade', 5, 2)->nullable();
            $table->string('remarks', 32)->nullable();
            $table->boolean('is_locked')->default(false);
            $table->foreignId('encoded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('grade_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->string('period_field', 32); // prelim | midterm | semi_final | final
            $table->decimal('old_value', 5, 2)->nullable();
            $table->decimal('new_value', 5, 2);
            $table->text('reason');
            $table->string('status', 32)->default('pending'); // pending | approved | rejected
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_remarks')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('grade_change_requests');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('enrollment_subjects');
        Schema::dropIfExists('class_sections');
        Schema::dropIfExists('admissions');
        Schema::dropIfExists('student_profiles');
        Schema::dropIfExists('staff_profiles');
        Schema::dropIfExists('school_terms');
        Schema::dropIfExists('curriculum_items');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('programs');
    }
};
