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
        if (Schema::hasTable('user_login_history')) {
            return;
        }

        $isMySql = DB::connection()->getDriverName() === 'mysql';

        Schema::create('user_login_history', function (Blueprint $table) use ($isMySql) {
            if ($isMySql) {
                $table->charset = 'latin1';
                $table->collation = 'latin1_swedish_ci';
            }

            $table->integer('id', true);
            $table->integer('custno');
            $table->text('email')->nullable();
            $table->text('name')->nullable();
            $table->text('ip_address')->nullable();
            $table->string('ip_address_v4', 45)->nullable();
            $table->string('ip_address_v6', 45)->nullable();
            $table->dateTime('created_at');
            $userCountry = $table->string('user_country', 30)->nullable();
            $userRegion = $table->string('user_region', 30)->nullable();

            if ($isMySql) {
                $userCountry->charset('utf8')->collation('utf8_unicode_ci');
                $userRegion->charset('utf8')->collation('utf8_unicode_ci');
            }

            $table->string('user_city', 30)->nullable();
            $table->string('user_ZipCode', 10)->nullable();
            $table->string('user_timezone', 30)->nullable();
            $table->text('user_agent')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_login_history');
    }
};
