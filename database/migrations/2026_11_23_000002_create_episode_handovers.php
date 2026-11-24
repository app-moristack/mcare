<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('episode_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('care_episode_id')->constrained()->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->mediumText('content');
            $table->string('priority', 20);
            $table->timestamp('due_at');
            $table->text('acknowledgement')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['recipient_id', 'care_episode_id']);
            $table->unique(['care_episode_id', 'sender_id', 'request_id'], 'episode_handover_requests');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_handovers');
    }
};
