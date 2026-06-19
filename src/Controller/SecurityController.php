<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    /** Durée de validité d'un lien de réinitialisation (1 heure). */
    private const RESET_LINK_TTL_SECONDS = 3600;

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Si déjà connecté, inutile de revoir le formulaire.
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        // Interceptée par le firewall (clé "logout"). Ne s'exécute jamais.
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    /**
     * Demande de réinitialisation de mot de passe.
     *
     * Le mot de passe actuel (hash) est inclus dans la signature HMAC du lien
     * envoyé par email → dès que le mot de passe change, l'ancien lien devient
     * automatiquement invalide. Pas besoin de table en base.
     */
    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        UserRepository $users,
        MailerService $mailer,
        UrlGeneratorInterface $urlGenerator,
    ): Response {
        $submitted = false;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot-password', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
                return $this->redirectToRoute('app_forgot_password');
            }

            $email = trim((string) $request->request->get('email', ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $user = $users->findOneBy(['email' => $email]);
                if ($user instanceof User) {
                    $ts        = time();
                    $token     = $this->buildResetToken($user, $ts);
                    $resetUrl  = $urlGenerator->generate(
                        'app_reset_password',
                        ['id' => $user->getId(), 'ts' => $ts, 'token' => $token],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    );
                    $mailer->sendPasswordReset($user, $resetUrl);
                }
            }

            // Anti-énumération : on affiche TOUJOURS le même message,
            // qu'on ait trouvé un compte ou non.
            $submitted = true;
        }

        return $this->render('security/forgot_password.html.twig', [
            'submitted' => $submitted,
        ]);
    }

    /**
     * Page de saisie du nouveau mot de passe.
     * Vérification : signature HMAC valide + lien créé il y a moins d'1 heure.
     */
    #[Route('/reinitialiser-mot-de-passe/{id}/{ts}/{token}', name: 'app_reset_password', methods: ['GET', 'POST'], requirements: ['id' => '\d+', 'ts' => '\d+', 'token' => '[a-f0-9]{32}'])]
    public function resetPassword(
        int $id,
        int $ts,
        string $token,
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $user = $users->find($id);
        if (!$user instanceof User) {
            $this->addFlash('error', 'Lien invalide ou expiré.');
            return $this->redirectToRoute('app_login');
        }

        // Vérif borne temporelle (1h)
        if ((time() - $ts) > self::RESET_LINK_TTL_SECONDS) {
            $this->addFlash('error', 'Ce lien a expiré (plus d\'1 heure). Refais une demande.');
            return $this->redirectToRoute('app_forgot_password');
        }
        if ($ts > time() + 60) {
            $this->addFlash('error', 'Lien invalide.');
            return $this->redirectToRoute('app_login');
        }

        // Vérif signature HMAC : le hash actuel du mdp est inclus → à usage unique
        $expected = $this->buildResetToken($user, $ts);
        if (!hash_equals($expected, $token)) {
            $this->addFlash('error', 'Lien invalide ou déjà utilisé.');
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset-password-' . $user->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');
                return $this->redirectToRoute('app_reset_password', ['id' => $id, 'ts' => $ts, 'token' => $token]);
            }

            $new     = (string) $request->request->get('password', '');
            $confirm = (string) $request->request->get('password_confirm', '');

            if (mb_strlen($new) < 8) {
                $this->addFlash('error', 'Le mot de passe doit faire au moins 8 caractères.');
                return $this->redirectToRoute('app_reset_password', ['id' => $id, 'ts' => $ts, 'token' => $token]);
            }
            if ($new !== $confirm) {
                $this->addFlash('error', 'Les deux mots de passe ne correspondent pas.');
                return $this->redirectToRoute('app_reset_password', ['id' => $id, 'ts' => $ts, 'token' => $token]);
            }

            $user->setPassword($hasher->hashPassword($user, $new));
            $em->flush();

            $this->addFlash('success', 'Ton mot de passe a été mis à jour. Tu peux te connecter avec.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'id'    => $id,
            'ts'    => $ts,
            'token' => $token,
            'user'  => $user,
        ]);
    }

    /**
     * Token HMAC signé qui dépend du hash du mot de passe actuel :
     * dès qu'il change, l'ancien lien devient automatiquement invalide.
     */
    private function buildResetToken(User $user, int $ts): string
    {
        $payload = sprintf('reset:%d:%d:%s', $user->getId(), $ts, $user->getPassword() ?? '');
        return substr(hash_hmac('sha256', $payload, (string) $this->getParameter('kernel.secret')), 0, 32);
    }
}
