<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('game_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained('game_intakes')->cascadeOnDelete();
            $table->string('first_name');
            $table->string('surname');
            $table->string('preferred_name')->nullable();
            $table->string('display_name');
            $table->string('email');
            $table->text('special_needs')->nullable();
            $table->string('game_role', 50)->default('Creator');
            $table->string('game_status', 50)->default('active');
            $table->boolean('is_spy')->default(false);
            $table->boolean('is_protector')->default(false);
            $table->dateTime('action_locked_until')->nullable();
            $table->string('action_locked_reason', 255)->nullable();
            $table->unsignedBigInteger('action_locked_by_game_user_id')->nullable();
            $table->dateTime('eliminated_at')->nullable();
            $table->unsignedBigInteger('eliminated_by_game_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['intake_id', 'email']);
            $table->index(['intake_id', 'game_status']);
            $table->index(['intake_id', 'game_role']);
            $table->index(['is_spy', 'is_protector']);
            $table->index('action_locked_until');
        });

        DB::statement('ALTER TABLE users ALTER is_system_user SET DEFAULT 1');
        DB::statement('ALTER TABLE users ALTER is_game_user SET DEFAULT 0');

        DB::table('users')->update([
            'is_system_user' => 1,
            'is_game_user' => 0,
        ]);

        DB::table('game_intakes')->updateOrInsert(
            ['code' => 'EQ-CYBER-24-14'],
            [
                'name' => 'Equinim Cyber Class 24.14',
                'status' => 'active',
                'active_week' => 'week_1',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $intakeId = DB::table('game_intakes')->where('code', 'EQ-CYBER-24-14')->value('id');

        DB::table('dashboard_settings')->where('key', 'game_active_intake_id')->update([
            'value' => $intakeId,
            'updated_at' => now(),
        ]);
        DB::table('dashboard_settings')->where('key', 'game_active_week')->update([
            'value' => 'week_1',
            'updated_at' => now(),
        ]);

        $students = [
            ['Darren', 'Geer', null, 'djstrike@protonmail.com'],
            ['Rosalinda', 'Greenham', null, 'ladylinda0272@gmail.com'],
            ['Sandeep', 'Kaur', null, 'skaur1990@gmail.com'],
            ['Rooplaxmi', 'Ram', null, 'ramrooplaxmi@gmail.com'],
            ['Gavin', 'Botha', null, 'gavindbotha@outlook.com'],
            ['Wei Bin', 'Ang', null, 'weibinang@gmail.com'],
            ['Peechar', 'Kamsrikerd', null, 'peechar.kamsrikerd@hotmail.com'],
            ['Adrian (deferred)', 'Sommerville', null, 'adrian@4wards.com.au'],
            ['Thanh Tung', 'Doan', 'Tony', 'thanhtung08@outlook.com'],
            ['Tongesayi', 'Saruchera', 'Elisha', 'elishave@yahoo.com'],
            ['Cameron', 'Cook', null, 'cameron_cook@hotmail.com.au'],
            ['Thomas', 'Peacock', null, 'tommay82@gmail.com'],
            ['Oomajee', 'Dowlutrao', 'Ashley', 'ashley.dowlutrao@gmail.com'],
            ['Nicholas (deferred)', 'Wheatley', null, 'welshnick05@gmail.com'],
            ['Elliot', 'Ramsay', null, 'hawkers@eliptus.com'],
            ['Amita', 'Arora', null, 'amitataya@gmail.com'],
            ['Tasnim', 'Tajani', null, 'tasnim.tajani@hotmail.com'],
            ['Mihir', 'Mehta', null, 'mihirjmehta@yahoo.com'],
            ['Zach', 'Templeman', null, 'zachtempleman27@gmail.com'],
        ];

        foreach ($students as [$firstName, $surname, $preferredName, $email]) {
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
                    'special_needs' => null,
                    'game_role' => 'Creator',
                    'game_status' => 'active',
                    'is_spy' => false,
                    'is_protector' => false,
                    'metadata' => json_encode([
                        'source' => 'Class 24.14 spreadsheet screenshot',
                        'class' => '24.14',
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
        Schema::dropIfExists('game_users');

        DB::statement('ALTER TABLE users ALTER is_system_user SET DEFAULT 0');
        DB::statement('ALTER TABLE users ALTER is_game_user SET DEFAULT 0');
    }
};
