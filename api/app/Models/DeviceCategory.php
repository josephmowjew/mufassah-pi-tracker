<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeviceCategory extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceCategoryFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the devices for the category.
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'category_id');
    }

    /**
     * Get the brands filed under the category.
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class, 'category_id');
    }

    /**
     * Soft deletes do not cascade at the database level, so deleting a
     * category has to hide its brands explicitly — and each brand hides its
     * own models in turn.
     */
    protected static function booted(): void
    {
        static::deleting(function (DeviceCategory $category) {
            if ($category->isForceDeleting()) {
                return;
            }

            $category->brands()->each(fn (Brand $brand) => $brand->delete());
        });

        static::restoring(function (DeviceCategory $category) {
            $category->brands()->withTrashed()->each(fn (Brand $brand) => $brand->restore());
        });
    }

    /**
     * Scope a query to only include active categories.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
