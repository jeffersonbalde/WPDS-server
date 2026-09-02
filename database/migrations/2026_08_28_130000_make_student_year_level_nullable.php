<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE student_profiles MODIFY year_level TINYINT UNSIGNED NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE student_profiles ALTER COLUMN year_level DROP NOT NULL');
            DB::statement('ALTER TABLE student_profiles ALTER COLUMN year_level DROP DEFAULT');
        } elseif ($driver === 'sqlite') {
            // SQLite does not enforce NOT NULL the same way after table create in all setups;
            // leave as-is — app now treats missing placement as null/optional.
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE student_profiles MODIFY year_level TINYINT UNSIGNED NOT NULL DEFAULT 1');
        } elseif ($driver === 'pgsql') {
            DB::statement('UPDATE student_profiles SET year_level = 1 WHERE year_level IS NULL');
            DB::statement('ALTER TABLE student_profiles ALTER COLUMN year_level SET DEFAULT 1');
            DB::statement('ALTER TABLE student_profiles ALTER COLUMN year_level SET NOT NULL');
        }
    }
};
