<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Catalog\Actions\SyncProductTags;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\Ecommerce\Catalog\Models\Tag;

/**
 * The free-form labels on a product.
 *
 * No `AttachAction` and no `DetachAction`. Both write the pivot directly, and
 * the pivot is not where the rules are: `SyncProductTags` takes *names*, creates
 * the tags that do not exist, folds case and spacing so "Water Resistant" and
 * "water resistant" are one tag, and stays silent when nothing actually moved.
 * Attaching a row by id skips every one of those and dispatches no
 * `ProductTagsChanged`.
 *
 * So both surfaces here are whole-set writes: setting the tags submits the full
 * list, and removing one submits the list without it. That is the shape of the
 * action, and bending the UI to look like attach/detach would mean
 * reimplementing the folding rules on this side of the boundary.
 *
 * `Tag` has no policy at all — a tag is a shared word with no owner — so
 * authorization here is `update` on the **product**, which is what the domain
 * says tagging is authorized against.
 */
class TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static ?string $title = 'Tags';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                // The slug is what tags are matched on, so it is shown rather
                // than hidden as an implementation detail: it is the reason two
                // spellings turn out to be the same tag.
                TextColumn::make('slug')
                    ->label('Matched on'),
            ])
            ->headerActions([
                Action::make('setTags')
                    ->label('Set tags')
                    ->icon('heroicon-o-tag')
                    ->visible(fn (): bool => Gate::allows('update', $product))
                    ->fillForm(fn (): array => ['tags' => self::names($product)])
                    ->schema([
                        TagsInput::make('tags')
                            ->label('Tags')
                            ->helperText('The whole set, not an addition. Anything removed here is detached; anything new is created.'),
                    ])
                    ->action(function (array $data) use ($product): void {
                        app(SyncProductTags::class)->handle($product, self::submitted($data['tags'] ?? []));

                        Notification::make()
                            ->title('Tags set')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('removeTag')
                    ->label('Remove')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => Gate::allows('update', $product))
                    ->action(function (Tag $record) use ($product): void {
                        $remaining = array_values(array_filter(
                            self::names($product),
                            fn (string $name): bool => $name !== $record->name,
                        ));

                        app(SyncProductTags::class)->handle($product, $remaining);

                        Notification::make()
                            ->title('Tag removed')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('name');
    }

    /**
     * Read from the relation query rather than a loaded collection: this runs
     * again immediately after a sync, and a stale collection would hand the
     * action back the set it just replaced.
     *
     * @return list<string>
     */
    private static function names(Product $product): array
    {
        return array_map(strval(...), $product->tags()->pluck('name')->all());
    }

    /**
     * @return list<string>
     */
    private static function submitted(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $names = [];

        foreach ($tags as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $names[] = trim($tag);
            }
        }

        return $names;
    }
}
