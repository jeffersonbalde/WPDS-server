<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_majors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32)->nullable();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['program_id', 'name']);
        });

        if (Schema::hasColumn('programs', 'major')) {
            $programs = DB::table('programs')->orderBy('id')->get();
            $now = now();

            foreach ($programs as $program) {
                $name = trim((string) ($program->major ?? ''));
                if ($name === '') {
                    continue;
                }

                $code = null;
                $programCode = (string) $program->code;
                if (str_contains($programCode, '-')) {
                    $suffix = substr($programCode, (int) strrpos($programCode, '-') + 1);
                    if ($suffix !== '' && strlen($suffix) <= 12) {
                        $code = strtoupper($suffix);
                    }
                }

                DB::table('program_majors')->insert([
                    'program_id' => $program->id,
                    'code' => $code,
                    'name' => $name,
                    'sort_order' => 0,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->foreignId('program_major_id')
                ->nullable()
                ->constrained('program_majors')
                ->nullOnDelete();
        });

        Schema::table('admissions', function (Blueprint $table) {
            $table->foreignId('program_major_id')
                ->nullable()
                ->constrained('program_majors')
                ->nullOnDelete();
        });

        $majorsByProgram = DB::table('program_majors')->get()->groupBy('program_id');
        foreach ($majorsByProgram as $programId => $majors) {
            if ($majors->count() !== 1) {
                continue;
            }
            $majorId = $majors->first()->id;
            DB::table('student_profiles')
                ->where('program_id', $programId)
                ->update(['program_major_id' => $majorId]);
            DB::table('admissions')
                ->where('program_id', $programId)
                ->update(['program_major_id' => $majorId]);
        }

        $this->mergeDuplicateNamedPrograms();

        if (Schema::hasColumn('programs', 'major')) {
            Schema::table('programs', function (Blueprint $table) {
                $table->dropColumn('major');
            });
        }
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_major_id');
        });
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_major_id');
        });

        if (! Schema::hasColumn('programs', 'major')) {
            Schema::table('programs', function (Blueprint $table) {
                $table->string('major')->nullable()->after('track_type');
            });

            $firstMajors = DB::table('program_majors')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->unique('program_id');

            foreach ($firstMajors as $major) {
                DB::table('programs')
                    ->where('id', $major->program_id)
                    ->update(['major' => $major->name]);
            }
        }

        Schema::dropIfExists('program_majors');
    }

    private function mergeDuplicateNamedPrograms(): void
    {
        $programs = DB::table('programs')->orderBy('id')->get();
        $groups = $programs->groupBy(function ($program) {
            $level = is_object($program->academic_level) ? ($program->academic_level->value ?? '') : (string) $program->academic_level;

            return mb_strtolower(trim((string) $program->name)).'|'.$level.'|'.mb_strtolower((string) ($program->track_type ?? ''));
        });

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $sorted = $group->values();
            $keeper = $sorted->first();
            $codes = $sorted->pluck('code')->all();
            $shared = $this->sharedProgramCode($codes);

            if ($shared && strcasecmp($shared, (string) $keeper->code) !== 0) {
                $taken = DB::table('programs')
                    ->where('code', $shared)
                    ->where('id', '!=', $keeper->id)
                    ->exists();
                if (! $taken) {
                    DB::table('programs')->where('id', $keeper->id)->update(['code' => $shared]);
                    $keeper->code = $shared;
                }
            }

            foreach ($sorted->skip(1) as $duplicate) {
                $this->reassignProgramId((int) $duplicate->id, (int) $keeper->id);
                DB::table('programs')->where('id', $duplicate->id)->delete();
            }

            $this->dedupeMajors((int) $keeper->id);
        }
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function sharedProgramCode(array $codes): ?string
    {
        $stems = [];
        foreach ($codes as $code) {
            $code = strtoupper(trim((string) $code));
            $stems[] = str_contains($code, '-')
                ? substr($code, 0, (int) strrpos($code, '-'))
                : $code;
        }
        $stems = array_values(array_unique(array_filter($stems)));

        return count($stems) === 1 ? $stems[0] : null;
    }

    private function reassignProgramId(int $fromId, int $toId): void
    {
        $keeperMajors = DB::table('program_majors')
            ->where('program_id', $toId)
            ->get()
            ->keyBy(fn ($major) => mb_strtolower(trim((string) $major->name)));

        foreach (DB::table('program_majors')->where('program_id', $fromId)->get() as $major) {
            $key = mb_strtolower(trim((string) $major->name));
            if (isset($keeperMajors[$key])) {
                $keepId = $keeperMajors[$key]->id;
                DB::table('student_profiles')->where('program_major_id', $major->id)->update(['program_major_id' => $keepId]);
                DB::table('admissions')->where('program_major_id', $major->id)->update(['program_major_id' => $keepId]);
                DB::table('program_majors')->where('id', $major->id)->delete();
            } else {
                DB::table('program_majors')->where('id', $major->id)->update(['program_id' => $toId]);
                $keeperMajors[$key] = $major;
            }
        }

        DB::table('student_profiles')->where('program_id', $fromId)->update(['program_id' => $toId]);
        DB::table('admissions')->where('program_id', $fromId)->update(['program_id' => $toId]);

        $items = DB::table('curriculum_items')->where('program_id', $fromId)->get();
        foreach ($items as $item) {
            $exists = DB::table('curriculum_items')
                ->where('program_id', $toId)
                ->where('subject_id', $item->subject_id)
                ->where('year_level', $item->year_level)
                ->where('semester', $item->semester)
                ->exists();

            if ($exists) {
                DB::table('curriculum_items')->where('id', $item->id)->delete();
            } else {
                DB::table('curriculum_items')->where('id', $item->id)->update(['program_id' => $toId]);
            }
        }
    }

    private function dedupeMajors(int $programId): void
    {
        $majors = DB::table('program_majors')
            ->where('program_id', $programId)
            ->orderBy('id')
            ->get();

        $seenNames = [];
        $order = 0;
        foreach ($majors as $major) {
            $key = mb_strtolower(trim((string) $major->name));
            if (isset($seenNames[$key])) {
                $keepId = $seenNames[$key];
                DB::table('student_profiles')->where('program_major_id', $major->id)->update(['program_major_id' => $keepId]);
                DB::table('admissions')->where('program_major_id', $major->id)->update(['program_major_id' => $keepId]);
                DB::table('program_majors')->where('id', $major->id)->delete();

                continue;
            }

            $seenNames[$key] = $major->id;
            DB::table('program_majors')->where('id', $major->id)->update(['sort_order' => $order]);
            $order++;
        }
    }
};
