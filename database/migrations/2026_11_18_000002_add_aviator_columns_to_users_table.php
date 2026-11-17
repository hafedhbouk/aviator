<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('agency_id')->nullable()->after('password')
                ->constrained('agencies')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->after('agency_id');
            $table->dateTime('last_login_at')->nullable()->after('is_active');
            // 2FA par application (Filament) : secret et codes de récupération chiffrés.
            $table->text('app_authentication_secret')->nullable()->after('last_login_at');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
            $table->dropColumn([
                'is_active',
                'last_login_at',
                'app_authentication_secret',
                'app_authentication_recovery_codes',
            ]);
        });
    }
};
