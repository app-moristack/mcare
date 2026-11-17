<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('requester_id')->constrained('users');
            $table->timestamp('requested_at');
            $table->string('category', 32);
            $table->text('details');
            $table->string('content_hash', 64);
            $table->uuid('request_id')->unique();
            $table->timestamps();
            $table->index(['patient_id', 'requested_at']);
        });

        Schema::create('investigation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('investigation_id')->constrained();
            $table->foreignId('received_by')->constrained('users');
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('backup_id')->constrained('users');
            $table->foreignId('supersedes_id')->nullable()->unique()->constrained('investigation_results');
            $table->foreignId('task_id')->nullable()->unique()->constrained('care_tasks');
            $table->timestamp('received_at');
            $table->timestamp('source_reported_at')->nullable();
            $table->string('source_flag', 24);
            $table->text('details');
            $table->text('correction_reason')->nullable();
            $table->string('status', 24)->default('pending');
            $table->text('acknowledgement')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users');
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('completion')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('content_hash', 64);
            $table->uuid('request_id')->unique();
            $table->timestamps();
            $table->index(['patient_id', 'status']);
        });

        Schema::create('investigation_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('investigation_result_id')->unique()->constrained();
            $table->text('content');
            $table->string('source_hash', 64);
            $table->foreignId('published_by')->constrained('users');
            $table->timestamp('published_at');
            $table->uuid('request_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_publications');
        Schema::dropIfExists('investigation_results');
        Schema::dropIfExists('investigations');
    }
};
