<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class StockTransferRequestItemResource extends JsonResource { public function toArray(Request $request): array { return ['id'=>$this->id,'device_id'=>$this->device_id,'device'=>new DeviceResource($this->whenLoaded('device'))]; } }
