<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('game_intakes')->updateOrInsert(
            ['code' => 'EQ-CYBER-24-17'],
            [
                'name' => 'Equinim Cyber Class 24.17',
                'status' => 'planned',
                'active_week' => 'week_1',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $intakeId = DB::table('game_intakes')->where('code', 'EQ-CYBER-24-17')->value('id');

        $students = [
            ['Agus', 'Susanto', null, 'agus313@y7mail.com', null, 'Creator'],
            ['Georgie', 'Perez', null, 'ggperez7@gmail.com', null, 'Creator'],
            ['Esther', 'Ko', null, 'esther@agili8.com', null, 'Creator'],
            ['Jake', 'Davey', null, 'jakedavey999564@gmail.com', null, 'Creator'],
            ['Wesley', 'Cossington-Hand', null, 'wesley-cossington-hand@hotmail.com', null, 'Creator'],
            ['Jose Antonio', 'Borges Veloso', null, 'veloso.jos@gmail.com', null, 'Creator'],
            ['Minnu', 'Selvanathan', null, 'minnu.shruthi@gmail.com', null, 'Creator'],
            ['Venkata Naga Srikar', 'Golakaram', null, 'srikargolakaram@yahoo.com', null, 'Creator'],
            ['Ervin', 'Fernandes', null, 'ervinfermandes@y7mail.com', null, 'Creator'],
            ['Russell', 'McChesney', null, 'russell.mcchesney@outlook.com', null, 'Creator'],
            ['Siveshan', 'Chellan', null, 'siveshanchellan12@gmail.com', null, 'Creator'],
            ['Theshalin', 'Gounden', 'Shalin', 'shalingounden@gmail.com', null, 'Creator'],
            ['Darren', 'Robinson', null, 'Darren344@hotmail.com', null, 'Creator'],
            ['Leatitia', 'Nyabera', null, 'aku.leatitia@gmail.com', null, 'Creator'],
            ['Ravi', 'Chauhan', null, 'rc20mail@gmail.com', null, 'Creator'],
            ['Zakia', 'Thevarajoo', null, 'thevarajoozakia@gmail.com', null, 'Creator'],
            ['Saisha (deferred)', 'Kishore', null, 'saishakishore@hotmail.com', null, 'Creator'],
            ['Norest', 'Nyabadza', null, 'nyabadzano@gmail.com', null, 'Creator'],
            ['Nagabhushanam', 'Bejjugamala', 'Lini', 'karthik231999@gmail.com', null, 'Creator'],
            ['Alina', 'Dronova', null, 'dronovaa95@gmail.com', null, 'Creator'],
            ['John', 'Hennessy (Trainer)', null, 'john@alwayson.net.au', null, 'trainer'],
        ];

        foreach ($students as [$firstName, $surname, $preferredName, $email, $specialNeeds, $gameRole]) {
            $displayName = trim(($preferredName ?: $firstName) . ' ' . $surname);

            DB::table('game_users')->updateOrInsert(
                [
                    'intake_id' => $intakeId,
                    'email' => $email,
                ],
                [
                    'first_name' => $firstName,
                    'surname' => $surname,
                    'preferred_name' => $preferredName,
                    'display_name' => $displayName,
                    'special_needs' => $specialNeeds,
                    'game_role' => $gameRole,
                    'game_status' => 'active',
                    'is_spy' => false,
                    'is_protector' => false,
                    'metadata' => json_encode([
                        'source' => 'Class 24.17 spreadsheet screenshot',
                        'class' => '24.17',
                        'course' => 'Cyber Security',
                    ]),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $intakeId = DB::table('game_intakes')->where('code', 'EQ-CYBER-24-17')->value('id');

        if ($intakeId) {
            DB::table('game_users')->where('intake_id', $intakeId)->delete();
            DB::table('game_intakes')->where('id', $intakeId)->delete();
        }
    }
};
