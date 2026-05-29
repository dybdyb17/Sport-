<?php

namespace App\Controller\Admin;

use App\Entity\Coach;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Form\AdminCoachType;
use App\Repository\CoachRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/coachs')]
#[IsGranted('ROLE_ADMIN')]
class AdminCoachController extends AbstractController
{
    #[Route('', name: 'app_admin_coachs', methods: ['GET'])]
    public function index(CoachRepository $coachRepository): Response
    {
        return $this->render('admin/coachs/index.html.twig', [
            'coaches' => $coachRepository->findAllWithUser(),
        ]);
    }

    #[Route('/new', name: 'app_admin_coach_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $form = $this->createForm(AdminCoachType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data  = $form->getData();
            $email = (string) $data['email'];

            if ($em->getRepository(User::class)->findOneBy(['email' => $email])) {
                $this->addFlash('error', sprintf('Un compte existe déjà avec l\'adresse "%s".', $email));

                return $this->redirectToRoute('app_admin_coach_new');
            }

            $user = new User();
            $user
                ->setEmail($email)
                ->setNomComplet((string) $data['nomComplet'])
                ->setPhone($data['phone'] ?: null)
                ->setRole(UserRole::COACH);
            $user->setPassword($hasher->hashPassword($user, (string) $data['plainPassword']));

            $coach = new Coach();
            $coach
                ->setUser($user)
                ->setHourlyRate(number_format((float) $data['hourlyRate'], 2, '.', ''))
                ->setSpecialties((array) ($data['specialties'] ?? []));

            $em->persist($user);
            $em->persist($coach);
            $em->flush();

            $this->addFlash('success', sprintf('Coach "%s" créé avec succès.', $user->getNomComplet()));

            return $this->redirectToRoute('app_admin_coachs');
        }

        return $this->render('admin/coachs/new.html.twig', [
            'form' => $form,
        ]);
    }
}
