<?php

namespace App\Controller\Admin;

use App\Entity\Coach;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Form\AdminCoachType;
use App\Repository\CoachRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

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
        SluggerInterface $slugger,
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
            $user->setPassword($hasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));

            $coach = new Coach();
            $coach
                ->setUser($user)
                ->setHourlyRate('40.00')
                ->setBio($data['bio'] ?: null)
                ->setSpecialties((array) ($data['specialties'] ?? []));

            $em->persist($user);
            $em->persist($coach);
            $em->flush();

            // Photo upload (après flush pour avoir l'ID)
            $this->handlePhotoUpload($form->get('photoFile')->getData(), $coach, $slugger, $em);

            $this->addFlash('success', sprintf('Coach "%s" créé avec succès.', $user->getNomComplet()));
            return $this->redirectToRoute('app_admin_coachs');
        }

        return $this->render('admin/coachs/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_admin_coach_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        int $id,
        Request $request,
        CoachRepository $coachRepository,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        SluggerInterface $slugger,
    ): Response {
        $coach = $coachRepository->find($id);
        if (!$coach) {
            throw $this->createNotFoundException('Coach introuvable.');
        }

        $user = $coach->getUser();

        $formData = [
            'email'       => $user->getEmail(),
            'nomComplet'  => $user->getNomComplet(),
            'phone'       => $user->getPhone(),
            'bio'         => $coach->getBio(),
            'specialties' => $coach->getSpecialties(),
        ];

        $form = $this->createForm(AdminCoachType::class, $formData, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $newEmail = (string) $data['email'];
            if ($newEmail !== $user->getEmail()) {
                $existing = $em->getRepository(User::class)->findOneBy(['email' => $newEmail]);
                if ($existing && $existing->getId() !== $user->getId()) {
                    $this->addFlash('error', sprintf('L\'adresse "%s" est déjà utilisée.', $newEmail));
                    return $this->redirectToRoute('app_admin_coach_edit', ['id' => $id]);
                }
            }

            $user->setEmail($newEmail);
            $user->setNomComplet((string) $data['nomComplet']);
            $user->setPhone($data['phone'] ?: null);

            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $user->setPassword($hasher->hashPassword($user, $plainPassword));
            }

            $coach->setBio($data['bio'] ?: null);
            $coach->setSpecialties((array) ($data['specialties'] ?? []));

            $this->handlePhotoUpload($form->get('photoFile')->getData(), $coach, $slugger, $em);

            $em->flush();

            $this->addFlash('success', sprintf('Coach %s mis à jour avec succès.', $user->getNomComplet()));
            return $this->redirectToRoute('app_admin_coachs');
        }

        return $this->render('admin/coachs/edit.html.twig', [
            'form'  => $form,
            'coach' => $coach,
        ]);
    }

    private function handlePhotoUpload(?UploadedFile $file, Coach $coach, SluggerInterface $slugger, EntityManagerInterface $em): void
    {
        if (!$file) {
            return;
        }

        $content = file_get_contents($file->getPathname());
        if (false !== $content) {
            $coach->setPhotoData(base64_encode($content));
            $coach->setPhotoMimeType($file->getMimeType() ?: 'image/jpeg');
        }

        // Garder aussi une copie dans public/img/coaches quand le disque est persistant
        // ou en local. En production Railway, la source fiable reste la BDD ci-dessus.
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/img/coaches';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        // Supprimer l'ancienne copie locale si elle existe.
        if ($coach->getPhotoFilename()) {
            $oldPath = $uploadDir . '/' . $coach->getPhotoFilename();
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
        }

        $safeNom  = $slugger->slug($coach->getNomComplet() ?? 'coach');
        $filename = strtolower($safeNom . '-' . uniqid() . '.' . ($file->guessExtension() ?: 'jpg'));

        $file->move($uploadDir, $filename);

        $coach->setPhotoFilename($filename);
        $em->flush();
    }
}
