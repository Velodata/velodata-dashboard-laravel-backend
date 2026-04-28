<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        for ($i = 0; $i < 10; $i++) {
            DB::table('tasks')->insert([
                'title' => $faker->sentence(4),
                'description' => $faker->paragraph,
                'due_date' => $faker->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
                'completed' => $faker->boolean(30),
                'user_email' => $faker->safeEmail,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }
}
