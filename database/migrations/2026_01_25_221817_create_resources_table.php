<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->enum('type', ['fiche', 'enonce', 'corrige', 'video', 'other'])->default('other');
            $table->string('file_path');
            $table->string('thumbnail_path')->nullable(); // For video thumbnails
            $table->boolean('is_public')->default(false);
            $table->bigInteger('size')->nullable(); // File size in bytes
            $table->integer('duration')->nullable(); // Video duration in seconds
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
