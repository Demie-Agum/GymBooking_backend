<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify the enum to include 'super_admin'
        // MySQL doesn't support ALTER ENUM directly, so we need to use raw SQL
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'staff', 'user') DEFAULT 'user'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to original enum values
        // First, update any super_admin users to admin
        DB::table('users')->where('role', 'super_admin')->update(['role' => 'admin']);
        
        // Then modify enum back
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'staff', 'user') DEFAULT 'user'");
    }
};

