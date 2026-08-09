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
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource\Pages\CreateCollection;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource\Pages\EditCollection;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource\Pages\ListCollections;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource\RelationManagers\ProductsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Catalog\Models\ProductCollection;
use UnitEnum;

/**
 * Merchandised groupings — "Summer", "Gifts under 50" — in a chosen order.
 *
 * Overlaps the category tree on purpose and does a different job: a category is
 * where a product *is*, a collection is where a merchant *put* it this month.
 *
 * The model is `ProductCollection` and the resource is `CollectionResource`,
 * because the class could not be called `Collection` in a Laravel package
 * without a permanent import collision. The label an operator reads is
 * "Collections" either way.
 */
class CollectionResource extends Resource
{
    protected static ?string $model = ProductCollection::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Collections';

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
            TextInput::make('image')
                ->helperText('A path or URL. This package stores no files of its own.')
                ->maxLength(255),
            TextInput::make('position')
                ->helperText('Lower sorts first among collections.')
                ->numeric()
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->counts('products'),
                TextColumn::make('position')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('position');
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            ProductsRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCollections::route('/'),
            'create' => CreateCollection::route('/create'),
            'edit' => EditCollection::route('/{record}/edit'),
        ];
    }
}
