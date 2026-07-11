<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * Substring LIKE search that treats user input literally.
 *
 * SQLite (our only DB) has no default LIKE escape character, so a user typing
 * "%" or "_" into a search box would otherwise inject wildcards (and a lone "%"
 * degrades into a full scan). These helpers escape those metacharacters and
 * pair every LIKE with an explicit `ESCAPE '\'` clause. Column names are always
 * developer-supplied, never user input, so they are interpolated directly.
 */
class LikeQuery
{
    /**
     * Escape LIKE metacharacters (\ % _) so the term matches literally.
     */
    public static function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * Register the whereLike / orWhereLike / whereLikeAny query macros. Called
     * once from AppServiceProvider::boot().
     */
    public static function registerMacros(): void
    {
        Builder::macro('whereLike', function (string $column, ?string $term): Builder {
            /** @var Builder $this */
            return $this->whereRaw("{$column} like ? escape '\\'", ['%'.LikeQuery::escape($term ?? '').'%']);
        });

        Builder::macro('orWhereLike', function (string $column, ?string $term): Builder {
            /** @var Builder $this */
            return $this->orWhereRaw("{$column} like ? escape '\\'", ['%'.LikeQuery::escape($term ?? '').'%']);
        });

        // Group an OR across several columns, e.g. (name LIKE ? OR sku LIKE ?).
        // No-ops on a blank term so it is safe to call unconditionally.
        Builder::macro('whereLikeAny', function (array $columns, ?string $term): Builder {
            /** @var Builder $this */
            if ($term === null || trim($term) === '') {
                return $this;
            }

            return $this->where(function (Builder $query) use ($columns, $term): void {
                foreach ($columns as $column) {
                    $query->orWhereLike($column, $term);
                }
            });
        });
    }
}
