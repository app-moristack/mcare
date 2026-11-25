<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('checklist_version_id')->constrained()->restrictOnDelete();
            $table->string('title', 160);
            $table->unsignedInteger('version');
            $table->json('steps');
            $table->string('source_hash', 64);
            $table->foreignId('attached_by')->constrained('users')->restrictOnDelete();
            $table->uuid('attach_request_id');
            $table->timestamp('attached_at');
            $table->mediumText('responses')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('submission_request_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('submission_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_checklists');
    }
};
