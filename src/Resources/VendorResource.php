<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource\Pages\CreateVendor;
use Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource\Pages\EditVendor;
use Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource\Pages\ListVendors;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Catalog\Models\Vendor;
use UnitEnum;

/**
 * Who the merchant gets it from.
 *
 * Thin, like the domain's model. Terms, settlement, purchase orders and lead
 * times belong to whichever module actually transacts with the vendor; what the
 * catalogue needs is an attribution it can group and filter by, and somewhere to
 * look up who to ring. Adding a payment-terms field here would make this package
 * look like the system of record for something it does not enforce.
 */
class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Vendors';

    protected static ?string $recordTitleAttribute = 'name';

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return PanelTeam::scope(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('contact_email')
                ->label('Contact email')
                ->email()
                ->maxLength(255),
            TextInput::make('contact_phone')
                ->label('Contact phone')
                ->tel()
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contact_email')
                    ->label('Contact email')
                    ->placeholder('None'),
                TextColumn::make('contact_phone')
                    ->label('Contact phone')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
            'create' => CreateVendor::route('/create'),
            'edit' => EditVendor::route('/{record}/edit'),
        ];
    }
}
