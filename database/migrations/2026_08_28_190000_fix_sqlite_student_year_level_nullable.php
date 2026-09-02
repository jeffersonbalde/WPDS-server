<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('year_level')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('year_level')->nullable(false)->default(1)->change();
        });
    }
};
