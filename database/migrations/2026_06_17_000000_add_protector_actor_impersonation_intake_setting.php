<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $key = 'game_protector_actor_impersonation';

    public function up(): void
    {
        if (! Schema::hasTable('game_intake_settings') || ! Schema::hasTable('game_intakes')) {
            return;
        }

        $now = now();
        $enabledWeeks = ['week_3', 'week_4', 'week_5', 'week_6'];

        DB::table('game_intakes')
            ->select('id', 'active_week')
            ->orderBy('id')
            ->get()
            ->each(function ($intake) use ($enabledWeeks, $now) {
                DB::table('game_intake_settings')->updateOrInsert(
                    [
                        'game_intake_id' => $intake->id,
                        'key' => $this->key,
                    ],
                    [
                        'value' => in_array($intake->active_week, $enabledWeeks, true) ? '1' : '0',
                        'type' => 'boolean',
                        'group' => 'roles-spies',
                        'label' => 'Protectors can now appear as other users',
                        'sort_order' => 157,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('game_intake_settings')) {
            return;
        }

        DB::table('game_intake_settings')->where('key', $this->key)->delete();
    }
};
