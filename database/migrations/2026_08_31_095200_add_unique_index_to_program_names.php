<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $programs = DB::table('programs')->orderBy('id')->get();
        $used = [];

        foreach ($programs as $program) {
            $normalized = trim((string) preg_replace('/\s+/u', ' ', (string) $program->name));
            $key = mb_strtolower($normalized);

            if ($normalized !== (string) $program->name) {
                DB::table('programs')->where('id', $program->id)->update(['name' => $normalized]);
            }

            if (! isset($used[$key])) {
                $used[$key] = $program->id;

                continue;
            }

            $suffix = ' ('.$program->code.')';
            $base = $normalized;
            $max = max(1, 255 - strlen($suffix));
            $candidate = mb_substr($base, 0, $max).$suffix;
            $candidateKey = mb_strtolower($candidate);
            $n = 2;

            while (isset($used[$candidateKey])) {
                $extra = ' ('.$program->code.'-'.$n.')';
                $maxExtra = max(1, 255 - strlen($extra));
                $candidate = mb_substr($base, 0, $maxExtra).$extra;
                $candidateKey = mb_strtolower($candidate);
                $n++;
            }

            DB::table('programs')->where('id', $program->id)->update(['name' => $candidate]);
            $used[$candidateKey] = $program->id;
        }

        Schema::table('programs', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }
};
