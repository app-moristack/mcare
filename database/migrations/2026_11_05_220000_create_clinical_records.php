<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('appointment_id')->constrained();
            $t->foreignId('author_id')->constrained('users');
            $t->string('discipline');
            $t->string('note_type');
            $t->string('visibility')->default('discipline_only');
            $t->string('status')->default('draft');
            $t->foreignId('original_id')->nullable()->constrained('clinical_notes');
            $t->text('addendum_reason')->nullable();
            $t->text('content');
            $t->timestamp('encounter_at')->nullable();
            $t->foreignId('signed_by')->nullable()->constrained('users');
            $t->timestamp('signed_at')->nullable();
            $t->string('content_hash', 64)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->uuid('request_id')->unique();
            $t->timestamps();
            $t->index(['patient_id', 'status']);
        });
        Schema::create('care_episodes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('referral_id')->nullable()->constrained();
            $t->string('title');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedBigInteger('current_plan_id')->nullable();
            $t->uuid('request_id')->unique();
            $t->timestamps();
        });
        Schema::create('care_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('episode_id')->constrained('care_episodes');
            $t->foreignId('author_id')->constrained('users');
            $t->foreignId('previous_id')->nullable()->constrained('care_plans');
            $t->unsignedInteger('revision_number');
            $t->text('revision_reason')->nullable();
            $t->text('content');
            $t->string('status')->default('draft');
            $t->foreignId('signed_by')->nullable()->constrained('users');
            $t->timestamp('signed_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('review_due_at')->nullable();
            $t->foreignId('review_task_id')->nullable()->constrained('care_tasks');
            $t->string('content_hash', 64)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->uuid('request_id')->unique();
            $t->timestamps();
            $t->unique(['episode_id', 'revision_number']);
        });
        Schema::table('care_episodes', fn (Blueprint $t) => $t->foreign('current_plan_id')->references('id')->on('care_plans'));
        Schema::create('care_plan_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('care_plan_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action');
            $t->text('reason');
            $t->timestamp('created_at');
        });
        Schema::create('clinical_publications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('clinical_note_id')->nullable()->unique()->constrained();
            $t->foreignId('care_plan_id')->nullable()->unique()->constrained();
            $t->text('content');
            $t->string('source_hash', 64);
            $t->foreignId('published_by')->constrained('users');
            $t->timestamp('published_at');
            $t->uuid('request_id')->unique();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_publications');
        Schema::dropIfExists('care_plan_events');
        Schema::table('care_episodes', fn (Blueprint $t) => $t->dropForeign(['current_plan_id']));
        Schema::dropIfExists('care_plans');
        Schema::dropIfExists('care_episodes');
        Schema::dropIfExists('clinical_notes');
    }
};
