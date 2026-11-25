<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_reassignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operational_incident_id')->constrained()->restrictOnDelete();
            $table->foreignId('previous_reviewer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('previous_assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('previous_assigned_at')->nullable();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->mediumText('reason');
            $table->timestamp('changed_at');
            $table->unsignedInteger('previous_version');
            $table->unique(['operational_incident_id', 'previous_version'], 'incident_reassignment_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_reassignments');
    }
};
