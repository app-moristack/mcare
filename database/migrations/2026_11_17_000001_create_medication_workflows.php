<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('appointment_id')->constrained();
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('medication_reconciliations');
            $table->timestamp('reconciled_at');
            $table->string('status', 24);
            $table->text('sources');
            $table->text('medicines');
            $table->text('discrepancies')->nullable();
            $table->text('follow_up')->nullable();
            $table->text('correction_reason')->nullable();
            $table->string('content_hash', 64);
            $table->uuid('request_id')->unique();
            $table->timestamps();
            $table->index(['patient_id', 'reconciled_at']);
        });

        Schema::create('medication_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('verifier_id')->constrained('users');
            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('medication_instructions');
            $table->timestamp('verified_at');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 24);
            $table->text('details');
            $table->text('correction_reason')->nullable();
            $table->string('content_hash', 64);
            $table->uuid('request_id')->unique();
            $table->timestamps();
            $table->index(['patient_id', 'starts_at']);
        });

        Schema::create('medication_administrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('medication_instruction_id')->constrained();
            $table->foreignId('appointment_id')->constrained();
            $table->foreignId('administrator_id')->constrained('users');
            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('medication_administrations');
            $table->timestamp('administered_at');
            $table->string('outcome', 24);
            $table->text('instruction_snapshot');
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->text('correction_reason')->nullable();
            $table->string('content_hash', 64);
            $table->uuid('request_id')->unique();
            $table->timestamps();
            $table->index(['patient_id', 'administered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_administrations');
        Schema::dropIfExists('medication_instructions');
        Schema::dropIfExists('medication_reconciliations');
    }
};
