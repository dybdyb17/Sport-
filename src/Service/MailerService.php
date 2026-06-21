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
                ->from($this->from());
            if ($this->replyTo()) {
                $email->replyTo($this->replyTo());
            }
            $email
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
                ->from($this->from());
            if ($this->replyTo()) {
                $email->replyTo($this->replyTo());
            }
            $email
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
                ->from($this->from());
            if ($this->replyTo()) {
                $email->replyTo($this->replyTo());
            }
            $email
                ->to($client->getEmail())
                ->subject('Ta réservation n\'a pas pu être confirmée')
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
                ->from($this->from());
            if ($this->replyTo()) {
                $email->replyTo($this->replyTo());
            }
            $email
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
                ->from($this->from());
            if ($this->replyTo()) {
                $email->replyTo($this->replyTo());
            }
            $email
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
            $adminEmail = $_ENV['APP_EMAIL_ADMIN'] ?? 'ls.sportplus13@gmail.com';

            $email = (new TemplatedEmail())
                ->from($this->from());
            if ($this->replyTo()) {
                $email->replyTo($this->replyTo());
            }
            $email
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
            $_ENV['APP_EMAIL_FROM']      ?? 'contact@sportplus-13.com',
            $_ENV['APP_EMAIL_FROM_NAME'] ?? 'SPORT+ Marseille'
        );
    }

    private function replyTo(): ?string
    {
        $value = $_ENV['APP_EMAIL_REPLY_TO'] ?? null;
        return $value ?: null;
    }

    private function abs(string $route, array $params = []): string
    {
        return $this->urlGenerator->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Email de rappel J-1 : envoyé la veille de la séance avec un bouton
     * « Je confirme ma présence » qui pointe vers un lien signé HMAC.
     *
     * Retourne TRUE si l'email est parti, FALSE si une exception a été attrapée
     * ou si le client n'a pas d'email. L'appelant utilise ce booléen pour décider
     * de marquer (ou pas) Booking.reminderSentAt — un échec d'envoi doit pouvoir
     * être retenté au prochain cron, sinon on perdrait des rappels en silence.
     */
    public function sendDayBeforeReminder(Booking $booking, string $secret): bool
    {
        try {
            $client = $booking->getClient();
            if (!$client?->getEmail()) {
                return false;
            }
            $sig = substr(hash_hmac('sha256', 'confirm:' . $booking->getId(), $secret), 0, 32);
            $confirmUrl = $this->abs('app_booking_confirm_attendance', [
                'ref' => $booking->getReference(),
                'sig' => $sig,
            ]);

            $email = (new TemplatedEmail())
                ->from($this->from());
            if ($this->replyTo()) {
                $email->replyTo($this->replyTo());
            }
            $email
                ->to($client->getEmail())
                ->subject(sprintf('Rappel : ta séance avec %s, c\'est demain', (string) $booking->getCoach()?->getNomComplet()))
                ->htmlTemplate('emails/booking_day_before.html.twig')
                ->context([
                    'booking'    => $booking,
                    'coach'      => $booking->getCoach(),
                    'client'     => $client,
                    'confirmUrl' => $confirmUrl,
                    'rdvUrl'     => $this->abs('app_espace_client_rdv', ['reference' => $booking->getReference()]),
                ]);
            $this->mailer->send($email);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi email booking_day_before: ' . $e->getMessage(), [
                'booking' => $booking->getId(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);
            return false;
        }
    }

    /**
     * Récap quotidien à l'admin après l'exécution du cron J-1.
     * Sert de "dead man's switch" : tant que Loïc reçoit son ping quotidien
     * (même "0 mail envoyé"), il sait que le cron tourne. S'il ne reçoit rien
     * un matin, c'est que le cron a planté en silence.
     *
     * @param Booking[] $bookings Liste des bookings ciblés ce matin
     */
    public function sendDayBeforeReminderSummary(int $sent, int $skipped, array $bookings): void
    {
        try {
            $adminEmail = $_ENV['APP_EMAIL_ADMIN'] ?? 'ls.sportplus13@gmail.com';

            $email = (new TemplatedEmail())
                ->from($this->from());
            if ($this->replyTo()) {
                $email->replyTo($this->replyTo());
            }
            $email
                ->to($adminEmail)
                ->subject(sprintf('Rappels J-1 — %d envoyé(s) ce matin', $sent))
                ->htmlTemplate('emails/day_before_summary_admin.html.twig')
                ->context([
                    'sent'     => $sent,
                    'skipped'  => $skipped,
                    'bookings' => $bookings,
                    'runDate'  => new \DateTimeImmutable(),
                ]);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi récap J-1 admin: ' . $e->getMessage(), [
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    /**
     * Email de réinitialisation de mot de passe.
     * L'URL est un lien signé HMAC à usage unique (le hash du mot de passe actuel
     * est dans la signature → dès qu'il change, l'ancien lien devient invalide).
     */
    public function sendPasswordReset(User $user, string $resetUrl): void
    {
        try {
            if (!$user->getEmail()) {
                return;
            }

            $email = (new TemplatedEmail())
                ->from($this->from());
            if ($this->replyTo()) {
                $email->replyTo($this->replyTo());
            }
            $email
                ->to($user->getEmail())
                ->subject('Réinitialise ton mot de passe — SPORT+')
                ->htmlTemplate('emails/password_reset.html.twig')
                ->context([
                    'user'     => $user,
                    'resetUrl' => $resetUrl,
                ]);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi email password_reset: ' . $e->getMessage(), [
                'user' => $user->getEmail(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    /**
     * Message envoyé via le formulaire de contact public (/contact).
     *
     * Particularité : le Reply-To pointe vers l'email du VISITEUR (pas vers le gmail
     * de Loïc comme dans les autres méthodes), pour que Loïc puisse répondre direct
     * au client en tapant simplement "Répondre" depuis sa boîte Gmail.
     */
    public function sendContactMessage(string $name, string $email, ?string $phone, string $message): void
    {
        try {
            $adminEmail = $_ENV['APP_EMAIL_ADMIN'] ?? 'ls.sportplus13@gmail.com';

            $mail = (new TemplatedEmail())
                ->from($this->from())
                ->replyTo($email)
                ->to($adminEmail)
                ->subject(sprintf('Nouveau message de %s — SPORT+', $name))
                ->htmlTemplate('emails/contact_message.html.twig')
                ->context([
                    'name'    => $name,
                    'email'   => $email,
                    'phone'   => $phone,
                    'message' => $message,
                ]);

            $this->mailer->send($mail);
        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi email contact_message: ' . $e->getMessage(), [
                'visitor' => $email,
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }
}
