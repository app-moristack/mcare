<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episode_discharges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('care_episode_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('care_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('signed_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->text('content');
            $table->string('content_hash', 64);
            $table->timestamp('signed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_discharges');
    }
};
