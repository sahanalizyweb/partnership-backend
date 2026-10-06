<?php

namespace App\Notifications;

use App\Models\PartnerDocument;
use Illuminate\Notifications\Notification;

class DocumentExpiringNotification extends Notification
{
    public function __construct(protected PartnerDocument $document) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_expiring',
            'partner_id' => $this->document->partner_id,
            'partner_name' => $this->document->partner->name,
            'document_type' => $this->document->documentType->name,
            'expiry_date' => $this->document->expiry_date?->toDateString(),
            'message' => "{$this->document->partner->name}'s {$this->document->documentType->name} expires on {$this->document->expiry_date?->toDateString()}",
        ];
    }
}
