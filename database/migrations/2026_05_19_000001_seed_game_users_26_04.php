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
            ['code' => 'EQ-CYBER-26-04'],
            [
                'name' => 'Equinim Cyber Class 26.04',
                'status' => 'active',
                'active_week' => 'week_1',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $intakeId = DB::table('game_intakes')->where('code', 'EQ-CYBER-26-04')->value('id');

        $students = [
            ['Robert', 'Amos', null, 'rob.amos@gmail.com', null],
            ['Ravi', 'Chitturi', null, 'ravi.teja.chitturi@proton.me', null],
            ['Yvonne', 'Deynu', null, 'yvonnedeynu47@gmail.com', null],
            ['Hadassah', 'Kennett', null, 'hadassahkennett@outlook.com', null],
            ['Munya', 'Magwaro', null, 'radxzi@gmail.com', null],
            ['Adrian', 'Noonan', null, 'adrian.e.noonan@gmail.com', null],
            ['Johnny', 'Pham', null, 'johnno-98@hotmail.com', null],
            ['Roland', 'Santos', null, 'rolzroi@hotmail.com', null],
            ['Frederick', 'Shaw', null, 'shawfr@gmail.com', null],
            ['Linda', 'Tischhauser', null, 'workinghard@iinet.net.au', null],
            ['Ernest', 'Bondi', null, 'ernestbondi@outlook.com', null],
            ['Brayden', 'Bailye', null, 'braydenbailye@gmail.com', null],
            ['Olivia', 'Aquino', null, 'olive.garcia2674@gmail.com', null],
            ['Caroline', 'Seidu', null, 'carolineseidu16@gmail.com', null],
            ['Loveness', 'Moyo', null, 'loveness.moyo83@hotmail.com', null],
            ['Satish', 'Kumar', null, 'vaddepallysatish@gmail.com', null],
            ['Rebecca', 'Essenam', null, 'essenamrebecca12@gmail.com', null],
            ['Jake', 'Clarke', null, 'clarkejake332@gmail.com', null],
            ['Faiza', 'Bashir', null, 'faizab316@outlook.com', null],
            ['Eddiong', 'Amos', 'Eddy', 'eddyamos360@gmail.com', null],
            ['Rophael', 'Kanzi', null, 'Raphael.ganji@hotmail.com', null],
            ['Priya', 'Gusani', null, 'priya.gusani83@gmail.com', null],
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
                        'source' => 'Class 26.04 spreadsheet screenshot',
                        'class' => '26.04',
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
        $intakeId = DB::table('game_intakes')->where('code', 'EQ-CYBER-26-04')->value('id');

        if ($intakeId) {
            DB::table('game_users')->where('intake_id', $intakeId)->delete();
            DB::table('game_intakes')->where('id', $intakeId)->delete();
        }
    }
};
