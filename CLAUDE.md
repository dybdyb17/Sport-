# CLAUDE.md — SPORT+ Marseille

> Fichier de contexte lu automatiquement par Claude Code au démarrage de chaque session.
> Il décrit le projet, son état, et la façon de travailler dessus. Tiens-le à jour.

---

## ⚡ À lire en premier

- **SPORT+ est un VRAI site de PRODUCTION**, pas un projet d'école. Le boss est **Loïc
  Taillefond** (entité juridique **LS SPORT SAS**, salle premium 24/24 au 164 Chemin de Saint-
  Jean du Désert, 13005 Marseille). Traite tout avec rigueur prod : sécurité, perf,
  accessibilité, RGPD, SEO.
- **Le seul projet DWWM de Dybril est Lakers Newz** — ne jamais mélanger les sujets de
  certification (dossier pro, mémoire) avec SPORT+.
- **Skills perso à utiliser** : `symfony-dybril` (conventions + env), `railway-symfony-deploy`
  (déploiement), `frontend-design` (UI). Consulte-les quand c'est pertinent — ils encodent
  l'environnement, les conventions et la recette de déploiement de Dybril.
- **Workflow de Dybril** : duo Claude (archi/prompts) + Claude Code (exécution CLI). Il écrit
  en français casual, veut des échanges directs et honnêtes, et des prompts/réponses structurés.
- **Rituel obligatoire** : finir chaque feature/fix par un **bloc de passation** (ce qui a été
  fait, fichiers touchés, ce qui reste, points d'attention).

---

## 🥅 Le concept : système 2-en-1

SPORT+ est une couche de **coaching premium** qui complète **Deciplus** (le système existant
d'abonnement + accès salle), sans le remplacer.

- **Deciplus** = abonnements mensuels (Student 25€, Premium+ 45€, Duo+ 55€, Ultra 90€) + accès
  salle 24/24 + paiement Paylib via deciplus.pro. App mobile = Xplor Active. Slug `sportplus`,
  code centre `Sportplus`. Pas d'API/SSO : 2 comptes séparés.
- **SPORT+** = coaching à la séance, choix du coach, messagerie, Night Coach. **Pas besoin
  d'être adhérent Deciplus pour réserver du coaching** (décision Loïc — le coaching de nuit
  attire les non-membres).

---

## 💶 Tarification (logique critique)

- Day Pass : **format** (Solo/Duo 40€ base, Group 25€/pers) **× multiplicateur créneau** :
  Journée (6h-20h) ×1.0, Night (20h-minuit) ×1.5 = 60€, Astreinte (minuit-6h) ×2.0 = 80€.
  Enums : `TimeSlot` (DAY/NIGHT/ASTREINTE), `BookingFormat` (SOLO/DUO/GROUP), `PackType`.
- **`booking.price` = TOUJOURS le prix unitaire** (prix séance × nb personnes).
  **`booking.coveredBy`** = `'subscription'` / `'founding'` / `null`.
  - Affichage **client** : « Incluse — Pack X » / « Incluse — Fondateur » (jamais le prix nu si
    couvert, sinon le client croit devoir repayer).
  - Affichage **coach/admin** : prix unitaire conservé (rémunération + CA réel) + mention
    « VIA PACK X » / « FONDATEUR » pour le contexte.
  - **CA admin** = somme des prix unitaires des séances confirmées. Un pack 180€ consommé à 2/4
    séances = 120€ de CA réalisé (l'écart paiement/réalisé est visible exprès).

---

## 💳 Paiements

- **Stripe Checkout = UNIQUEMENT l'offre Founding** (50 places). **En mode LIVE** depuis le
  15 juin (clés sk_live, webhook signing, branding). Idempotence + verrou pessimiste + webhook
  sécurisé.
- **Deciplus / Xplor = séances + abonnements** (Paylib). Modal Xplor intégrée in-site
  (`XplorExtension` Twig `xplor_pay_url(booking)`, `getDisplayAmountFormatted()` affiche le
  prix TOTAL du pack, ex 210€, pas l'unitaire). **Format Groupe = paiement sur place
  obligatoire** (Xplor masqué, protégé côté serveur).
- 3 modes de paiement choisis à la résa (chips Espèces/CB/Xplor → champ
  `intendedPaymentMethod`). Le coach déclare l'encaissement réel après séance. Check-in QR
  conditionnel selon statut + paiement.

---

## 🧱 Stack & environnement

- Symfony **7.4** / PHP **8.4** / Doctrine ORM **3.x**.
- **MySQL 9.6 local** (Homebrew, port 3306, user `dybdyb`/`17112003` ou `root`, base
  `sportplus`) — **PAS MAMP**. **PostgreSQL 16 en prod** (Railway).
- AssetMapper, Mercure, **vanilla CSS modulaire** (`@import` dans `assets/styles/sportplus.css`).
- Répertoire local : `/Users/dybril/Dev/sportplus-corrige`. Serveur dev sur `127.0.0.1:8001`.
- **Pas de migrations Doctrine** → `php bin/console doctrine:schema:update --force` (start.sh
  étape 2 le fait en prod).
- Filtre Twig `format_datetime` NON installé → utiliser `|date('d/m/Y \\à H\\hi')`.
- Doctrine ORM 3.x + PHP 8.4 → `enable_native_lazy_objects: true` obligatoire.

### DA « Night Performance »
Fond sombre `--bg #0F0F13`, cards `--bg-card #18181F`, or `--gold #C8A951`, vert
`--green #2ECC71`, `--radius-sm 6px`. Polices Inter + Barlow Condensed (titres). Toujours
prévoir `html.performance-lite` + `@media (prefers-reduced-motion)` qui coupent les animations.
**UI soignée obligatoire** (micro-interactions, jamais de Bootstrap générique). Icônes Tabler.

---

## 🗂️ Architecture du code

**Entités** : User, Coach, Booking, Subscription, FoundingOffer, FoundingClaim, Conversation,
Message, AuditLog. **Enums** : AuditAction, BookingFormat, PackType, TimeSlot, UserRole.

**Controllers** : Booking, Client, Coach, Conversation, Home, Legal, Public, Registration,
Security, Sitemap, Stripe + `Admin/` (Audit, Booking, Calendar, Checkin, Coach, Conversation,
Dashboard, Export, Founding, Payment, Subscription, User).

**Services** : AdminExportService, AuditLogger, BookingManager, DeciplusPaymentUrlResolver,
FoundingOfferService, MailerService, NotificationService, PricingCalculator,
StripeCheckoutService.

**Commands** : CreateAdmin, CreateCoach, GrantAdmin, PromoteAdmin, PromoteCoach,
ResetFoundingClaims, SeedFoundingOffer, SeedPricingShowcase, **SendDayBeforeReminders**
(cron J-1), **TestEmails** (`app:test-emails <email>` pour tester le rendu).

---

## ✅ État des features (construites & validées)

- **Espace client refondu « Mission Control »** (`/mon-espace`) : hero conversationnel +
  heroMessage contextuel, ticket séance avec photo coach (glass, coin coupé), countdown
  « humain », founding thanks card, parcours strip, heatmap consistance 12 semaines, card
  rythme du mois, décor SVG sport animé sur les flancs (haltère/kettlebell/target/chrono/
  pulse/orbes), container max 960px, icônes Tabler. CSS : `assets/styles/pages/espace-client.css`.
- **Features nuit** : `Coach.isAvailableTonight` + toggle, `User.lastSeenAt` +
  UserActivitySubscriber, préférences nocturnes, bandeau séance + indicateur en ligne + quick
  replies en messagerie.
- **Check-in QR** : page Mon RDV (`/mon-espace/mon-rdv/{ref}`), AdminCheckinController, champs
  Booking `checkinAt`/`checkinBy`. Téléphone client affiché/cliquable côté coach + admin.
- **No-show** : `Booking.noShow`/`noShowMarkedAt`/`noShowFee`, route `/coach/booking/{id}/no-show`,
  fee **30%** (décision Loïc), bouton coach + badge rouge admin.
- **Confirmation J-1** : `Booking.clientConfirmedAt`, route signée HMAC
  `/reservation/{ref}/confirmer/{sig}` (sans auth), email + bouton « Je confirme », commande
  `app:send-day-before-reminders` (cron `0 10 * * *` à configurer sur Railway).
- **Audit log anti-fraude (16 juin)** : entité AuditLog, service AuditLogger (capture
  acteur/IP/UA), AdminAuditController (`/admin/audit`), enum AuditAction (14 actions).
- **Confirmation/contestation client des paiements** : routes `/reservation/{ref}/paiement/
  confirmer` et `/contester`, bannière Mon RDV, audit PAYMENT_CONFIRMED / PAYMENT_DISPUTED.
- **Messagerie admin** : filtres, mots-clés sensibles, highlight, badge « Signalée ».
- **Admin** : AdminPaymentController (`/admin/paiements` anti-magouille, récap par coach),
  AdminConversationController (lecture toutes conv), fiche coach `/admin/coachs/{id}` (perf
  mois + carrière), modal PEEK universelle, modal confirmation universelle, TVA 20%
  (HT/TVA/TTC), exports CSV.
- **Tarifs** : `/tarifs` rend `tarifs_v2.html.twig` (l'ancien `tarifs.html.twig` est conservé
  mais NON utilisé — ne jamais y revenir). Modal Group « Vous êtes combien ? ».
- **Photos coach** : base64 en DB (`photo_data` + `photo_mime_type`), `getPhotoSrc()`, fallback
  fichier local.
- **Pages légales** : 4 pages avec contenu réel.
- **Page À propos : RETIRÉE** (route, template, CSS, liens navbar/footer, sitemap). Ne pas la
  recréer. `/a-propos` → 404 volontaire.
- **Système Reply-To MailerService (18 juin)** : helper `replyTo()` qui lit
  `$_ENV['APP_EMAIL_REPLY_TO']`, appliqué aux 7 méthodes `send*` via `if ($this->replyTo()) {
  $email->replyTo($this->replyTo()); }`. Fallback admin `sendNewFoundingAlertToAdmin` =
  `ls.sportplus13@gmail.com`. Subject `sendBookingRefusedToClient` accentué proprement.
- **Alignement domaine `sportplus-13.com` (19 juin)** : remplacement des 14 occurrences
  `sportplus-marseille.fr` / `sportplus-13.fr` dans les 4 pages légales, MailerService,
  `.env` et `EMAIL_SETUP.md`. `ls.sportplus13@gmail.com` (gmail Loïc) et URL Railway
  intacts.
- **Logo emails + globale Twig `site_url` (19 juin)** : variable `APP_SITE_URL` ajoutée
  dans `.env`, globale Twig `site_url` créée dans `config/packages/twig.yaml`, template
  `templates/emails/base.html.twig` corrigé (logo + lien footer). Plus de dépendance à
  `app.request` (qui n'existe pas en CLI/cron). Bug latent ligne 51 corrigé en bonus :
  `app_url` globale n'était définie nulle part → tous les liens footer pointaient sur
  `127.0.0.1` en prod. ⚠️ **À ajouter dans Railway** : `APP_SITE_URL=https://sportplus-13.com`
  + redeploy.
- **Formulaire de contact fonctionnel (19 juin)** : `/contact` envoie désormais un vrai
  email vers `ls.sportplus13@gmail.com` (gmail Loïc) via `MailerService::sendContactMessage()`.
  Particularité : le **Reply-To pointe vers l'email du visiteur** (pas vers le gmail de
  Loïc comme les autres méthodes) → Loïc clique « Répondre » dans Gmail, la réponse part
  direct au client. Template `templates/emails/contact_message.html.twig` (étend
  `emails/base.html.twig` + macros). Validation basique côté contrôleur (nom, email, message
  non vides + `filter_var` email).
- **Mot de passe oublié (19 juin)** : routes `/mot-de-passe-oublie` (`app_forgot_password`)
  et `/reinitialiser-mot-de-passe/{id}/{ts}/{token}` (`app_reset_password`) dans
  `SecurityController`. Token = `hash_hmac('sha256', 'reset:' ~ id ~ ':' ~ ts ~ ':' ~
  user.password, APP_SECRET)` tronqué à 32 chars. **Le hash du mot de passe est dans la
  signature → à usage unique automatiquement** (dès qu'il change, l'ancien lien devient
  invalide, zéro table en base). Expiration 1h via timestamp dans l'URL. Anti-énumération :
  même message neutre « si un compte existe… » qu'on ait trouvé l'email ou pas. Lien
  « Mot de passe oublié ? » ajouté sous le champ password de `login.html.twig`. 2 nouveaux
  templates `security/forgot_password.html.twig` et `security/reset_password.html.twig`
  (DA Night Performance, layout `auth-layout`). Template email
  `emails/password_reset.html.twig`. Routes en PUBLIC_ACCESS dans `security.yaml`.

---

## 📧 Emails — chantier EN COURS (important)

Tout le système email est codé et prêt. Il reste la config infra (hors-code) à finaliser.

### Décisions actées
- **Domaine acheté : `sportplus-13.com`** (Cloudflare, au nom de Loïc / SPORT+). C'est bien
  le **.com**, PAS le .fr. ✅ **Aligné partout (19 juin)** : pages légales, MailerService
  fallback, `.env`, EMAIL_SETUP.md — plus aucune occurrence de `sportplus-13.fr` ni
  `sportplus-marseille.fr` dans le code.
- **Système Reply-To** (codé par Claude Code) :
  - Expéditeur affiché = **`contact@sportplus-13.com`** (domaine pro — gmail comme `from` est
    bloqué par DMARC pour l'envoi auto).
  - **Reply-To = `ls.sportplus13@gmail.com`** (vraie boîte de Loïc, reçoit les réponses clients).
  - `contact@sportplus-13.com` = simple façade d'envoi technique via Resend (Option A, coût 0€,
    pas une vraie boîte à consulter).
- **Service d'envoi = Resend** (3000 mails/mois gratuits, bridge `symfony/resend-mailer`
  installé). Domaine ajouté + **Auto configure réussi** (DNS SPF/DKIM créés automatiquement
  dans Cloudflare).
- **7 templates emails** : booking_confirmed_client, booking_day_before, booking_pending_coach,
  booking_refused_client, founding_alert_admin, founding_welcome, registration_welcome.
  MailerService lit les vars via `$_ENV[]` direct, Reply-To appliqué aux 7 méthodes `send*`.
- `EMAIL_SETUP.md` à la racine = guide complet des étapes.

### Variables d'env (à mettre dans Railway, PAS dans .env commité)
```
MAILER_DSN=resend+api://re_LA_VRAIE_CLE@default
APP_EMAIL_FROM=contact@sportplus-13.com
APP_EMAIL_FROM_NAME=SPORT+ Marseille
APP_EMAIL_ADMIN=ls.sportplus13@gmail.com
APP_EMAIL_REPLY_TO=ls.sportplus13@gmail.com
```
En local `.env` reste `MAILER_DSN=null://null` ; `.env.local` utilise Mailtrap (sandbox dev).

### Ce qui reste à faire (hors-code)
1. Créer la **clé API Resend** (`re_...`) → la coller directement dans Railway (jamais dans le
   chat/code).
2. Mettre les 5 variables ci-dessus dans Railway + **Redeploy obligatoire**.
3. Vérifier le domaine **« Verified »** (vert) dans Resend.
4. **Custom domain Railway** : `sportplus-13.com` déjà branché (Auto configure OK).
5. **Cron Railway** `0 10 * * *` sur `app:send-day-before-reminders`.
6. **Tester** : `php bin/console app:test-emails <ton-gmail>` → vérifier réception en boîte.
7. **Valider l'email ICANN** dans le Gmail de Loïc (sinon domaine suspendu sous 14 jours).

---

## 🚀 Déploiement (voir skill `railway-symfony-deploy`)

- Recette : Dockerfile `php:8.4-cli-alpine` + `start.sh` + serveur PHP intégré port 8080 +
  `router.php` (sert les assets) + `doctrine:schema:update --force` (pas de migrations) +
  `server_version: '16'` en prod.
- Live actuel : `sport-production-ba11.up.railway.app` (→ bascule sur `sportplus-13.com`).
- **Réflexes** : Redeploy manuel après changement de variable ; migration prod immédiate après
  push ; CSS manquants = router.php absent ou asset-map:compile raté.

---

## ⏳ Backlog / en attente

- **Côté Loïc/infra** : clé API Resend + variables Railway + cron J-1 ; SIREN/SIRET de LS SPORT
  SAS pour finaliser le légal ; activer « VAD = Oui » sur les prestations Deciplus iframe ;
  uploader les vraies photos coachs (système prêt) ; valider email ICANN.
- **Code à faire** : aligner les pages légales sur `sportplus-13.com` (au lieu de `.fr`).
- **Idées non implémentées** : app mobile (à faire APRÈS les mails — finir un chantier avant
  d'en ouvrir un autre) ; Lighthouse 95+ (Caddy/Nginx, configs prêtes) ; détection d'anomalies
  admin auto (ex : 20 paiements cash/7j = alerte) ; badge fiabilité client ; témoignages,
  parrainage, PWA, cartes cadeaux.

---

## 🔧 Workflow Git
Branche → commit (`feat:`/`fix:`/`refactor:`) → push → merge GitHub → suppression branche
locale. Attention aux fichiers `.idea/` (PhpStorm) qui peuvent interférer — vérifier
`.gitignore`. Avant push : vérifier qu'aucune vraie clé (`re_...`, `sk_live`, secret) n'est
commitée.
