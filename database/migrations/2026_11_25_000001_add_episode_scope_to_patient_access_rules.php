<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_access_rules', function (Blueprint $table) {
            $table->foreignId('episode_id')->nullable()->constrained('care_episodes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('patient_access_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('episode_id');
        });
    }
};
