<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Repository\ConversationRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ConversationController extends AbstractController
{
    #[Route('/messages', name: 'app_conversation_inbox', methods: ['GET'])]
    public function inbox(ConversationRepository $convRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user          = $this->getUser();
        $userId        = $user?->getId();
        $conversations = $convRepository->findForUser($user);

        // Comparaisons par ID partout (proxies lazy Doctrine cassent ===).
        $items = [];
        foreach ($conversations as $conv) {
            $lastMsg       = $conv->getLastMessage();
            $interlocuteur = ($conv->getClient()?->getId() === $userId)
                ? $conv->getCoach()?->getUser()
                : $conv->getClient();

            $items[] = [
                'conversation'  => $conv,
                'interlocuteur' => $interlocuteur,
                'lastMessage'   => $lastMsg,
                'isUnread'      => null !== $lastMsg
                    && $lastMsg->getAuthor()?->getId() !== $userId
                    && null === $lastMsg->getReadAt(),
            ];
        }

        return $this->render('conversation/inbox.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/messages/{id}', name: 'app_conversation_show', methods: ['GET'])]
    public function show(Conversation $conversation, EntityManagerInterface $em, ConversationRepository $convRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user        = $this->getUser();
        // Comparaison par ID (et pas par instance via ===) : avec
        // enable_native_lazy_objects, les getters Doctrine peuvent renvoyer
        // des proxies lazy différents de l'instance retournée par getUser(),
        // ce qui ferait échouer === et bloquerait le user de sa propre conv.
        $userId      = $user?->getId();
        $isClient    = $userId !== null && $conversation->getClient()?->getId() === $userId;
        $coachUserId = $conversation->getCoach()?->getUser()?->getId();
        $isCoach     = $coachUserId !== null && $coachUserId === $userId;

        if (!$isClient && !$isCoach) {
            throw $this->createAccessDeniedException();
        }

        // Marquer comme lus les messages de l'interlocuteur (un seul flush).
        // Comparaison par ID — cf bloc isClient/isCoach plus haut.
        $now     = new \DateTimeImmutable();
        $changed = false;
        foreach ($conversation->getMessages() as $msg) {
            if ($msg->getAuthor()?->getId() !== $userId && null === $msg->getReadAt()) {
                $msg->setReadAt($now);
                $changed = true;
            }
        }
        if ($changed) {
            $em->flush();
        }

        // Messages triés chronologiquement ASC
        $messages = $conversation->getMessages()->toArray();
        usort($messages, static fn (Message $a, Message $b): int => $a->getCreatedAt() <=> $b->getCreatedAt());

        $interlocuteur = $isClient
            ? $conversation->getCoach()?->getUser()
            : $conversation->getClient();

        // Sidebar: liste de toutes les conversations de l'utilisateur
        // (mêmes comparaisons par ID que la boucle inbox).
        $allConversations = $convRepository->findForUser($user);
        $sidebarItems     = [];
        foreach ($allConversations as $conv) {
            $lastMsg       = $conv->getLastMessage();
            $inter = ($conv->getClient()?->getId() === $userId)
                ? $conv->getCoach()?->getUser()
                : $conv->getClient();

            $sidebarItems[] = [
                'conversation'  => $conv,
                'interlocuteur' => $inter,
                'lastMessage'   => $lastMsg,
                'isUnread'      => null !== $lastMsg
                    && $lastMsg->getAuthor()?->getId() !== $userId
                    && null === $lastMsg->getReadAt(),
            ];
        }

        return $this->render('conversation/show.html.twig', [
            'conversation'  => $conversation,
            'interlocuteur' => $interlocuteur,
            'messages'      => $messages,
            'sidebarItems'  => $sidebarItems,
        ]);
    }

    #[Route('/messages/{id}/send', name: 'app_conversation_send', methods: ['POST'])]
    public function send(
        Conversation $conversation,
        Request $request,
        EntityManagerInterface $em,
        NotificationService $notifier,
    ): Response {
        /** @var \App\Entity\User $user */
        $user        = $this->getUser();
        // Idem show() : comparaison par ID pour contourner les proxies lazy.
        $userId      = $user?->getId();
        $isClient    = $userId !== null && $conversation->getClient()?->getId() === $userId;
        $coachUserId = $conversation->getCoach()?->getUser()?->getId();
        $isCoach     = $coachUserId !== null && $coachUserId === $userId;

        if (!$isClient && !$isCoach) {
            throw $this->createAccessDeniedException();
        }

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('msg' . $conversation->getId(), $token)) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_conversation_show', ['id' => $conversation->getId()]);
        }

        $content = trim((string) $request->request->get('content'));
        if ('' === $content || mb_strlen($content) > 2000) {
            $this->addFlash('error', 'Message invalide (vide ou trop long).');

            return $this->redirectToRoute('app_conversation_show', ['id' => $conversation->getId()]);
        }

        $message = new Message();
        $message
            ->setConversation($conversation)
            ->setAuthor($user)
            ->setContent($content);

        $em->persist($message);
        $em->flush();

        $recipient = $isClient
            ? $conversation->getCoach()?->getUser()
            : $conversation->getClient();

        if (null !== $recipient) {
            $notifier->notifyConversation($message, $recipient);
        }

        return $this->redirectToRoute('app_conversation_show', ['id' => $conversation->getId()]);
    }
}
