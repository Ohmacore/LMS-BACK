<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Teacher;
use App\Models\Student;
use App\Models\Module;
use App\Models\Folder;
use App\Models\Resource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create Admin User
        $admin = User::create([
            'name' => 'Admin User',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'pseudo' => 'admin',
            'email' => 'admin@elearning.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Create Teachers
        $teacher1 = User::create([
            'name' => 'Prof. Ahmed Mansouri',
            'first_name' => 'Ahmed',
            'last_name' => 'Mansouri',
            'pseudo' => 'prof_ahmed',
            'email' => 'ahmed@elearning.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $teacherProfile1 = Teacher::create([
            'user_id' => $teacher1->id,
            'domain_of_interest' => 'Informatique',
            'year' => '2024-2025',
            'bio' => 'Enseignant expérimenté en algorithmique et structures de données avec 10 ans d\'expérience.',
            'rating' => 4.8,
            'total_students' => 150,
            'bank_account' => '0123456789',
            'status' => 'approved',
        ]);

        $teacher2 = User::create([
            'name' => 'Dr. Fatima Benali',
            'first_name' => 'Fatima',
            'last_name' => 'Benali',
            'pseudo' => 'dr_fatima',
            'email' => 'fatima@elearning.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $teacherProfile2 = Teacher::create([
            'user_id' => $teacher2->id,
            'domain_of_interest' => 'Mathématiques',
            'year' => '2024-2025',
            'bio' => 'Docteur en mathématiques appliquées, spécialisée en analyse et algèbre.',
            'rating' => 4.9,
            'total_students' => 200,
            'bank_account' => '9876543210',
            'status' => 'approved',
        ]);

        // Create Students
        $referralCodes = [];
        for ($i = 1; $i <= 5; $i++) {
            $student = User::create([
                'name' => "Étudiant {$i}",
                'first_name' => "Étudiant",
                'last_name' => "{$i}",
                'pseudo' => "student{$i}",
                'email' => "student{$i}@elearning.com",
                'password' => Hash::make('password'),
                'role' => 'student',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            $referralCode = strtoupper(Str::random(8));
            $referralCodes[] = $referralCode;

            Student::create([
                'user_id' => $student->id,
                'wallet_balance' => rand(0, 5000),
                'referral_code' => $referralCode,
                'referred_by' => $i > 1 ? $i - 1 : null, // Student 2+ referred by previous student
            ]);
        }

        // Create Modules for Teacher 1 (Ahmed - Informatique)
        $module1 = Module::create([
            'teacher_id' => $teacherProfile1->id,
            'name' => 'Algo_1_1ere_Info',
            'subject' => 'Algorithmique',
            'year' => '1ère année',
            'level' => 'Licence Informatique',
            'description' => 'Introduction à l\'algorithmique : structures de données, tri, recherche.',
            'pricing_settings' => json_encode([
                'price_per_chapter' => 500,
                'price_cours_only' => 2000,
                'price_td_only' => 1500,
                'price_tp_only' => 1000,
                'price_full_pack' => 4000,
            ]),
        ]);

        // Create folder structure for Module 1
        $coursFolder = Folder::create([
            'module_id' => $module1->id,
            'name' => 'Cours',
            'type' => 'cours',
            'order' => 1,
        ]);

        $tdFolder = Folder::create([
            'module_id' => $module1->id,
            'name' => 'TD',
            'type' => 'td',
            'order' => 2,
        ]);

        $tpFolder = Folder::create([
            'module_id' => $module1->id,
            'name' => 'TP',
            'type' => 'tp',
            'order' => 3,
        ]);

        $enregFolder = Folder::create([
            'module_id' => $module1->id,
            'name' => 'Enregistrements',
            'type' => 'enregistrements',
            'order' => 4,
        ]);

        // Create chapters in Cours folder
        $chapitre1 = Folder::create([
            'module_id' => $module1->id,
            'parent_folder_id' => $coursFolder->id,
            'name' => 'Chapitre 1: Introduction',
            'type' => 'chapter',
            'chapter_number' => 1,
            'order' => 1,
        ]);

        $chapitre2 = Folder::create([
            'module_id' => $module1->id,
            'parent_folder_id' => $coursFolder->id,
            'name' => 'Chapitre 2: Structures de données',
            'type' => 'chapter',
            'chapter_number' => 2,
            'order' => 2,
        ]);

        // Add sample resources
        Resource::create([
            'folder_id' => $chapitre1->id,
            'name' => 'Introduction à l\'algorithmique',
            'type' => 'fiche',
            'file_path' => 'modules/algo1/cours/chapitre1/intro.pdf',
            'is_public' => true,
            'size' => 524288, // 512KB
            'order' => 1,
        ]);

        Resource::create([
            'folder_id' => $chapitre1->id,
            'name' => 'Vidéo: Concepts de base',
            'type' => 'video',
            'file_path' => 'modules/algo1/cours/chapitre1/video1.mp4',
            'thumbnail_path' => 'modules/algo1/cours/chapitre1/video1_thumb.jpg',
            'is_public' => false,
            'size' => 104857600, // 100MB
            'duration' => 1800, // 30 minutes
            'order' => 2,
        ]);

        // Create Module 2 for Teacher 2 (Fatima - Maths)
        $module2 = Module::create([
            'teacher_id' => $teacherProfile2->id,
            'name' => 'Analyse_1_1ere_Math',
            'subject' => 'Analyse Mathématique',
            'year' => '1ère année',
            'level' => 'Licence Mathématiques',
            'description' => 'Analyse réelle : limites, continuité, dérivabilité, intégration.',
            'pricing_settings' => json_encode([
                'price_per_chapter' => 600,
                'price_cours_only' => 2500,
                'price_td_only' => 2000,
                'price_tp_only' => 0, // Pas de TP pour ce module
                'price_full_pack' => 4200,
            ]),
        ]);

        // Create basic structure for Module 2
        Folder::create([
            'module_id' => $module2->id,
            'name' => 'Cours',
            'type' => 'cours',
            'order' => 1,
        ]);

        Folder::create([
            'module_id' => $module2->id,
            'name' => 'TD',
            'type' => 'td',
            'order' => 2,
        ]);

        Folder::create([
            'module_id' => $module2->id,
            'name' => 'Enregistrements',
            'type' => 'enregistrements',
            'order' => 3,
        ]);

        $this->command->info('Database seeded successfully!');
        $this->command->info('');
        $this->command->info('Login credentials:');
        $this->command->info('Admin: admin@elearning.com / password');
        $this->command->info('Teacher 1: ahmed@elearning.com / password');
        $this->command->info('Teacher 2: fatima@elearning.com / password');
        $this->command->info('Students: student1@elearning.com to student5@elearning.com / password');
    }
}
