<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\MessageRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose `unread_messages_count()` en Twig pour afficher le badge
 * de messages non lus dans la navigation.
 */
class MessagingExtension extends AbstractExtension
{
    private ?int $cache = null;

    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly Security          $security,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_messages_count', $this->getUnreadCount(...)),
        ];
    }

    public function getUnreadCount(): int
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return $this->cache = 0;
        }

        return $this->cache = $this->messageRepository->countUnreadForUser($user);
    }
}
