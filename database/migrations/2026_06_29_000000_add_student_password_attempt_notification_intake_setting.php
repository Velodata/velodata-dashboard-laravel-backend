<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $key = 'game_notify_staff_admins_student_third_party_password_attempts';

    public function up(): void
    {
        if (! Schema::hasTable('game_intake_settings') || ! Schema::hasTable('game_intakes')) {
            return;
        }

        $now = now();

        DB::table('game_intakes')
            ->select('id', 'active_week')
            ->orderBy('id')
            ->get()
            ->each(function ($intake) use ($now) {
                DB::table('game_intake_settings')->updateOrInsert(
                    [
                        'game_intake_id' => $intake->id,
                        'key' => $this->key,
                    ],
                    [
                        'value' => in_array((string) $intake->active_week, ['week_5', 'week_6'], true) ? '1' : '0',
                        'type' => 'boolean',
                        'group' => 'game-controls',
                        'label' => 'Notify Staff Admins when a Student tries to change 3rd Party passwords',
                        'sort_order' => 55,
                        'created_at' => $now,
                        'updated_at' => $now,
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
