<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Message;
use App\Entity\User;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class NotificationService
{
    public function __construct(
        private readonly HubInterface $mercureHub,
    ) {
    }

    /**
     * Notifie un coach d'une nouvelle demande de réservation (temps réel via Mercure).
     */
    public function notifyCoach(Booking $booking): void
    {
        $coachUser = $booking->getCoach()?->getUser();
        if (!$coachUser) {
            return;
        }

        $data = [
            'type' => 'new_booking',
            'booking_id' => $booking->getId(),
            'ref' => $booking->getReference(),
            'client' => $booking->getClient()?->getNomComplet(),
            'start' => $booking->getStartAt()?->format('d/m H:i'),
            'price' => $booking->getPrixFormatted(),
            'message' => $booking->getMessage() ?: 'Aucun message',
            'sound' => true,
        ];

        $topic = sprintf('coach/%d/stream', $coachUser->getId());
        $this->publish($topic, $data);
    }

    /**
     * Notifie un client que sa réservation est confirmée.
     */
    public function notifyClient(Booking $booking): void
    {
        $client = $booking->getClient();
        if (!$client) {
            return;
        }

        $data = [
            'type' => 'booking_confirmed',
            'ref' => $booking->getReference(),
            'start' => $booking->getStartAt()?->format('d/m/Y à H:i'),
            'coach' => $booking->getCoach()?->getNomComplet(),
            'location' => 'SPORT+ Marseille 5ème',
            'price' => $booking->getPrixFormatted(),
        ];

        $topic = sprintf('client/%d/stream', $client->getId());
        $this->publish($topic, $data);
    }

    /**
     * Notifie le destinataire d'un nouveau message (topic partagé par les 2 participants).
     */
    public function notifyConversation(Message $message, User $recipient): void
    {
        $conversation = $message->getConversation();
        if (!$conversation) {
            return;
        }

        $data = [
            'type'            => 'new_message',
            'message_id'      => $message->getId(),
            'conversation_id' => $conversation->getId(),
            'author_id'       => $message->getAuthor()?->getId(),
            'author_name'     => $message->getAuthor()?->getNomComplet(),
            'content'         => $message->getContent(),
            'created_at'      => $message->getCreatedAt()->format('H:i'),
            'recipient_id'    => $recipient->getId(),
        ];

        $topic = sprintf('conversation/%d', $conversation->getId());
        $this->publish($topic, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function publish(string $topic, array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->mercureHub->publish(new Update($topic, $json, true));
    }
}
