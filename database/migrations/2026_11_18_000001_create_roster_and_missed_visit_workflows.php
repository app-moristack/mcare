<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_roster_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained();
            $table->foreignId('provider_id')->constrained('users');
            $table->string('kind', 24);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('time_zone', 64)->default('Indian/Mauritius');
            $table->string('locality')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->text('review_evidence')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('request_id')->unique();
            $table->timestamps();
            $table->index(['organisation_id', 'provider_id', 'starts_at', 'ends_at'], 'roster_provider_period');
            $table->index(['organisation_id', 'status', 'starts_at']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('offer_status', 24)->default('pending')->after('status');
            $table->timestamp('offered_at')->nullable()->after('offer_status');
            $table->timestamp('offer_responded_at')->nullable()->after('offered_at');
            $table->text('offer_evidence')->nullable()->after('offer_responded_at');
        });

        Schema::create('missed_visit_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('appointment_id')->unique()->constrained();
            $table->text('missed_reason');
            $table->foreignId('reported_by')->constrained('users');
            $table->timestamp('reported_at');
            $table->string('welfare_status', 24)->default('pending');
            $table->text('welfare_evidence')->nullable();
            $table->foreignId('welfare_checked_by')->nullable()->constrained('users');
            $table->timestamp('welfare_checked_at')->nullable();
            $table->string('replacement_status', 24)->default('required');
            $table->foreignId('replacement_appointment_id')->nullable()->unique()->constrained('appointments');
            $table->text('replacement_evidence')->nullable();
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('backup_id')->constrained('users');
            $table->foreignId('task_id')->nullable()->unique()->constrained('care_tasks');
            $table->timestamp('due_at');
            $table->string('status', 24)->default('open');
            $table->text('resolution_evidence')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('request_id')->unique();
            $table->timestamps();
            $table->index(['organisation_id', 'status', 'due_at']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('missed_visit_case_id')->nullable()->after('referral_id')->constrained('missed_visit_cases');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropConstrainedForeignId('missed_visit_case_id'));
        Schema::dropIfExists('missed_visit_cases');
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn(['offer_status', 'offered_at', 'offer_responded_at', 'offer_evidence']));
        Schema::dropIfExists('provider_roster_entries');
    }
};
