<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_assessments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('appointment_id')->nullable()->constrained();
            $t->string('kind');
            $t->string('status')->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->string('schema_version');
            $t->text('content');
            $t->foreignId('author_id')->constrained('users');
            $t->foreignId('previous_id')->nullable()->unique()->constrained('patient_assessments');
            $t->text('amendment_reason')->nullable();
            $t->foreignId('confirmed_by')->nullable()->constrained('users');
            $t->timestamp('submitted_at')->nullable();
            $t->string('confirmation_method')->nullable();
            $t->text('confirmation_evidence')->nullable();
            $t->foreignId('signed_by')->nullable()->constrained('users');
            $t->timestamp('signed_at')->nullable();
            $t->boolean('cosign_required')->default(false);
            $t->foreignId('cosigned_by')->nullable()->constrained('users');
            $t->timestamp('cosigned_at')->nullable();
            $t->date('review_due')->nullable();
            $t->string('content_hash', 64)->nullable();
            $t->uuid('request_id')->unique();
            $t->timestamps();
            $t->index(['patient_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_assessments');
    }
};
