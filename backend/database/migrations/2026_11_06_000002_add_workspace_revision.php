<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_state', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->uuid('workspace_id');
            $table->unsignedBigInteger('revision')->default(0);
        });
        DB::table('workspace_state')->insert(['id' => 1, 'workspace_id' => (string) Str::uuid(), 'revision' => 0]);

        // Repair legacy duplicate positions before enforcing deterministic ordering.
        $positions = [];
        foreach (DB::table('work_blocks')->whereNotNull('date')->orderBy('date')->orderBy('slot')->orderByDesc('completed')->orderBy('position')->orderBy('id')->get() as $block) {
            $key = $block->date.'/'.$block->slot;
            $positions[$key] = ($positions[$key] ?? 0) + 1;
            DB::table('work_blocks')->where('id', $block->id)->update(['position' => $positions[$key]]);
        }
        Schema::table('work_blocks', fn (Blueprint $table) => $table->unique(['date', 'slot', 'position'], 'work_blocks_position_unique'));
    }

    public function down(): void
    {
        Schema::table('work_blocks', fn (Blueprint $table) => $table->dropUnique('work_blocks_position_unique'));
        Schema::dropIfExists('workspace_state');
    }
};
