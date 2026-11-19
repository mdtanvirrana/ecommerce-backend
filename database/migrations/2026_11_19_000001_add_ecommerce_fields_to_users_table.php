<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable()->after('email');
            $table->enum('user_type', ['customer', 'staff'])->default('customer')->after('phone');
            $table->boolean('is_active')->default(true)->after('user_type');
            $table->softDeletes();

            $table->index(['user_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['user_type', 'is_active']);
            $table->dropSoftDeletes();
            $table->dropColumn(['phone', 'user_type', 'is_active']);
        });
    }
};
