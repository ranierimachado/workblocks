<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('plan_settings', function(Blueprint $t){$t->id();$t->text('document');});
  Schema::create('priorities',function(Blueprint $t){$t->string('id')->primary();$t->unsignedInteger('rank');$t->string('name');});
  Schema::create('template_blocks',function(Blueprint $t){$t->id();$t->string('day');$t->string('slot');$t->unsignedInteger('position');$t->string('priority_id');$t->unsignedInteger('duration_minutes');$t->text('notes');});
  Schema::create('plan_weeks',function(Blueprint $t){$t->date('week_start')->primary();$t->text('notes');$t->text('changes');});
  Schema::create('work_blocks',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('origin_id');$t->date('original_date')->nullable();$t->date('date')->nullable()->index();$t->string('slot');$t->unsignedInteger('position');$t->string('priority_id')->nullable();$t->string('priority_name');$t->unsignedInteger('duration_minutes');$t->text('notes');$t->boolean('completed')->default(false);$t->unsignedInteger('revision')->default(1);$t->timestamps();});
  Schema::create('plan_imports',function(Blueprint $t){$t->uuid('id')->primary();$t->string('plan_name');$t->text('snapshot');$t->text('document');$t->timestamps();});
 }
 public function down(): void {foreach(['plan_imports','work_blocks','plan_weeks','template_blocks','priorities','plan_settings'] as $t)Schema::dropIfExists($t);}
};
