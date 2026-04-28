<?php

namespace App\Filament\Widgets;

use App\Models\Transaction;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestTransactions extends BaseWidget
{
    protected static ?int $sort = 3;
    
    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Dernières Transactions';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Transaction::with(['student.user', 'module'])->latest()->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('student.user.name')
                    ->label('Étudiant'),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Type')
                    ->colors([
                        'primary' => 'deposit',
                        'success' => 'purchase',
                        'warning' => 'referral',
                    ])
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'deposit' => 'Recharge',
                        'purchase' => 'Achat',
                        'referral' => 'Parrainage',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant (DZD)')
                    ->numeric()
                    ->formatStateUsing(fn (string $state): string => number_format(abs((float)$state), 2, ',', ' ') . ' DZD'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Statut')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'completed',
                        'danger' => 'rejected',
                    ])
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'pending' => 'En Attente',
                        'completed' => 'Validé',
                        'rejected' => 'Rejeté',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->paginated(false);
    }
}
