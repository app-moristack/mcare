<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_event_id')->unique()->constrained();
            $table->string('decision', 32);
            $table->text('evidence');
            $table->foreignId('reviewed_by')->constrained('users');
            $table->timestamp('reviewed_at');
            $table->uuid('request_id')->unique();
            $table->timestamps();
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_event_id')->constrained();
            $table->foreignId('appointment_id')->constrained();
            $table->foreignId('requested_by')->constrained('users');
            $table->timestamp('proposed_occurred_at');
            $table->text('reason');
            $table->text('evidence');
            $table->string('status', 24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->text('review_evidence')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('request_id')->unique();
            $table->timestamps();
            $table->index(['attendance_event_id', 'status']);
            $table->index(['appointment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_reviews');
    }
};
