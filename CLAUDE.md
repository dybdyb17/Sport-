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
- Convention dates Twig (mise à jour 20 juin) : `twig/intl-extra ^3.26` **est installé**.
  - `|date('d/m/Y \\à H\\hi')` pour les formats compacts du quotidien
  - `|format_datetime('none', 'none', "EEEE d MMMM y", locale='fr')` quand on veut le
    français complet et lisible (ex: « samedi 21 juin 2026 »). Les 2 premiers args
    `'none'` sont obligatoires sinon le pattern personnalisé est ignoré (déjà vécu).
  - Les deux coexistent volontairement dans le code, ne pas tout uniformiser.
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

- **Espace client refondu épuré (22 juin)** (`/mon-espace`) : retiré heatmap 12 semaines,
  card rythme du mois, parcours strip (trop chargé). Ticket prochaine séance **adaptatif
  selon 3 états** : `state-pending` (icône horloge + texte rassurant), `state-payment`
  (CTA central « Régler via Xplor » avec mention « QR prêt, attend juste le paiement » —
  parcours Xplor clarifié), `state-ready` (QR affiché en grand directement). Logique
  centralisée via 2 helpers `Booking.isQrUnlocked()` / `Booking.isAwaitingXplorPayment()`
  (utilisés par espace + mon-rdv → plus de duplication). Ajouts : bannière action
  contextuelle en haut (paiement Xplor en attente OU profil incomplet, cliquable, fond or
  translucide), liste « TES AUTRES SÉANCES À VENIR » compacte sous le ticket (n'apparaît
  que s'il y a >1 séance), état vide soigné (icône calendar-plus + CTA réserver). Décor
  line art doré **animé** sur les flancs (haltère/cible/disques/kettlebell/pulse/chrono/
  triangle/croix/cercles), ≥1100px (couvre MacBook 13" + tablette paysage), variable CSS
  `--deco-opacity: 0.28` (28% bien visible). Animations : `floatY` (translateY -16px, 5-6s),
  `floatYb` (translateY +14px, 5-6s) et `spawnFade` (opacity 0↔1 + scale, 8-9s) appliquées
  sur 10 silhouettes avec délais décalés pour effet organique. Structure SVG `<g>` double
  (extérieur = animation, intérieur = position) pour ne pas écraser les `translate`.
  Exception `prefers-reduced-motion: reduce` + `html.performance-lite` coupent l'animation
  (accessibilité obligatoire) — par défaut ça bouge. Lien discret « Voir mes séances
  passées » en bas. Founding thanks card + encart Deciplus gardés. CSS :
  `assets/styles/pages/espace-client.css` (réécrit).
- **Page historique client (22 juin)** : nouvelle route `/mon-espace/historique`
  (`app_espace_client_history`), template `templates/client/historique.html.twig`. Liste
  cards compactes triées de la plus récente à la plus ancienne, statut final synthétique
  (honorée / no-show / annulée / refusée / non confirmée / terminée) avec pastille colorée
  et bordure gauche de couleur correspondante. CSS dédié
  `assets/styles/pages/historique-client.css`.
- **Features nuit** : `Coach.isAvailableTonight` + toggle, `User.lastSeenAt` +
  UserActivitySubscriber, préférences nocturnes, bandeau séance + indicateur en ligne + quick
  replies en messagerie.
- **Check-in QR** : page Mon RDV (`/mon-espace/mon-rdv/{ref}`), AdminCheckinController, champs
  Booking `checkinAt`/`checkinBy`. Téléphone client affiché/cliquable côté coach + admin.
- **Check-in accessible aux coachs (23 juin)** : `AdminCheckinController` n'est plus
  `#[IsGranted('ROLE_ADMIN')]` au niveau classe. Autorisation gérée **sans
  expression-language** (composant non installé) : simple `#[IsGranted('ROLE_COACH')]`
  sur la méthode `validate` — ça suffit grâce à la `role_hierarchy` de `security.yaml`
  (`ROLE_ADMIN: [ROLE_COACH, ...]`) → admin et coach passent, client/visiteur bloqué.
  Contrôle fin dans le corps (GET ET POST) : `booking.coach.user === currentUser`.
  Admin = passe-partout, coach = limité à SES séances. Coach scannant la séance d'un
  autre → page lisible `templates/admin/checkin/forbidden.html.twig` (icône cadenas,
  nom du coach assigné, bouton retour dashboard) au lieu d'un 403 brut. **Route et nom
  inchangés** (`/admin/checkin/{reference}`, `app_admin_checkin_validate`) pour ne pas
  invalider les QR déjà générés. `checkinBy` enregistre QUI a validé (coach ou admin).
  Template `validate.html.twig` migré sur `base.html.twig` (plus de sidebar admin
  orpheline pour un coach).
- **No-show** : `Booking.noShow`/`noShowMarkedAt`/`noShowFee`, route `/coach/booking/{id}/no-show`,
  fee **30%** (décision Loïc), bouton coach + badge rouge admin.
- **Confirmation J-1** : `Booking.clientConfirmedAt`, route signée HMAC
  `/reservation/{ref}/confirmer/{sig}` (sans auth), email + bouton « Je confirme », commande
  `app:send-day-before-reminders` (cron `0 10 * * *` sur Railway).
- **Rappel J-1 toute heure (21 juin)** : `Booking.reminderSentAt` + envoi immédiat à la
  confirmation si la séance est dans <30h (BookingManager::sendImmediateReminderIfDue).
  Le cron quotidien filtre sur `reminderSentAt IS NULL` et marque le flag après envoi
  réussi → aucun doublon, aucune séance oubliée (résa du soir pour le lendemain matin,
  résa de nuit pour le matin même, etc.). `sendDayBeforeReminder` retourne `bool` :
  si Resend plante, le flag n'est pas set → le prochain cron retentera. Fenêtre cron
  élargie à 36h (au lieu de juste « demain ») pour rattraper un éventuel cron sauté.
- **Wording rappel adaptatif (21 juin)** : helpers `Booking.relativeDayLabel` →
  `"aujourd'hui"` / `"demain"` / `"samedi 22 juin"` (IntlDateFormatter `fr_FR`), et
  `Booking.relativeDayWithTimeLabel` → `"aujourd'hui à 16h00"`. Template
  `emails/booking_day_before.html.twig` (sujet + corps) ne dit plus jamais
  « c'est demain » en dur — il sort le bon label selon la vraie distance entre `now`
  et `startAt`. Évite l'effet amateur quand un rappel part le jour-même
  (envoi immédiat à 5h pour séance à 10h) ou rattrape un cron sauté.
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
- **Audit solidité (23 juin)** :
  - **Point C — enums Booking/Subscription** : NON corrigés. Diagnostic prouvé en prod (booking
    de test avec `format=group, time_slot=night` chargé via `find()` retourne bien `group/night`
    et PAS les défauts `solo/day`). Le proxy lazy hydrate correctement ces propriétés malgré
    leur valeur par défaut, contrairement à `User::$role` qui avait planté. À surveiller si
    Doctrine/PHP change, mais pas d'action nécessaire pour l'instant.
  - **Point A — removeBooking par ID** : `User::removeBooking` et `Coach::removeBooking`
    migrés vers `$booking->getClient()?->getId() === $this->getId()` (idem côté Coach).
    Évite que le dénouage rate sur un proxy lazy.
  - **Point B — messagerie cosmétique** : 5 comparaisons `===` migrées vers ID dans
    `ConversationController::inbox` (interlocuteur sidebar, badge unread) et `::show` (boucle
    marquage as-read, sidebar dans show). `$userId` extrait une fois en début de méthode.
- **Trusted proxies Railway (23 juin)** : `framework.yaml` reçoit `trusted_proxies:
  '%env(TRUSTED_PROXIES)%'` + `trusted_headers: [x-forwarded-for, x-forwarded-host,
  x-forwarded-proto, x-forwarded-port]`. Valeur par défaut dans `.env` :
  `127.0.0.1,REMOTE_ADDR,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16` (REMOTE_ADDR fait
  confiance au dernier hop quel qu'il soit, parfait pour Railway sans IP fixe).
  Cause : Railway termine le TLS en amont, sans trusted proxies Symfony lit
  `http` côté requête → sitemap.xml génère les `<loc>` en `http://` →
  Search Console rejette parce que la propriété est déclarée en `https`. Fix
  aussi toutes les URL absolues `url()` Twig générées en contexte requête.
  ⚠️ **`TRUSTED_PROXIES` DOIT être définie côté Railway** (le `.env` du repo
  n'est PAS chargé fiablement en mode prod sur Railway). Set via
  `railway variables -s Sport- --set TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR,
  10.0.0.0/8,172.16.0.0/12,192.168.0.0/16` — appliqué 23/06.
- **FAQ catégorie « Coaching de nuit & accès 24h/24 » + Schema FAQPage (23 juin)** : 6 nouvelles
  Q/R ciblées SEO niche (« coach sportif nuit Marseille », « salle 24h/24 Marseille ») dans une
  section avec ancre `#nuit`, ajoutée entre « Coachs & séances » et « Membres Fondateurs ». Lien
  ajouté dans les 2 navs (.faq-nav + #faq-fab-panel) avec icône ti-moon-stars. Total : 30 → 36
  questions (compteurs hero + meta description mis à jour). Ajout d'un `<script type="application/
  ld+json">` Schema.org **FAQPage** listant les 36 Q/R en texte brut → rich snippets Google.
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
- **Fix dézoom iOS en impersonnification (20 juin)** : cause identifiée — sur mobile, le
  header de l'utilisateur impersonné contenait Logo + bouton « Sortir admin » + bouton
  « Réserver » + avatar + burger = la somme des largeurs dépassait 100vw → iOS Safari
  dézoomait pour tout afficher et restait bloqué dézoomé.
  Fix :
  - Classe `is-impersonating` ajoutée sur `<body>` quand `isImpersonating` est `true`
    (variable Twig calculée une fois en haut de `base.html.twig`).
  - CSS dans `helpers.css` : en mobile (`max-width: 640px`) ET sous `body.is-impersonating`,
    on cache `.header-actions .btn-primary` (Réserver) et `.user-avatar` + son conteneur
    parent (via `:has(> .user-avatar)`). L'avatar reste accessible via le menu burger.
  - Le `body` reste propre pour les non-impersonnifiants : classe vide normale.
- **Espace coach caché pour les admins non-coachs (20 juin)** : dans `base.html.twig`,
  le check `is_granted('ROLE_COACH')` (qui matche aussi les admins via la role_hierarchy)
  est remplacé par `app.user.coach is not null` pour le dropdown avatar et le menu mobile.
  → Loïc (admin + coach, `sizzlostyle@gmail.com`) garde son accès à `/coach/dashboard`.
  → Un admin sans profil coach (ex: `dybrilboudiaf14@gmail.com`) ne voit plus le lien et
  ne tombe plus sur la bannière flash jaune mal placée. Le contrôleur garde sa garde
  silencieuse (redirect vers `/admin` si URL tapée à la main, sans flash technique).
- **Fixes mobile 2e passe (20 juin)** :
  - **FAB « Sortir admin » caché en mobile** (`max-width: 640px`) : suspect du dézoom iOS
    (position: fixed + animation pulse). En mobile, seul le bouton dans le header reste
    pour sortir d'impersonnification.
  - **Bouton « Export CSV semaine » calendrier admin** : parent en `flex-wrap: wrap` +
    `flex-shrink: 0` sur le bouton, label raccourci à « CSV » en mobile via les helpers.
  - **Helpers globaux `.hide-on-mobile` / `.show-on-mobile-only`** dans `helpers.css`,
    réutilisables partout.
- **Fixes mobile critiques (20 juin)** : 3 corrections ciblées sur le responsive.
  - **Bannière impersonnification cachée en mobile (≤640px)** : prenait 1/3 de l'écran
    avec son bouton « SORTIR ET REDEVENIR ADMIN » qui passait à la ligne. Le bouton dans
    le header (icône seule) + le FAB en bas suffisent largement comme double sécurité.
  - **`.admin-body` + `.admin-main` : `overflow-x: hidden` + `min-width: 0`** dans
    `account.css`. Coupe net tout débordement horizontal sur les pages admin. Réglait le
    problème de `/admin/conversations` qui « débordait de fou » sur iPhone.
  - **Filtres conversations admin responsive** : remplacement du grid inline
    `2fr 1fr 1fr auto` par une classe `.conv-admin-filters` dans `dashboard-extras.css`
    avec breakpoints : desktop = 4 col, tablette = 2 col, mobile = 1 col (stack).
- **Favicon Google + iOS (20 juin)** : SPORT+ est désormais en première page Google sur
  « sport+ marseille » mais le favicon ressortait en globe générique car on n'avait QUE
  un `favicon.svg` — or **Google indexe uniquement les favicons en PNG/JPG/ICO**, pas SVG.
  Solution : 3 fichiers servis à la racine `public/` :
  - `favicon.png` (192×192, 4 KB) — pour Google + browsers modernes
  - `favicon.svg` (360 octets) — pour browsers vectoriels (fallback)
  - `apple-touch-icon.png` (180×180, 3.7 KB) — pour iOS "Ajouter à l'écran d'accueil"
  Générés via `qlmanage -t -s 512` (macOS) → puis `sips -Z` pour les tailles cibles.
  Balises link mises à jour dans `base.html.twig`. Le favicon est le S+ stylé doré/vert
  sur fond noir — signature visuelle SPORT+. Pour forcer Google à recrawler : Search
  Console → Outil d'inspection URL → "Demander une indexation".
- **Bouton « Sortir admin » dans le header (20 juin)** : en plus de la bannière sticky en
  haut ET du FAB en bas à droite, un **3e bouton** « SORTIR ADMIN » est désormais affiché
  dans le `header-actions` de `base.html.twig` — donc visible sur **toutes** les pages
  publiques/client/coach. Style pill orange-doré pulsant. Sur mobile : icône seule.
  Triple sécurité pour ne jamais rester coincé en impersonnification.
  ⚠️ **Compat Symfony 6+** : check étendu sur `is_granted('ROLE_PREVIOUS_ADMIN') or
  is_granted('IS_IMPERSONATOR')` — le second nom est le plus récent, l'ancien reste
  supporté. Variable Twig `isImpersonating` calculée une fois au début du body et réutilisée.
- **FAB « Sortir admin » impersonnification (20 juin)** : en plus de la bannière sticky
  en haut, un bouton flottant orange est désormais affiché en bas à droite quand un admin
  impersonne un user. `position: fixed`, `z-index: 10000`, animation pulse douce pour
  attirer l'œil. Sur mobile : icône seule. **Strictement invisible** pour les vrais
  clients/coachs/admins non impersonnifiants (check `is_granted('ROLE_PREVIOUS_ADMIN')`).
  Garantit qu'on peut TOUJOURS sortir d'impersonnification, même si la bannière sticky est
  cachée par une modal ou un parent qui crée un stacking context. Ajouté dans
  `base.html.twig` ET `admin/base.html.twig`.
- **Fixes UX impersonnification + tunnel réservation (20 juin)** :
  - Modal de confirmation universelle (`base.html.twig`) étendue pour intercepter aussi
    les clics sur `<a data-confirm>` (avant : uniquement les `<form data-confirm>`).
    Variable interne `pendingForm` → `pendingEl`. Plus de `confirm()` natif moche du
    navigateur pour le bouton « Entrer » impersonnification.
  - Bouton « Entrer » dans `/admin/users` route désormais vers l'**espace adapté au rôle
    de la cible** : ROLE_ADMIN → `/admin`, ROLE_COACH → `/coach/dashboard`,
    ROLE_CLIENT → `/mon-espace`. Avant : tout le monde tombait sur `/` (page d'accueil
    publique).
  - Tunnel de réservation (`templates/booking/new.html.twig`) : badge doré « Durée 1h »
    fixe à droite du label « Date & heure de début ». Récap latéral : nouvelle ligne
    **« Horaires »** qui apparaît dès que le client choisit une date/heure, avec
    `22h00 → 23h00 · 1h` en direct. Hint sous le champ affiche aussi la plage calculée.
    Logique dans `booking-form.js` : calcul `end = start + 60min`, formatage en `HHhMM`,
    injection dans 3 éléments DOM à chaque `onChange` Flatpickr.
- **Impersonnification admin (20 juin)** : un `ROLE_ADMIN` peut « entrer dans l'espace »
  de n'importe quel user (client, coach, autre admin) depuis `/admin/users` via le bouton
  **Entrer** sur chaque ligne. Mécanique : `switch_user` natif Symfony activé dans
  `security.yaml` (param `_switch_user=email@du.user`, sortie via `_switch_user=_exit`).
  Symfony garde un token `ROLE_PREVIOUS_ADMIN` → retour en 1 clic, pas besoin de se
  reconnecter. Bannière orange persistante affichée tant qu'on impersonne (dans
  `base.html.twig` ET `admin/base.html.twig` pour couvrir toutes les pages). Toute entrée
  ET sortie est tracée dans le journal d'audit via `SwitchUserAuditListener`
  (`AuditAction::ADMIN_IMPERSONATE` / `ADMIN_LEAVE_IMPERSONATE`) — qui a switché en qui,
  IP, UA, date. ROLE_ADMIN hérite désormais aussi de ROLE_ALLOWED_TO_SWITCH.
- **Durée de séance bien visible partout (20 juin)** : 2 helpers ajoutés sur `Booking` :
  `getTimeRangeFormatted()` → `"22h00 → 23h00"` et `getDurationFormatted()` → `"1h"`
  (ou `"1h30"` plus tard si on a des séances longues). Remplacé l'affichage simple de
  l'heure de début par la plage horaire + badge durée sur : Mon RDV, page suivi demande,
  mail confirmation client, mail J-1 (corps + infoCard), page « Présence confirmée »,
  ticket prochaine séance espace client, dashboard coach (3 occurrences), modal PEEK admin.
  Le client comprend en 1 seconde que sa séance va de 9h à 10h, pas juste « à 9h ».
- **Récap J-1 admin = dead man's switch (20 juin)** : à la fin de la commande
  `app:send-day-before-reminders`, un email récap part **toujours** à `APP_EMAIL_ADMIN`
  (`ls.sportplus13@gmail.com`), même si 0 mail a été envoyé. Contenu : nombre envoyés,
  ignorés, total demain + liste des séances ciblées. Template
  `emails/day_before_summary_admin.html.twig`. **But** : si Loïc ne reçoit pas son ping
  quotidien un matin, il sait que le cron Railway a planté en silence — pas besoin
  d'attendre qu'un client se plaigne. Méthode `MailerService::sendDayBeforeReminderSummary()`.
  Le dry-run NE déclenche PAS l'envoi du récap (pour tests).
- **Fix bug bloquant — confirmation de présence J-1 inaccessible (20 juin)** :
  `BookingController` avait un `#[IsGranted('ROLE_CLIENT')]` au niveau de la **classe**
  qui s'appliquait à toutes les méthodes, **y compris `confirmAttendance`** qui doit être
  publique (lien signé HMAC depuis le mail J-1, sans connexion). Conséquence : tout clic
  sur le bouton « Je confirme ma présence » depuis la boîte mail finissait en redirection
  login → `clientConfirmedAt` jamais rempli → côté coach, le badge restait sur « En
  attente » même pour les clients ayant cliqué. **Fix** : `#[IsGranted]` retiré de la
  classe, ajouté méthode par méthode (7 méthodes : `new`, `pricingPreview`, `payWithXplor`,
  `status`, `statusJson`, `confirmPayment`, `disputePayment`). `confirmAttendance` **sans
  IsGranted** — protégée uniquement par HMAC (`hash_equals`). La règle
  `^/reservation/[^/]+/confirmer/` en PUBLIC_ACCESS dans `security.yaml` fonctionne enfin.
  Bonus : page `confirmed_attendance.html.twig` refondue (carte récap date/heure/coach/
  format, halo radial vert sur l'icône check, 2 boutons adaptés selon connecté ou pas vu
  que le lien arrive d'un mail).
- **Badge confirmation de présence côté coach (20 juin)** : dans
  `templates/coach/dashboard.html.twig`, section « Mes séances à venir », chaque ligne
  affiche désormais un badge basé sur `Booking.clientConfirmedAt` :
  - **Vert « Présence confirmée »** (icône `ti-user-check`) si le client a cliqué « Je
    confirme ma présence » dans le mail J-1
  - **Doré « En attente de confirmation »** (icône `ti-clock-hour-4`) sinon
  - Hint additionnel discret « À relancer rapidement » (icône `ti-phone-call`) si la
    séance est dans les 24h ET pas confirmée ET le client a un téléphone (le tel est déjà
    cliquable au-dessus).
  CSS dans `assets/styles/pages/dashboard-extras.css` (classes `.confirm-badge`,
  `--confirmed`, `--pending`, `.confirm-badge-hint`). Aucune émoticône, icônes Tabler
  uniquement. **Préparation future** : un bouton coach « Confirmer la présence par
  téléphone » remplira le même champ → badge basculera automatiquement.
- **Bouton « Voir le site » dans la topbar admin (20 juin)** : raccourci toujours visible
  en haut à droite du layout admin (`templates/admin/base.html.twig`), pour éviter de rester
  coincé dans le dashboard sur mobile. Style pill dorée. Sur mobile (≤ 640px), le label est
  caché mais l'icône `ti-arrow-back-up` reste visible — accessible en 1 tap depuis n'importe
  où dans l'admin. Le lien existant en footer de sidebar reste, c'est un double filet de
  sécurité.
- **Règle « 8 caractères minimum » visible sur les formulaires de mot de passe (19 juin)** :
  help text affiché sous le champ via `'help' => '8 caractères minimum.'` sur
  `AdminCoachType` et `RegistrationFormType`. Bloc d'erreurs global ajouté en haut de
  `admin/coachs/new.html.twig` et `admin/coachs/edit.html.twig` (alert rouge listant chaque
  erreur du formulaire, impossible à rater). Texte help ajouté aussi sur
  `security/reset_password.html.twig`. Messages harmonisés sur « Votre mot de passe doit
  faire au moins 8 caractères ». ⚠️ **`templates/security/login.html.twig` volontairement
  non touché** : sur la page de connexion, aucune règle de mdp affichée (sécurité — pas
  d'indice à un attaquant). Le seul message reste « Identifiants invalides ».
- **Cron rappels J-1 EN PROD (20 juin)** : service cron Railway dédié configuré et
  fonctionnel — la commande `app:send-day-before-reminders` tourne quotidiennement
  (cron config par l'IA Railway selon `EMAIL_SETUP.md`). Pré-requis `DEFAULT_URI=
  https://sportplus-13.com` dans Railway = OK (test J-1 validé avec lien fonctionnel).
  Pour vérifier que ça continue : Railway → service cron → onglet Deployments (vert/rouge
  par exécution).
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
