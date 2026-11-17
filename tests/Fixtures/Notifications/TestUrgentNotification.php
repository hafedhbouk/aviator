<?php

namespace Tests\Fixtures\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notification de test, normalement mise en file, pour éprouver le réglage
 * notifications.send_urgent_in_request (D20).
 */
class TestUrgentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['message' => 'Nouvelle demande d\'approbation à traiter'];
    }
}
