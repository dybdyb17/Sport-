<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\FoundingClaim;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MailerService
{
    public function __construct(
        private readonly MailerInterface       $mailer,
        private readonly LoggerInterface       $logger,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function sendBookingPendingToCoach(Booking $booking): void
    {
        try {
            $coach = $booking->getCoach();
            if (!$coach?->getUser()?->getEmail()) {
                return;
            }

            $email = (new TemplatedEmail())
                ->from($this->from())
                ->to($coach->getUser()->getEmail())
                ->subject(sprintf(
                    '🔔 Nouvelle demande de réservation — %s à %s',
                    $booking->getClient()->getNomComplet(),
                    $booking->getStartAt()->format('H\hi')
                ))
                ->htmlTemplate('emails/booking_pending_coach.html.twig')
                ->context([
                    'booking'      => $booking,
                    'coach'        => $coach,
                    'client'       => $booking->getClient(),
                    'dashboardUrl' => $this->abs('app_coach_dashboard'),
                ]);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi email booking_pending_coach: ' . $e->getMessage(), [
                'booking' => $booking->getId(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    public function sendBookingConfirmedToClient(Booking $booking): void
    {
        try {
            $client = $booking->getClient();
            if (!$client->getEmail()) {
                return;
            }

            $email = (new TemplatedEmail())
                ->from($this->from())
                ->to($client->getEmail())
                ->subject(sprintf('Ta séance avec %s est confirmée', $booking->getCoach()->getNomComplet()))
                ->htmlTemplate('emails/booking_confirmed_client.html.twig')
                ->context([
                    'booking'         => $booking,
                    'coach'           => $booking->getCoach(),
                    'client'          => $client,
                    'espaceClientUrl' => $this->abs('app_espace_client'),
                    'inboxUrl'        => $this->abs('app_conversation_inbox'),
                ]);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi email booking_confirmed_client: ' . $e->getMessage(), [
                'booking' => $booking->getId(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    public function sendBookingRefusedToClient(Booking $booking, ?string $reason = null): void
    {
        try {
            $client = $booking->getClient();
            if (!$client->getEmail()) {
                return;
            }

            $email = (new TemplatedEmail())
                ->from($this->from())
                ->to($client->getEmail())
                ->subject('Ta reservation n\'a pas pu etre confirmee')
                ->htmlTemplate('emails/booking_refused_client.html.twig')
                ->context([
                    'booking'    => $booking,
                    'coach'      => $booking->getCoach(),
                    'client'     => $client,
                    'reason'     => $reason,
                    'coachsUrl'  => $this->abs('app_coachs_list'),
                    'bookingUrl' => $this->abs('app_booking_new'),
                ]);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi email booking_refused_client: ' . $e->getMessage(), [
                'booking' => $booking->getId(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    public function sendFoundingWelcomeToUser(FoundingClaim $claim): void
    {
        try {
            $user = $claim->getUser();
            if (!$user->getEmail()) {
                return;
            }

            $email = (new TemplatedEmail())
                ->from($this->from())
                ->to($user->getEmail())
                ->subject(sprintf('Bienvenue parmi les Membres Fondateurs #%02d', $claim->getClaimNumber()))
                ->htmlTemplate('emails/founding_welcome.html.twig')
                ->context([
                    'claim'      => $claim,
                    'user'       => $user,
                    'offer'      => $claim->getOffer(),
                    'bookingUrl' => $this->abs('app_booking_new'),
                ]);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi email founding_welcome: ' . $e->getMessage(), [
                'claim' => $claim->getId(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    public function sendRegistrationWelcome(User $user): void
    {
        try {
            if (!$user->getEmail()) {
                return;
            }

            $email = (new TemplatedEmail())
                ->from($this->from())
                ->to($user->getEmail())
                ->subject('Bienvenue chez SPORT+ Marseille')
                ->htmlTemplate('emails/registration_welcome.html.twig')
                ->context([
                    'user'            => $user,
                    'coachsUrl'       => $this->abs('app_coachs_list'),
                    'foundingUrl'     => $this->abs('app_founding_detail'),
                    'espaceClientUrl' => $this->abs('app_espace_client'),
                ]);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi email registration_welcome: ' . $e->getMessage(), [
                'user' => $user->getEmail(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    public function sendNewFoundingAlertToAdmin(FoundingClaim $claim): void
    {
        try {
            $adminEmail = $_ENV['APP_EMAIL_ADMIN'] ?? 'admin@sportplus-marseille.fr';

            $email = (new TemplatedEmail())
                ->from($this->from())
                ->to($adminEmail)
                ->subject(sprintf('Nouveau Membre Fondateur - #%02d', $claim->getClaimNumber()))
                ->htmlTemplate('emails/founding_alert_admin.html.twig')
                ->context([
                    'claim'        => $claim,
                    'user'         => $claim->getUser(),
                    'offer'        => $claim->getOffer(),
                    'adminListUrl' => $this->abs('app_admin_founding_list'),
                ]);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi email founding_alert_admin: ' . $e->getMessage(), [
                'claim' => $claim->getId(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    private function from(): Address
    {
        return new Address(
            $_ENV['APP_EMAIL_FROM']      ?? 'contact@sportplus-marseille.fr',
            $_ENV['APP_EMAIL_FROM_NAME'] ?? 'SPORT+ Marseille'
        );
    }

    private function abs(string $route, array $params = []): string
    {
        return $this->urlGenerator->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
