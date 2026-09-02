<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Demo seeder used invalid "pending" status; admissions only allow enrolled | withdrawn | completed.
     */
    public function up(): void
    {
        DB::table('admissions')
            ->where('status', 'pending')
            ->update(['status' => 'enrolled']);
    }

    public function down(): void
    {
        // Cannot reliably restore which rows were pending.
    }
};
