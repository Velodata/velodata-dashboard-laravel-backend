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
            ['code' => 'EQ-WEBDEV-24-16'],
            [
                'name' => 'Equinim Web Dev Class 24.16',
                'status' => 'planned',
                'active_week' => 'week_1',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $intakeId = DB::table('game_intakes')->where('code', 'EQ-WEBDEV-24-16')->value('id');

        $students = [
            ['Janelle', 'Jose', null, 'Janelleruth.dizon08@gmail.com', null],
            ['James', 'MacLaren', null, 'cuchulainn28@gmail.com', null],
            ['Farshid', 'Yazdani', null, 'yazdanifarshid1@gmail.com', null],
            ['Craig', 'Williams', null, 'cwill081@gmail.com', 'Special needs'],
            ['Anoopama', 'Dowlutrao', null, 'anoo.dowlutrao@gmail.com', null],
            ['Luzien', 'Cowell', null, 'cluzien@gmail.com', null],
            ['Ariel', 'Reeves', null, 'ariel-reeves@hotmail.com', null],
            ['Sreya', 'Chakrabarti', null, 'sreya99@hotmail.com', null],
            ['Phoebe', 'Sharp', null, 'phoebesharp06@hotmail.com', null],
            ['Jusinda', 'Dyson', null, 'jusindadyson33@gmail.com', null],
            ['Ana', 'Lopez', null, 'alopez@westnet.com.au', null],
            ['Atoosa', 'Ferdosian Najafabadi', null, 'aferdosian76@gmail.com', null],
            ['Suresh', 'Sriramareddy', null, 'sureshsriramareddy@gmail.com', null],
            ['Mohammad', 'Jalwana', 'Asim', 'asimjalwana@gmail.com', null],
            ['Senthilkumar', 'Chandramani', null, 'coolcs01@gmail.com', null],
            ['Peter', 'Norman', null, 'peter@twoswans.com.au', null],
            ['Amaran', 'Chellan', null, 'amaranchellan@gmail.com', null],
            ['Dale', 'Osler', null, 'daleosler@gmail.com', null],
            ['Priya', 'Elumalai', null, 'priyappm92@gmail.com', null],
            ['Thieu (Catherine)', 'Vu', null, 'vuthieucatherine@gmail.com', null],
            ['Biancha', 'Guthrie', null, 'bibzie91@hotmail.com', null],
            ['Myung', 'Kang', 'Sun', 'sun.kang.609@gmail.com', null],
        ];

        foreach ($students as [$firstName, $surname, $preferredName, $email, $specialNeeds]) {
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
                    'game_role' => 'Creator',
                    'game_status' => 'active',
                    'is_spy' => false,
                    'is_protector' => false,
                    'metadata' => json_encode([
                        'source' => 'Class 24.16 spreadsheet screenshot',
                        'class' => '24.16',
                        'course' => 'Back End Web Development',
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
        $intakeId = DB::table('game_intakes')->where('code', 'EQ-WEBDEV-24-16')->value('id');

        if ($intakeId) {
            DB::table('game_users')->where('intake_id', $intakeId)->delete();
            DB::table('game_intakes')->where('id', $intakeId)->delete();
        }
    }
};
