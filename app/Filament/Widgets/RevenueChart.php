<?php

namespace App\Filament\Widgets;

use App\Models\Transaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RevenueChart extends ChartWidget
{
    protected static ?string $heading = 'Évolution des Revenus (12 derniers mois)';
    
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $startDate = now()->subMonths(11)->startOfMonth();
        $endDate = now()->endOfMonth();
        $monthExpression = $this->getMonthExpression();

        $transactions = Transaction::query()
            ->selectRaw('ABS(COALESCE(SUM(amount), 0)) as aggregate')
            ->selectRaw("{$monthExpression} as month")
            ->where('status', 'completed')
            ->where('type', 'purchase')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupByRaw($monthExpression)
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $data = collect();
        $labels = collect();

        // Ensure all 12 months are present, even if no data
        for ($i = 11; $i >= 0; $i--) {
            $monthDate = now()->subMonths($i)->startOfMonth();
            $monthKey = $monthDate->format('Y-m');
            
            $aggregate = $transactions->has($monthKey) ? $transactions->get($monthKey)->aggregate : 0;
            
            $data->push($aggregate);
            $labels->push($monthDate->translatedFormat('M Y'));
        }

        return [
            'datasets' => [
                [
                    'label' => 'Revenus (DZD)',
                    'data' => $data->toArray(),
                    'fill' => 'start',
                    'backgroundColor' => 'rgba(234, 179, 8, 0.2)',
                    'borderColor' => 'rgb(234, 179, 8)', // Amber primary color
                ],
            ],
            'labels' => $labels->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    private function getMonthExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', created_at)",
            'pgsql' => "TO_CHAR(created_at, 'YYYY-MM')",
            default => "DATE_FORMAT(created_at, '%Y-%m')",
        };
    }
}
