<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Catalog\Actions\MoveCategory;
use Liberu\Ecommerce\Catalog\Exceptions\CategoryCycle;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource\Pages\CreateCategory;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource\Pages\EditCategory;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource\Pages\ListCategories;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Catalog\Models\Category;
use UnitEnum;

/**
 * The merchant's tree. A product sits in exactly one node.
 *
 * `parent_category_id` is on the create form and not on the edit form. Moving a
 * node is not an attribute change: `MoveCategory` refuses a cycle, and a
 * category moved under its own descendant leaves a ring with no root that never
 * terminates a breadcrumb walk again. So re-parenting is its own action, with
 * the descendants kept out of the options and the exception caught anyway.
 *
 * `slug` is on neither form. It is unique across the whole tree rather than
 * within a parent, because a category's URL is its slug — and an editable one
 * silently breaks every link already pointing at the node.
 */
class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Categories';

    protected static ?string $recordTitleAttribute = 'name';

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return PanelTeam::scope(parent::getEloquentQuery())->with('parent');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            // Create only, because `CreateCategory` takes the parent and
            // `MoveCategory` is the only thing allowed to change it afterwards.
            Select::make('parent_category_id')
                ->label('Parent')
                ->placeholder('None — a root category')
                ->options(fn (): array => self::parentOptions())
                ->searchable()
                ->visibleOn('create'),
            // Edit only. `CreateCategory` takes a name, a parent and a team and
            // nothing else, so a description typed on the create form would be
            // accepted by the form and dropped by the action.
            Textarea::make('description')
                ->rows(3)
                ->hiddenOn('create'),
            TextInput::make('image')
                ->helperText('A path or URL. This package stores no files of its own.')
                ->maxLength(255)
                ->hiddenOn('create'),
            TextInput::make('position')
                ->helperText('Lower sorts first among its siblings.')
                ->numeric()
                ->hiddenOn('create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder('Root'),
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
                self::moveAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    /**
     * Re-parenting, with the cycle guard the domain owns.
     *
     * The options already exclude the node and everything under it, so the
     * exception should be unreachable. It is caught regardless: two operators
     * re-parenting either end of the same branch in two tabs reach it, and
     * losing that race deserves a notification rather than a 500.
     */
    public static function moveAction(): Action
    {
        return Action::make('move')
            ->label('Move')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (Category $record): bool => Gate::allows('update', $record))
            ->fillForm(fn (Category $record): array => ['parent_category_id' => $record->parent_category_id])
            ->schema(fn (Category $record): array => [
                Select::make('parent_category_id')
                    ->label('New parent')
                    ->placeholder('None — promote to a root')
                    ->helperText('A category cannot move under itself or under one of its own descendants.')
                    ->options(fn (): array => self::parentOptions($record))
                    ->searchable(),
            ])
            ->action(function (Category $record, array $data): void {
                $parentId = $data['parent_category_id'] ?? null;

                try {
                    app(MoveCategory::class)->handle(
                        $record,
                        $parentId === null || $parentId === '' ? null : (int) $parentId,
                    );

                    Notification::make()
                        ->title('Category moved')
                        ->success()
                        ->send();
                } catch (CategoryCycle $exception) {
                    Notification::make()
                        ->title('Category not moved')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
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
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }

    /**
     * Every category the actor's team owns, minus the one being moved and
     * everything under it.
     *
     * @return array<int|string, string>
     */
    private static function parentOptions(?Category $except = null): array
    {
        $query = PanelTeam::scope(Category::query());

        if ($except !== null) {
            $query->whereNotIn('id', $except->descendantIds());
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }
}
