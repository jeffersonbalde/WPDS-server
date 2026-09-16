<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The system no longer has a separate "alumni" role. A graduated student
     * keeps the student role and the same read access to their records.
     */
    public function up(): void
    {
        DB::table('users')->where('role', 'alumni')->update(['role' => 'student']);
    }

    public function down(): void
    {
        // No-op: the alumni role has been retired.
    }
};
