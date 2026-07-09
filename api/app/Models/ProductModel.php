<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'name',
        'slug',
        'description',
        'image',
        'base_price',
        'specifications',
        'available_colors',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'specifications' => 'array',
            'available_colors' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ProductModel $model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name);
            }
        });

        static::updating(function (ProductModel $model) {
            if ($model->isDirty('name') && ! $model->isDirty('slug')) {
                $model->slug = Str::slug($model->name);
            }
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForBrand($query, int $brandId)
    {
        return $query->where('brand_id', $brandId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
