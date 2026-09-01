<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('treatment_layers')->nullable(); // treatment => layer snapshot
            $table->json('white_dirs')->nullable();       // white-ink art directories
            $table->json('serialization')->nullable();    // {enabled, pad, columns:{treatment=>column}}
            $table->json('keywords')->nullable();         // ability-keyword styling config
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['treatment_layers', 'white_dirs', 'serialization', 'keywords']);
        });
    }
};
