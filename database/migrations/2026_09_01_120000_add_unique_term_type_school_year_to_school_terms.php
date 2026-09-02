<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_terms', function (Blueprint $table) {
            $table->unique(['term_type', 'school_year'], 'school_terms_type_year_unique');
        });
    }

    public function down(): void
    {
        Schema::table('school_terms', function (Blueprint $table) {
            $table->dropUnique('school_terms_type_year_unique');
        });
    }
};
