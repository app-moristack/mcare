<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('category', 32);
            $table->text('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->text('storage_path')->nullable();
            $table->string('status', 24)->default('quarantined');
            $table->text('description')->nullable();
            $table->text('review_evidence')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('request_id')->unique();
            $table->timestamps();
            $table->index(['patient_id', 'category', 'status']);
        });

        Schema::create('document_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('requested_by')->constrained('users');
            $table->text('document_ids');
            $table->text('purpose');
            $table->string('status', 24)->default('pending');
            $table->text('review_evidence')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('archive_path')->nullable();
            $table->string('archive_sha256', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('request_id')->unique();
            $table->timestamps();
            $table->index(['patient_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_exports');
        Schema::dropIfExists('patient_documents');
    }
};
