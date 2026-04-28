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
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('domain_of_interest')->nullable();
            $table->string('year')->nullable(); // Academic year
            $table->text('bio')->nullable();
            $table->decimal('rating', 3, 2)->default(0); // 0.00 to 5.00
            $table->integer('total_students')->default(0);
            $table->string('bank_account')->nullable(); // BaridiMob account
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
