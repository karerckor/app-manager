<?php

namespace App\Models\Concerns;

use App\Support\Tags\TagRules;
use Illuminate\Database\Eloquent\Builder;

trait HasTags
{
    public function initializeHasTags(): void
    {
        $this->mergeCasts(['tags' => 'array']);
    }

    /**
     * @return array<int, array{0: string, 1: string|null}>
     */
    public function tagList(): array
    {
        return $this->tags ?? [];
    }

    public function hasTag(string $text): bool
    {
        $needle = mb_strtolower(trim($text));

        foreach ($this->tagList() as $tag) {
            if (mb_strtolower((string) ($tag[0] ?? '')) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function addTag(string $text, ?string $color = null): static
    {
        $this->tags = TagRules::normalize([...$this->tagList(), [$text, $color]]);

        return $this;
    }

    public function removeTag(string $text): static
    {
        $needle = mb_strtolower(trim($text));

        $this->tags = array_values(array_filter(
            $this->tagList(),
            fn ($tag) => mb_strtolower((string) ($tag[0] ?? '')) !== $needle,
        ));

        return $this;
    }

    public function syncTags(array $tags): static
    {
        $this->tags = TagRules::normalize($tags);

        return $this;
    }

    public function scopeWhereTag(Builder $query, string $text): Builder
    {
        return $query->where(fn (Builder $inner) => $this->applyTagPredicate($inner, [$text], 'and'));
    }

    public function scopeWhereAnyTag(Builder $query, array $texts): Builder
    {
        return $query->where(fn (Builder $inner) => $this->applyTagPredicate($inner, $texts, 'or'));
    }

    protected function applyTagPredicate(Builder $query, array $texts, string $boolean): Builder
    {
        $driver = $query->getConnection()->getDriverName();
        $column = $query->getModel()->qualifyColumn('tags');

        foreach ($texts as $text) {
            $sql = $driver === 'pgsql'
                ? "EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(({$column})::jsonb, '[]'::jsonb)) el WHERE el->>0 = ?)"
                : "EXISTS (SELECT 1 FROM json_each(COALESCE({$column}, '[]')) el WHERE json_extract(el.value, '\$[0]') = ?)";

            $query->whereRaw($sql, [$text], $boolean);
        }

        return $query;
    }
}
