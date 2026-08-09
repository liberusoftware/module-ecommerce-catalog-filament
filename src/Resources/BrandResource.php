<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource\Pages\CreateBrand;
use Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource\Pages\EditBrand;
use Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource\Pages\ListBrands;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Catalog\Models\Brand;
use UnitEnum;

/**
 * Whose product this is, as the shopper understands it.
 *
 * Its own resource rather than a select-and-forget on the product form, because
 * a brand carries a description, a logo and a website that somebody has to
 * maintain somewhere — and because a shopper filters by it, which makes it a
 * thing rather than a label.
 *
 * Separate from `VendorResource` for the reason the domain keeps the two
 * columns apart: a shopper filters by the brand and a buyer chases the vendor,
 * and they are routinely different answers.
 */
class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Brands';

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
            Textarea::make('description')
                ->rows(3),
            TextInput::make('logo')
                ->helperText('A path or URL. This package stores no files of its own.')
                ->maxLength(255),
            TextInput::make('website')
                ->url()
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
                TextColumn::make('website')
                    ->placeholder('None'),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products'),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            'index' => ListBrands::route('/'),
            'create' => CreateBrand::route('/create'),
            'edit' => EditBrand::route('/{record}/edit'),
        ];
    }
}
