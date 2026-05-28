# 🔧 SPORT+ — Corrections appliquées

Projet audité et corrigé. Stack : Symfony 7.4 / PHP 8.4 / Doctrine ORM 3.6 / MySQL / Mercure / Stripe (à intégrer).

## ⚠️ À FAIRE EN PREMIER (chez toi, PHP 8.4)

```bash
composer install
php bin/console cache:clear
php bin/console lint:container          # doit passer

# La table user a été renommée `user` (mot réservé SQL) -> recréer la BDD :
php bin/console doctrine:database:drop --force
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n

# Créer un coach de test :
php bin/console app:create-coach coach@sportplus.fr motdepasse8 "Karim Coach" 45
```

Puis : register un client → /reservation/new → le coach voit la demande sur /coach/dashboard → accept → notif Mercure.

## 🔴 Bloquants corrigés
1. **Auth manquante** : créé SecurityController (login/logout), RegistrationController + RegistrationFormType (login auto), HomeController + route app_home, templates login/register/home. Le firewall pointait vers des routes inexistantes.
2. **Relations Doctrine cassées** : ajout User.coach (OneToOne inverse) et User.bookings (OneToMany inverse) qui manquaient.
3. **enable_native_lazy_objects** : RETIRÉ de doctrine.yaml. Sur DoctrineBundle 3.2+, ce flag ne doit plus être posé (deprecated, natif toujours actif, throw si présent). Requiert PHP 8.4.

## 🟠 Bugs logiques corrigés
- Calcul de durée bancal dans BookingController (getEndAt() null) -> durée déduite du serviceType dans BookingManager.
- getDurationMinutes() : priorité d'opérateur cassée -> intdiv().
- hourlyRate : float -> DECIMAL/string (cohérence argent avec price).
- Template : mercure('hub') -> mercure(topic) (le hub s'appelle 'default').
- Coach.isAvailableOnSlot() : compte maintenant aussi les 'pending' (sinon double réservation possible).
- BookingType : ajout de 'groupe_6' qui existait dans les prix mais pas dans le form.

## 🟡 Propreté / sécurité
- 3 repositories créés (User avec PasswordUpgrader, Coach, Booking avec findForCoach/findForClient).
- NotificationService : SerializerInterface retiré (composant non installé) -> json_encode natif.
- CSRF ajouté sur accept/refuse (POST qui modifient des données).
- #[IsGranted] au niveau classe sur BookingController et CoachController.
- security.yaml : role_hierarchy (COACH>CLIENT, ADMIN>COACH), access_control complet, target paths.
- Mercure configuré pour le dev (.env.dev : hub local symfony serve).
- base.html.twig : nav auth-aware (connexion/inscription vs espace/déconnexion).
- Page de suivi réservation (booking/status) avec mise à jour temps réel côté client.

## ✅ Testé
- Lint syntaxe : tout src/ OK.
- Logique métier (prix, durée, chevauchement créneaux, référence) : tests isolés OK.
- Cohérence relations + routes template/controller : vérifiée.
- NON testé ici : boot Doctrine complet (env de test en PHP 8.3, bundle exige 8.4). À valider chez toi.

## 🔜 Reste à faire
- Intégration Stripe Checkout (le champ stripeData existe, le tunnel reste à coder dans BookingManager::confirm).
- Son de notif : déposer un fichier dans public/sounds/notification.mp3.
- Profil coach éditable, gestion ROLE_ADMIN.
