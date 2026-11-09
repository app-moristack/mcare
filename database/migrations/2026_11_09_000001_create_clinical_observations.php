<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('appointment_id')->constrained();
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('clinical_observations');
            $table->timestamp('observed_at');
            $table->text('measurements');
            $table->text('context')->nullable();
            $table->text('correction_reason')->nullable();
            $table->string('content_hash', 64);
            $table->uuid('request_id')->unique();
            $table->timestamps();
            $table->index(['patient_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_observations');
    }
};
