<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'custno')) {
                $table->integer('custno')->nullable()->after('id');
            }

            if (! Schema::hasColumn('users', 'role_id')) {
                $table->unsignedBigInteger('role_id')->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'role_name')) {
                $table->string('role_name', 50)->nullable()->after('role_id');
            }

            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 20)->nullable()->default('Active')->after('role_name');
            }

            if (! Schema::hasColumn('users', 'updated_by')) {
                $table->string('updated_by')->nullable()->after('updated_at');
            }

            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->after('updated_by');
            }

            if (! Schema::hasColumn('users', 'avatar')) {
                $table->text('avatar')->nullable()->after('google_id');
            }

            if (! Schema::hasColumn('users', 'profile_image')) {
                $table->text('profile_image')->nullable()->after('avatar');
            }

            if (! Schema::hasColumn('users', 'company_name')) {
                $table->text('company_name')->nullable()->after('profile_image');
            }

            if (! Schema::hasColumn('users', 'gender')) {
                $table->string('gender', 50)->nullable()->after('company_name');
            }

            if (! Schema::hasColumn('users', 'location')) {
                $table->string('location')->nullable()->after('gender');
            }

            if (! Schema::hasColumn('users', 'address_1')) {
                $table->text('address_1')->nullable()->after('location');
            }

            if (! Schema::hasColumn('users', 'address_2')) {
                $table->text('address_2')->nullable()->after('address_1');
            }

            if (! Schema::hasColumn('users', 'address_3')) {
                $table->text('address_3')->nullable()->after('address_2');
            }

            if (! Schema::hasColumn('users', 'city')) {
                $table->string('city')->nullable()->after('address_3');
            }

            if (! Schema::hasColumn('users', 'state')) {
                $table->string('state')->nullable()->after('city');
            }

            if (! Schema::hasColumn('users', 'postcode')) {
                $table->string('postcode', 20)->nullable()->after('state');
            }

            if (! Schema::hasColumn('users', 'phone_no')) {
                $table->string('phone_no', 50)->nullable()->after('postcode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'phone_no',
                'postcode',
                'state',
                'city',
                'address_3',
                'address_2',
                'address_1',
                'location',
                'gender',
                'company_name',
                'profile_image',
                'avatar',
                'google_id',
                'updated_by',
                'status',
                'role_name',
                'role_id',
                'custno',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
