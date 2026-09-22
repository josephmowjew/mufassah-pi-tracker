<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
class StockTransferRequestItem extends Model {
    use SoftDeletes;
    protected $fillable = ['stock_transfer_request_id', 'device_id'];
    public function request(): BelongsTo { return $this->belongsTo(StockTransferRequest::class, 'stock_transfer_request_id'); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
}
