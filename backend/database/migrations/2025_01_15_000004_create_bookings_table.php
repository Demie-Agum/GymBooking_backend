<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('gym_session_id')->constrained()->onDelete('cascade');
            $table->string('status', 20)->default('pending'); // pending until admin confirms, queued for platinum when full, cancelled
            $table->timestamps();
            
            // Prevent duplicate bookings
            $table->unique(['user_id', 'gym_session_id']);
            
            // Indexes for better query performance
            $table->index('user_id');
            $table->index('gym_session_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};



