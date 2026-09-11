<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Category extends TenantModel
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $category->name = Str::squish($category->name);

            if (self::query()
                ->whereRaw('lower(name) = ?', [Str::lower($category->name)])
                ->when($category->exists, fn ($query) => $query->whereKeyNot($category->getKey()))
                ->exists()) {
                throw ValidationException::withMessages(['name' => 'This category already exists.']);
            }
        });
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
