<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $t) {
            $t->date('date_of_birth')->nullable()->change();
            $t->text('demographics')->nullable();
        });
        Schema::create('patient_summaries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->unique()->constrained();
            $t->text('content');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('updated_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('patient_access_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('kind');
            $t->string('scope');
            $t->string('role')->nullable();
            $t->string('purpose');
            $t->string('authority_type');
            $t->text('evidence');
            $t->string('wording_version');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->foreignId('recorded_by')->constrained('users');
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('revoked_by')->nullable()->constrained('users');
            $t->text('revocation_reason')->nullable();
            $t->uuid('request_id')->unique();
            $t->timestamps();
            $t->index(['patient_id', 'user_id', 'kind']);
        });
        Schema::create('patient_summary_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained();
            $t->text('content');
            $t->unsignedInteger('version');
            $t->foreignId('recorded_by')->constrained('users');
            $t->text('reason');
            $t->timestamp('created_at');
            $t->unique(['patient_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_summary_revisions');
        Schema::dropIfExists('patient_access_rules');
        Schema::dropIfExists('patient_summaries');
        Schema::table('patients', fn (Blueprint $t) => $t->dropColumn('demographics'));
    }
};
