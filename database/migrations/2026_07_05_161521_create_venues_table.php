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
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique()->nullable();
            $table->string('building')->nullable();
            $table->string('faculty')->nullable();
            $table->unsignedInteger('capacity')->default(0);
            $table->enum('type', ['lecture_hall', 'laboratory', 'seminar_room', 'hall', 'other'])->default('lecture_hall');
            $table->enum('status', ['available', 'maintenance', 'disabled'])->default('available');
            $table->enum('source', ['manual', 'timetable_import'])->default('manual');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
