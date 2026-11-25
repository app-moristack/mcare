<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->unique(['organisation_id', 'request_id']);
        });

        Schema::create('checklist_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft');
            $table->json('steps');
            $table->uuid('request_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['checklist_template_id', 'version']);
            $table->unique(['checklist_template_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_versions');
        Schema::dropIfExists('checklist_templates');
    }
};
