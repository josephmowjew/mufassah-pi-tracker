<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'customer_id',
        'branch_id',
        'created_by_id',
        'status',
        'public_token',
        'recipient_email',
        'issued_at',
        'expires_at',
        'vat_applicable',
        'tax_rate',
        'tax_label',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'currency',
        'notes',
        'emailed_at',
        'redeemed_at',
        'cancelled_at',
        'cancellation_reason',
        'converted_sale_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'vat_applicable' => 'boolean',
            'tax_rate' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'emailed_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'reserved_invoice_id');
    }

    public static function generateInvoiceNumber(): string
    {
        $year = now()->format('Y');
        $lastInvoice = static::withTrashed()
            ->whereYear('created_at', $year)
            ->orderByDesc('id')
            ->first();

        $sequence = $lastInvoice ? ((int) substr($lastInvoice->invoice_number, -5)) + 1 : 1;

        return sprintf('INV-%s-%05d', $year, $sequence);
    }

    public function isExpired(): bool
    {
        return $this->expires_at?->isPast() ?? false;
    }

    public function isRedeemable(): bool
    {
        return $this->status === InvoiceStatus::SENT && ! $this->isExpired();
    }

    public function calculateTotals(): void
    {
        $this->subtotal = round((float) $this->items->sum('unit_price'), 2);
        $this->tax_amount = round((float) $this->items->sum('tax'), 2);
        $this->discount_amount = round((float) $this->items->sum('discount'), 2);
        $this->total_amount = round((float) $this->items->sum('total_price'), 2);
    }
}
