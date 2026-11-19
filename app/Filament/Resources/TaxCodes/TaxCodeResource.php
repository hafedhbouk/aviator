<?php

namespace App\Filament\Resources\TaxCodes;

use App\Enums\TaxNature;
use App\Filament\Resources\TaxCodes\Pages\ListTaxCodes;
use App\Filament\Resources\TaxCodes\Pages\ViewTaxCode;
use App\Filament\Resources\TaxCodes\RelationManagers\RulesRelationManager;
use App\Models\TaxCode;
use App\Models\TaxRule;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Codes fiscaux du référentiel validé (liste fermée, lecture seule) et versions de leurs
 * règles de TVA : écriture par le super admin, lecture par l'admin (TaxRulePolicy).
 */
class TaxCodeResource extends Resource
{
    protected static ?string $model = TaxCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Paramètres fiscaux';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'code fiscal';

    protected static ?string $pluralModelLabel = 'codes fiscaux et TVA';

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $slug = 'fiscal/codes';

    protected static bool $isGloballySearchable = false;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Code fiscal')->columns(3)->schema([
                TextEntry::make('code')->label('Code'),
                TextEntry::make('label')->label('Libellé'),
                TextEntry::make('nature')->label('Nature')->formatStateUsing(fn (TaxNature $state): string => $state->label()),
                IconEntry::make('requires_justification')->label('Justificatif obligatoire')->boolean(),
                TextEntry::make('legal_reference')->label('Base'),
                TextEntry::make('description')->label('Traitement')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('rules'))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('code')->label('Code')->fontFamily('mono'),
                TextColumn::make('label')->label('Libellé')->wrap(),
                TextColumn::make('nature')->label('Nature')->badge()
                    ->formatStateUsing(fn (TaxNature $state): string => $state->label()),
                TextColumn::make('current_rate')->label('Taux en vigueur')
                    ->state(fn (TaxCode $record): string => self::rateLabel(self::current($record)?->rate)),
                TextColumn::make('current_status')->label('Statut')->badge()
                    ->state(fn (TaxCode $record): string => self::current($record)?->validation_status->value ?? 'Aucune règle'),
                IconColumn::make('requires_justification')->label('Justificatif')->boolean(),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    /**
     * « 19.0000 » → « 19 % » ; « — » sans taux.
     */
    public static function rateLabel(?string $rate): string
    {
        return $rate === null ? '—' : rtrim(rtrim(str_replace('.', ',', $rate), '0'), ',').' %';
    }

    public static function current(TaxCode $code): ?TaxRule
    {
        $today = now()->toDateString();

        return $code->rules->first(fn (TaxRule $rule): bool => $rule->valid_from->toDateString() <= $today
            && ($rule->valid_to === null || $rule->valid_to->toDateString() >= $today));
    }

    public static function getRelations(): array
    {
        return [RulesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxCodes::route('/'),
            'view' => ViewTaxCode::route('/{record}'),
        ];
    }
}
