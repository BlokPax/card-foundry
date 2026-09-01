<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending'); // pending|running|completed|failed|cancelled
            $table->string('mode')->default('selected');  // single|selected|all
            $table->string('treatment')->nullable();   // display label (joined)
            $table->json('treatments')->nullable();    // the actual list
            $table->boolean('proof')->default(true);
            $table->string('set_name')->nullable();
            $table->string('profile')->nullable();
            $table->string('format')->default('pdf');
            $table->json('export_options')->nullable();
            $table->json('pipeline')->nullable(); // snapshot; null = app default
            $table->string('manifest_path')->nullable();
            $table->string('merge_path');
            $table->json('art_dirs');
            $table->string('template_path');
            $table->string('output_dir');
            $table->string('kit_path')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->longText('log')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
