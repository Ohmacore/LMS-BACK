<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For SQLite, we need to recreate the table
        // For MySQL/PostgreSQL, we could use ALTER TABLE

        // Drop the old type column constraint and recreate
        Schema::table('resources', function (Blueprint $table) {
            // Add new columns we need
            $table->string('mime_type')->nullable()->after('file_path');
            $table->bigInteger('file_size')->nullable()->after('mime_type');
        });

        // For SQLite, we need to work around the enum limitation
        // We'll change the type column manually using raw SQL
        DB::statement("
            CREATE TABLE resources_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                folder_id INTEGER NOT NULL,
                name VARCHAR NOT NULL,
                type VARCHAR NOT NULL CHECK(type IN ('cours', 'TD', 'TP', 'exam', 'other')),
                file_path VARCHAR NOT NULL,
                mime_type VARCHAR,
                file_size BIGINT,
                thumbnail_path VARCHAR,
                is_public BOOLEAN NOT NULL DEFAULT 0,
                size BIGINT,
                duration INTEGER,
                `order` INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME,
                updated_at DATETIME,
                FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE CASCADE
            )
        ");

        // Copy data from old table to new
        DB::statement("
            INSERT INTO resources_new (id, folder_id, name, type, file_path, mime_type, file_size, thumbnail_path, is_public, size, duration, `order`, created_at, updated_at)
            SELECT id, folder_id, name, 
                CASE 
                    WHEN type = 'fiche' THEN 'cours'
                    WHEN type = 'enonce' THEN 'TD'
                    WHEN type = 'corrige' THEN 'TP'
                    WHEN type = 'video' THEN 'exam'
                    ELSE 'other'
                END,
                file_path, mime_type, file_size, thumbnail_path, is_public, size, duration, `order`, created_at, updated_at
            FROM resources
        ");

        // Drop old table and rename new one
        DB::statement("DROP TABLE resources");
        DB::statement("ALTER TABLE resources_new RENAME TO resources");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse: change back to old types
        DB::statement("
            CREATE TABLE resources_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                folder_id INTEGER NOT NULL,
                name VARCHAR NOT NULL,
                type VARCHAR NOT NULL CHECK(type IN ('fiche', 'enonce', 'corrige', 'video', 'other')),
                file_path VARCHAR NOT NULL,
                thumbnail_path VARCHAR,
                is_public BOOLEAN NOT NULL DEFAULT 0,
                size BIGINT,
                duration INTEGER,
                `order` INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME,
                updated_at DATETIME,
                FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE CASCADE
            )
        ");

        DB::statement("
            INSERT INTO resources_old (id, folder_id, name, type, file_path, thumbnail_path, is_public, size, duration, `order`, created_at, updated_at)
            SELECT id, folder_id, name,
                CASE 
                    WHEN type = 'cours' THEN 'fiche'
                    WHEN type = 'TD' THEN 'enonce'
                    WHEN type = 'TP' THEN 'corrige'
                    WHEN type = 'exam' THEN 'video'
                    ELSE 'other'
                END,
                file_path, thumbnail_path, is_public, size, duration, `order`, created_at, updated_at
            FROM resources
        ");

        DB::statement("DROP TABLE resources");
        DB::statement("ALTER TABLE resources_old RENAME TO resources");
    }
};
