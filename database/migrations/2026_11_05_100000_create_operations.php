<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('mfa_secret')->nullable();
            $t->timestamp('mfa_confirmed_at')->nullable();
            $t->unsignedBigInteger('mfa_last_counter')->nullable();
        });
        Schema::table('audit_events', function (Blueprint $t) {
            $t->foreignId('organisation_id')->nullable()->constrained();
            $t->string('resource_type')->nullable();
            $t->unsignedBigInteger('resource_id')->nullable();
        });
        Schema::table('account_applications', function (Blueprint $t) {
            $t->foreignId('organisation_id')->nullable()->constrained();
            $t->string('discipline')->nullable();
            $t->string('credential_reference')->nullable();
            $t->date('credential_expires_at')->nullable();
            $t->text('review_evidence')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
        });
        Schema::create('patients', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('organisation_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('name');
            $t->date('date_of_birth');
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('locality');
            $t->text('address')->nullable();
            $t->string('identity_status')->default('unverified');
            $t->string('status')->default('active');
            $t->string('authority_type');
            $t->text('authority_evidence');
            $t->unsignedInteger('version')->default(1);
            $t->uuid('request_id');
            $t->timestamps();
            $t->unique(['organisation_id', 'request_id']);
            $t->unique(['organisation_id', 'user_id']);
        });
        Schema::create('referrals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organisation_id')->constrained();
            $t->foreignId('patient_id')->constrained();
            $t->string('source');
            $t->text('reason');
            $t->string('discipline');
            $t->string('priority')->default('unknown');
            $t->string('status')->default('received');
            $t->text('triage_evidence')->nullable();
            $t->text('disposition')->nullable();
            $t->foreignId('owner_id')->constrained('users');
            $t->foreignId('backup_id')->constrained('users');
            $t->timestamp('due_at');
            $t->unsignedInteger('version')->default(1);
            $t->uuid('request_id');
            $t->timestamps();
            $t->unique(['organisation_id', 'request_id']);
        });
        Schema::create('appointments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organisation_id')->constrained();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('referral_id')->constrained();
            $t->foreignId('provider_id')->constrained('users');
            $t->string('service');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->string('location_type');
            $t->text('location');
            $t->string('status')->default('booked');
            $t->text('completion_evidence')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->uuid('request_id');
            $t->timestamps();
            $t->unique(['organisation_id', 'request_id']);
            $t->index(['provider_id', 'starts_at', 'ends_at']);
        });
        Schema::create('attendance_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('kind');
            $t->timestamp('occurred_at');
            $t->timestamp('received_at');
            $t->string('capture_status');
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->decimal('accuracy', 10, 2)->nullable();
            $t->text('exception_reason')->nullable();
            $t->uuid('request_id')->unique();
            $t->unique(['appointment_id', 'kind']);
        });
        Schema::create('care_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organisation_id')->constrained();
            $t->foreignId('patient_id')->nullable()->constrained();
            $t->string('title');
            $t->text('description');
            $t->string('priority');
            $t->string('status')->default('new');
            $t->foreignId('owner_id')->constrained('users');
            $t->foreignId('backup_id')->constrained('users');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('due_at');
            $t->text('completion_evidence')->nullable();
            $t->text('waiting_reason')->nullable();
            $t->timestamp('next_check_at')->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->uuid('request_id');
            $t->timestamps();
            $t->unique(['organisation_id', 'request_id']);
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organisation_id')->constrained();
            $t->foreignId('patient_id')->constrained();
            $t->string('number')->unique();
            $t->string('status')->default('draft');
            $t->string('currency', 3)->default('MUR');
            $t->date('due_date');
            $t->unsignedBigInteger('total_minor');
            $t->text('void_reason')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->uuid('request_id');
            $t->timestamps();
            $t->unique(['organisation_id', 'request_id']);
        });
        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->string('description');
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price_minor');
            $t->unsignedBigInteger('total_minor');
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->foreignId('recorded_by')->constrained('users');
            $t->unsignedBigInteger('amount_minor');
            $t->string('reference');
            $t->text('evidence');
            $t->timestamp('received_at');
            $t->uuid('request_id')->unique();
            $t->timestamps();
            $t->unique(['invoice_id', 'reference']);
        });
        Schema::create('operation_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organisation_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('message');
            $t->string('url');
            $t->string('deduplication_key')->unique();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['operation_notifications', 'payments', 'invoice_items', 'invoices', 'care_tasks', 'attendance_events', 'appointments', 'referrals', 'patients'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('account_applications', fn (Blueprint $t) => $t->dropColumn(['organisation_id', 'discipline', 'credential_reference', 'credential_expires_at', 'review_evidence', 'reviewed_by']));
        Schema::table('audit_events', fn (Blueprint $t) => $t->dropColumn(['organisation_id', 'resource_type', 'resource_id']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['mfa_secret', 'mfa_confirmed_at', 'mfa_last_counter']));
    }
};
