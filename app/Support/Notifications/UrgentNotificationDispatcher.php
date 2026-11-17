<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Settings\NotificationSettings;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Envoi d'une notification urgente (demande d'approbation, décision) : pendant la
 * requête si le réglage notifications.send_urgent_in_request est actif, sinon par
 * la file comme les autres (D20). Les notifications ordinaires utilisent l'envoi
 * habituel de Laravel.
 */
final class UrgentNotificationDispatcher
{
    public function __construct(private readonly NotificationSettings $settings) {}

    /**
     * @param  mixed  $notifiables  Destinataire ou collection de destinataires
     */
    public function send(mixed $notifiables, Notification $notification): void
    {
        if ($this->settings->send_urgent_in_request) {
            NotificationFacade::sendNow($notifiables, $notification);

            return;
        }

        NotificationFacade::send($notifiables, $notification);
    }
}
