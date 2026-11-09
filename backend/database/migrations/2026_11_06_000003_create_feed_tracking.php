<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_observations', function (Blueprint $t) {
            $t->string('filename')->primary();
            $t->string('signature');
            $t->unsignedBigInteger('stable_since');
        });
        Schema::create('feed_receipts', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->string('filename');
            $t->string('hash', 64);
            $t->string('status');
            $t->string('source_folder');
            $t->string('source_name');
            $t->string('archive_name');
            $t->boolean('delivered')->default(false);
            $t->text('reason')->nullable();
            $t->text('summary')->nullable();
            $t->uuid('import_id')->nullable();
            $t->uuid('workspace_id');
            $t->unsignedBigInteger('previous_revision');
            $t->unsignedBigInteger('new_revision')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_receipts');
        Schema::dropIfExists('feed_observations');
    }
};
