<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\DocumentExpiringNotification;
use App\Services\DocumentExpiryService;
use Illuminate\Console\Command;

class CheckDocumentExpiry extends Command
{
    protected $signature = 'documents:check-expiry {--days=30}';

    protected $description = 'Notify managers about partner documents expiring soon';

    public function handle(DocumentExpiryService $service): int
    {
        $expiring = $service->expiringWithin((int) $this->option('days'));

        if ($expiring->isEmpty()) {
            $this->info('No documents expiring soon.');

            return self::SUCCESS;
        }

        $managers = User::role(['Super Administrator', 'Partnership Manager'])->get();

        foreach ($expiring as $document) {
            foreach ($managers as $manager) {
                if (! $manager->business_id || $manager->business_id === $document->partner->business_id) {
                    $manager->notify(new DocumentExpiringNotification($document));
                }
            }
        }

        $this->info("Notified managers about {$expiring->count()} expiring document(s).");

        return self::SUCCESS;
    }
}
