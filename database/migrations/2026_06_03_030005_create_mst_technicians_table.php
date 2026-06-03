<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-0205 — mst_technicians. Optionally linked to a user account (ERD §5.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mst_technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('specialization', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mst_technicians');
    }
};
