<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('protector_actor_masks')) {
            return;
        }

        Schema::create('protector_actor_masks', function (Blueprint $table) {
            $table->id();
            $table->string('protector_email')->index();
            $table->string('game_intake_code')->index();
            $table->string('masked_as_email')->nullable()->index();
            $table->boolean('enabled')->default(false)->index();
            $table->timestamps();

            $table->unique(['protector_email', 'game_intake_code'], 'protector_actor_masks_protector_intake_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protector_actor_masks');
    }
};
