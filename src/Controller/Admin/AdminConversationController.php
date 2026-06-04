<?php

namespace App\Controller\Admin;

use App\Repository\ConversationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/conversations')]
#[IsGranted('ROLE_ADMIN')]
class AdminConversationController extends AbstractController
{
    #[Route('', name: 'app_admin_conversations', methods: ['GET'])]
    public function index(ConversationRepository $repo): Response
    {
        $conversations = $repo->createQueryBuilder('c')
            ->leftJoin('c.booking', 'b')
            ->leftJoin('b.client', 'cl')
            ->leftJoin('b.coach', 'co')
            ->leftJoin('co.user', 'cou')
            ->addSelect('b', 'cl', 'co', 'cou')
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/conversations/index.html.twig', [
            'conversations' => $conversations,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_conversation_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, ConversationRepository $repo): Response
    {
        $conversation = $repo->find($id);
        if (!$conversation) {
            throw $this->createNotFoundException();
        }
        return $this->render('admin/conversations/show.html.twig', [
            'conversation' => $conversation,
        ]);
    }
}
