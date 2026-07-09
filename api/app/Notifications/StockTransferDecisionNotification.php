<?php
namespace App\Notifications;
use App\Models\StockTransferRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
class StockTransferDecisionNotification extends Notification { use Queueable; public function __construct(public StockTransferRequest $request) {} public function via(object $notifiable): array { return ['database']; } public function toArray(object $notifiable): array { return ['type'=>'stock_transfer_decision','title'=>'Stock transfer updated','message'=>"{$this->request->request_number} was {$this->request->status}",'transfer_request_id'=>$this->request->id,'status'=>$this->request->status]; } }
