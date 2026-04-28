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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->enum('type', ['deposit', 'purchase', 'referral']); // deposit (wallet recharge), purchase (buying module), referral (reward)
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'completed', 'rejected'])->default('pending');
            $table->string('receipt_url')->nullable(); // For BaridiMob receipt upload
            $table->foreignId('validated_by')->nullable()->constrained('users')->onDelete('set null'); // Teacher/Admin who validated
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('module_id')->nullable()->constrained()->onDelete('set null'); // For purchase transactions
            $table->text('notes')->nullable(); // Admin notes or rejection reason
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
