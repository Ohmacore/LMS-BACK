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
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('pseudo')->nullable()->after('user_id');
            $table->string('domain')->nullable()->after('domain_of_interest'); // Cleaner name
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->after('year');
            $table->text('notes')->nullable()->after('status'); // Admin notes for rejection reason
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn(['pseudo', 'domain', 'status', 'notes']);
        });
    }
};
