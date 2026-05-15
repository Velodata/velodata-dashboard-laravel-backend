<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_intake_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('game_intake_id')->constrained('game_intakes')->cascadeOnDelete();
            $table->string('assignment_type', 50)->default('trainer');
            $table->boolean('active')->default(true);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['staff_user_id', 'game_intake_id', 'assignment_type'], 'staff_intake_assignment_unique');
            $table->index(['staff_user_id', 'active']);
            $table->index(['game_intake_id', 'active']);
            $table->index(['assignment_type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_intake_assignments');
    }
};
