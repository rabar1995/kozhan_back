<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone-number login.
 *
 * Adds users.phone (unique when present). The legacy email column is
 * left untouched in the database but is no longer used for login or
 * user management. Existing users keep their username as a fallback
 * login identifier until a phone number is assigned to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('phone', 50)->nullable()->after('email');
                $t->unique('phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropUnique(['phone']);
                $t->dropColumn('phone');
            });
        }
    }
};
