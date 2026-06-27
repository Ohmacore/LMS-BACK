<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->string('provider')->default('jitsi');
            $table->string('provider_room')->nullable();
            $table->text('join_url')->nullable();
            $table->text('start_url')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'provider_room',
                'join_url',
                'start_url',
                'started_at',
                'ended_at',
                'cancelled_at',
            ]);
        });
    }
};
