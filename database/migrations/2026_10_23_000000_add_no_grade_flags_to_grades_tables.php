<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->boolean('prelim_ng')->default(false)->after('prelim');
            $table->boolean('midterm_ng')->default(false)->after('midterm');
            $table->boolean('semi_final_ng')->default(false)->after('semi_final');
            $table->boolean('final_ng')->default(false)->after('final');
            $table->boolean('final_grade_ng')->default(false)->after('final_grade');
        });

        Schema::table('grade_change_requests', function (Blueprint $table) {
            $table->decimal('new_value', 5, 2)->nullable()->change();
            $table->boolean('old_is_ng')->default(false)->after('old_value');
            $table->boolean('new_is_ng')->default(false)->after('new_value');
        });
    }

    public function down(): void
    {
        Schema::table('grade_change_requests', function (Blueprint $table) {
            $table->dropColumn(['old_is_ng', 'new_is_ng']);
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->dropColumn(['prelim_ng', 'midterm_ng', 'semi_final_ng', 'final_ng', 'final_grade_ng']);
        });
    }
};
