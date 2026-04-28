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
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('module_id')->constrained()->onDelete('cascade');
            $table->enum('subscription_type', ['chapter', 'type', 'full']); // chapter only, by type (cours/td/tp), or full pack
            $table->foreignId('chapter_id')->nullable()->constrained('folders')->onDelete('cascade'); // If subscription is for specific chapter
            $table->json('resource_types')->nullable(); // For type-based subscription: ['cours', 'td']
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['active', 'expired', 'blocked'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
