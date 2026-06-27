<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EnrollmentResource\Pages;
use App\Models\Enrollment;
use App\Models\Folder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EnrollmentResource extends Resource
{
    protected static ?string $model = Enrollment::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Inscriptions';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Détails de l\'inscription')
                    ->schema([
                        Forms\Components\Select::make('student_id')
                            ->label('Étudiant')
                            ->relationship('student.user', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn (?Enrollment $record) => $record !== null),

                        Forms\Components\Select::make('module_id')
                            ->label('Module')
                            ->relationship('module', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn (?Enrollment $record) => $record !== null),

                        Forms\Components\Select::make('subscription_type')
                            ->label('Type d\'inscription')
                            ->options([
                                'full'    => 'Module complet',
                                'chapter' => 'Chapitre',
                            ])
                            ->required(),

                        Forms\Components\Select::make('status')
                            ->label('Statut')
                            ->options([
                                'active'   => 'Actif',
                                'expired'  => 'Expiré',
                                'blocked' => 'Bloqué',
                            ])
                            ->required(),

                        Forms\Components\Select::make('chapter_id')
                            ->label('Chapitre (si type = chapitre)')
                            ->options(
                                Folder::whereNull('parent_folder_id')
                                    ->get()
                                    ->mapWithKeys(fn ($f) => [$f->id => "{$f->name} (Module #{$f->module_id})"])
                            )
                            ->searchable()
                            ->nullable()
                            ->disabled(fn (?Enrollment $record) => $record !== null),

                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('Expire le')
                            ->displayFormat('d/m/Y H:i')
                            ->nullable(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('student.user.name')
                    ->label('Étudiant')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('module.name')
                    ->label('Module')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('module.teacher.user.name')
                    ->label('Enseignant')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\BadgeColumn::make('subscription_type')
                    ->label('Type')
                    ->colors([
                        'primary' => 'full',
                        'info'    => 'chapter',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'full'    => 'Module complet',
                        'chapter' => 'Chapitre',
                        default   => $state,
                    }),

                Tables\Columns\TextColumn::make('chapter.name')
                    ->label('Chapitre')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Statut')
                    ->colors([
                        'success' => 'active',
                        'danger'  => 'expired',
                        'warning' => 'blocked',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active'   => 'Actif',
                        'expired'  => 'Expiré',
                        'blocked' => 'Bloqué',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expire le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->placeholder('Jamais'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Inscrit le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('subscription_type')
                    ->label('Type')
                    ->options([
                        'full'    => 'Module complet',
                        'chapter' => 'Chapitre',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'active'   => 'Actif',
                        'expired'  => 'Expiré',
                        'blocked' => 'Bloqué',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListEnrollments::route('/'),
            'create' => Pages\CreateEnrollment::route('/create'),
            'edit'   => Pages\EditEnrollment::route('/{record}/edit'),
        ];
    }
}
