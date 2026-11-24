<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_tasks', function (Blueprint $table) {
            $table->boolean('handover_transferable')->default(false);
        });

        DB::table('care_tasks')->whereNotNull('patient_id')->where('priority', 'routine')
            ->whereExists(fn ($audit) => $audit->selectRaw('1')->from('audit_events')
                ->where('event', 'tasks.created')->where('resource_type', 'tasks')->whereNotNull('actor_id')
                ->whereColumn('audit_events.organisation_id', 'care_tasks.organisation_id')
                ->whereColumn('audit_events.resource_id', 'care_tasks.id'))
            ->update(['handover_transferable' => true]);

        Schema::table('episode_handovers', function (Blueprint $table) {
            $table->mediumText('transfer_tasks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('episode_handovers', function (Blueprint $table) {
            $table->dropColumn('transfer_tasks');
        });

        Schema::table('care_tasks', function (Blueprint $table) {
            $table->dropColumn('handover_transferable');
        });
    }
};
