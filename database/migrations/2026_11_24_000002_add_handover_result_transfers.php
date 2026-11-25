<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigation_results', function (Blueprint $table) {
            $table->foreignId('follow_up_owner_id')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::table('episode_handovers', function (Blueprint $table) {
            $table->mediumText('transfer_results')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('episode_handovers', function (Blueprint $table) {
            $table->dropColumn('transfer_results');
        });

        Schema::table('investigation_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('follow_up_owner_id');
        });
    }
};
