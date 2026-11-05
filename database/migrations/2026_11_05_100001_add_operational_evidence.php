<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', fn (Blueprint $t) => $t->text('approval_evidence')->nullable());
        Schema::table('appointments', fn (Blueprint $t) => $t->text('confirmation_evidence')->nullable());
    }

    public function down(): void
    {
        Schema::table('memberships', fn (Blueprint $t) => $t->dropColumn('approval_evidence'));
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn('confirmation_evidence'));
    }
};
