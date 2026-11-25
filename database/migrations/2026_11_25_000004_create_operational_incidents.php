<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('appointments')->restrictOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->string('category', 24);
            $table->string('severity', 16);
            $table->string('status', 16);
            $table->mediumText('description');
            $table->mediumText('resolution')->nullable();
            $table->timestamp('reported_at');
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('request_id');
            $table->string('content_hash', 64);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['organisation_id', 'reporter_id', 'request_id'], 'incident_report_requests');
            $table->index(['visit_id', 'reporter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_incidents');
    }
};
