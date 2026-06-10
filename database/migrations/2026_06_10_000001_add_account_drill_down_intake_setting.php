<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $key = 'game_account_drill_down_enabled';

    public function up(): void
    {
        if (! Schema::hasTable('game_intake_settings') || ! Schema::hasTable('game_intakes')) {
            return;
        }

        $now = now();

        DB::table('game_intakes')->orderBy('id')->get(['id', 'active_week'])->each(function ($intake) use ($now) {
            $enabled = in_array($intake->active_week, ['week_4', 'week_5', 'week_6'], true) ? '1' : '0';

            DB::table('game_intake_settings')->updateOrInsert(
                [
                    'game_intake_id' => $intake->id,
                    'key' => $this->key,
                ],
                [
                    'value' => $enabled,
                    'type' => 'boolean',
                    'group' => 'roles-spies',
                    'label' => 'Admins can trace fake-account ownership',
                    'description' => null,
                    'sort_order' => 165,
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

        DB::table('game_intake_settings')
            ->where('key', $this->key)
            ->delete();
    }
};
