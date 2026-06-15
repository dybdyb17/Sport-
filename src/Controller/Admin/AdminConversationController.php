<?php

namespace App\Controller\Admin;

use App\Repository\ConversationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/conversations')]
#[IsGranted('ROLE_ADMIN')]
class AdminConversationController extends AbstractController
{
    /**
     * Mots-clés "sensibles" surveillés dans les messages : leur présence est
     * surfacée sur la liste et highlightée dans la vue thread.
     * Lower-case, sans accents (la recherche normalise des deux côtés).
     */
    public const SENSITIVE_KEYWORDS = [
        // Paiement / argent
        'paiement', 'payer', 'paye', 'paye',
        'argent', 'cash', 'espece', 'especes',
        'rembours', 'remboursement',
        'facture', 'recu',
        // Fraude / litige
        'fraude', 'arnaque', 'escroc',
        'litige', 'conteste', 'contestation',
        'plainte', 'porter plainte',
        // Annulation
        'annul',
        // Hors site (potentiel contournement)
        'whatsapp', 'sms', 'téléphone', 'telephone', 'directement',
    ];

    #[Route('', name: 'app_admin_conversations', methods: ['GET'])]
    public function index(Request $request, ConversationRepository $repo): Response
    {
        $search   = trim((string) $request->query->get('q', ''));
        $fromStr  = (string) $request->query->get('from', '');
        $toStr    = (string) $request->query->get('to', '');
        $flagOnly = (bool) $request->query->get('flagged');

        $qb = $repo->createQueryBuilder('c')
            ->leftJoin('c.booking', 'b')
            ->leftJoin('b.client', 'cl')
            ->leftJoin('b.coach', 'co')
            ->leftJoin('co.user', 'cou')
            ->leftJoin('c.messages', 'm')
            ->addSelect('b', 'cl', 'co', 'cou')
            ->groupBy('c.id')
            ->orderBy('MAX(m.createdAt)', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC');

        if ($search !== '') {
            $qb->andWhere(
                'LOWER(m.content) LIKE :q '
              . 'OR LOWER(cl.firstName) LIKE :q OR LOWER(cl.lastName) LIKE :q '
              . 'OR LOWER(cou.firstName) LIKE :q OR LOWER(cou.lastName) LIKE :q'
            )->setParameter('q', '%' . mb_strtolower($search) . '%');
        }

        try {
            if ($fromStr !== '') {
                $qb->andWhere('c.createdAt >= :from')->setParameter('from', new \DateTimeImmutable($fromStr));
            }
            if ($toStr !== '') {
                $qb->andWhere('c.createdAt <= :to')->setParameter('to', new \DateTimeImmutable($toStr . ' 23:59:59'));
            }
        } catch (\Exception) {
            // dates invalides ignorées en silence
        }

        $conversations = $qb->getQuery()->getResult();

        // Annoter chaque conv avec un flag "contient mot-clé sensible"
        $annotated = [];
        foreach ($conversations as $conv) {
            $flagged = false;
            foreach ($conv->getMessages() as $msg) {
                if ($this->containsSensitive($msg->getContent())) {
                    $flagged = true;
                    break;
                }
            }
            if ($flagOnly && !$flagged) {
                continue;
            }
            $annotated[] = [
                'conversation' => $conv,
                'flagged'      => $flagged,
            ];
        }

        return $this->render('admin/conversations/index.html.twig', [
            'rows'     => $annotated,
            'search'   => $search,
            'from'     => $fromStr,
            'to'       => $toStr,
            'flagOnly' => $flagOnly,
            'sensitiveKeywords' => self::SENSITIVE_KEYWORDS,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_conversation_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request, ConversationRepository $repo): Response
    {
        $conversation = $repo->find($id);
        if (!$conversation) {
            throw $this->createNotFoundException();
        }
        return $this->render('admin/conversations/show.html.twig', [
            'conversation' => $conversation,
            'highlight'    => trim((string) $request->query->get('highlight', '')),
            'sensitiveKeywords' => self::SENSITIVE_KEYWORDS,
        ]);
    }

    private function containsSensitive(?string $text): bool
    {
        if ($text === null) return false;
        $normalized = $this->normalize($text);
        foreach (self::SENSITIVE_KEYWORDS as $kw) {
            if (str_contains($normalized, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII;', $s) ?: $s;
        return $s;
    }
}
