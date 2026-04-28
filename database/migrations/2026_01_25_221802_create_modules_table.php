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
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->onDelete('cascade');
            $table->string('name'); // e.g., Algo_1_1ere_Info
            $table->string('subject'); // Matière
            $table->string('year'); // Année
            $table->string('level'); // Niveau
            $table->text('description')->nullable();
            $table->json('pricing_settings'); // {"price_per_chapter": 500, "price_cours_only": 2000, "price_td_only": 1500, "price_tp_only": 1000, "price_full_pack": 4000}
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
