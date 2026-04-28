<?php

namespace App\Filament\Widgets;

use App\Models\Enrollment;
use App\Models\Module;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Transaction;
use App\Models\Withdrawal;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Calculate total completed revenue
        $totalRevenue = abs(Transaction::where('status', 'completed')
            ->where('type', 'purchase')
            ->sum('amount'));

        // Get total entities
        $totalTeachers = Teacher::count();
        $totalStudents = Student::count();
        $totalModules = Module::count();

        // Get enrollments (active)
        $activeEnrollments = Enrollment::where('status', 'active')->count();

        // Get pending withdrawals amount
        $pendingWithdrawals = Withdrawal::where('status', 'pending')->sum('amount');

        return [
            Stat::make('Revenu Total', number_format($totalRevenue, 2) . ' DZD')
                ->description('Achats de modules complétés')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Enseignants & Étudiants', "{$totalTeachers} / {$totalStudents}")
                ->description('Utilisateurs inscrits sur la plateforme')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('Modules & Inscriptions', "{$totalModules} / {$activeEnrollments}")
                ->description('Modules disponibles et inscriptions actives')
                ->descriptionIcon('heroicon-m-academic-cap')
                ->color('info'),

            Stat::make('Retraits en attente', number_format($pendingWithdrawals, 2) . ' DZD')
                ->description(Withdrawal::where('status', 'pending')->count() . ' demande(s) en attente')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingWithdrawals > 0 ? 'warning' : 'success'),
        ];
    }
}
