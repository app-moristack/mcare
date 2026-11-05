<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('account_kind')->default('self_patient');
            $t->string('account_status')->default('active');
            $t->string('identity_status')->default('unverified');
            $t->string('preferred_language')->default('en');
            $t->string('time_zone')->default('Indian/Mauritius');
            $t->string('terms_version')->nullable();
            $t->string('privacy_notice_version')->nullable();
            $t->timestamp('terms_accepted_at')->nullable();
            $t->timestamp('privacy_acknowledged_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
        });
        Schema::create('organisations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type')->default('care_service');
            $t->string('currency', 3)->default('MUR');
            $t->string('time_zone')->default('Indian/Mauritius');
            $t->timestamps();
        });
        Schema::create('memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('role');
            $t->string('status')->default('pending');
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamps();
            $t->unique(['organisation_id', 'user_id', 'role']);
        });
        Schema::create('account_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('kind');
            $t->string('status')->default('pending');
            $t->timestamps();
        });
        Schema::create('patient_profiles', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('legal_name')->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('mobile_phone', 30)->nullable();
            $t->string('locality')->nullable();
            $t->timestamp('onboarding_completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('event');
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'patient_profiles', 'account_applications', 'memberships', 'organisations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['account_kind', 'account_status', 'identity_status', 'preferred_language', 'time_zone', 'terms_version', 'privacy_notice_version', 'terms_accepted_at', 'privacy_acknowledged_at', 'last_login_at']));
    }
};
