<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('status', 'banned')
            ->update(['status' => 'blocked']);

        DB::table('users')
            ->where('status', 'inactive')
            ->update(['status' => 'pending']);

        $approvedTeacherUserIds = DB::table('teachers')
            ->where('status', 'approved')
            ->pluck('user_id');

        if ($approvedTeacherUserIds->isNotEmpty()) {
            DB::table('users')
                ->whereIn('id', $approvedTeacherUserIds)
                ->where('status', 'pending')
                ->update(['status' => 'active']);
        }

        $activeTeacherUserIds = DB::table('users')
            ->where('role', 'teacher')
            ->where('status', 'active')
            ->pluck('id');

        if ($activeTeacherUserIds->isNotEmpty()) {
            DB::table('teachers')
                ->whereIn('user_id', $activeTeacherUserIds)
                ->where('status', 'pending')
                ->update(['status' => 'approved']);
        }

        $rejectedTeacherUserIds = DB::table('teachers')
            ->where('status', 'rejected')
            ->pluck('user_id');

        if ($rejectedTeacherUserIds->isNotEmpty()) {
            DB::table('users')
                ->whereIn('id', $rejectedTeacherUserIds)
                ->update(['status' => 'blocked']);
        }
    }

    public function down(): void
    {
        //
    }
};
