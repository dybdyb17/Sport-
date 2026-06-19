# Configuration emails SPORT+ (Resend)

## Étapes (à faire UNE fois, par Loïc + Dybril)

### 1. Acheter le domaine (~10€/an)
- OVH ou Cloudflare → acheter `sportplus-13.com`

### 2. Compte Resend (gratuit, 3000 mails/mois)
- resend.com → s'inscrire avec ls.sportplus13@gmail.com

### 3. Vérifier le domaine dans Resend
- Resend → Domains → Add Domain → sportplus-13.com
- Copier les enregistrements DNS fournis (SPF, DKIM, return-path) dans la zone DNS OVH/Cloudflare
- Attendre la vérification (quelques minutes à 48h)

### 4. Créer une clé API
- Resend → API Keys → Create → copier la clé `re_...`
- NE JAMAIS la mettre dans le code / git

### 5. Configurer Railway (dashboard → service App → Variables)
```
MAILER_DSN=resend+api://re_VOTRE_CLE@default
APP_EMAIL_FROM=contact@sportplus-13.com
APP_EMAIL_FROM_NAME=SPORT+ Marseille
APP_EMAIL_ADMIN=ls.sportplus13@gmail.com
APP_EMAIL_REPLY_TO=ls.sportplus13@gmail.com
APP_SITE_URL=https://sportplus-13.com
```
> `APP_SITE_URL` = URL publique du site, utilisée pour les images/liens absolus dans les
> emails (sans elle, le logo et les liens ne s'affichent pas correctement quand les mails
> sont envoyés via CLI/cron).

Redeploy Railway après ajout des variables (obligatoire pour prise en compte)

### 6. Tester
- En local avec Mailtrap : `php bin/console app:test-emails ton@email.fr`
- En prod après config : envoyer un vrai mail test vers une boîte Gmail perso

### 7. Cron rappels J-1 sur Railway

La commande `app:send-day-before-reminders` doit tourner **automatiquement chaque jour
à 10h** (heure de Paris). Elle sélectionne les séances confirmées du lendemain dont le
client n'a pas encore validé sa présence, et envoie l'email de rappel.

> ⚠️ Railway utilise **UTC** pour les crons. Pour exécuter à **10h heure de Paris** :
> - Heure d'été (mars→octobre) : `0 8 * * *` (8h UTC = 10h Paris)
> - Heure d'hiver (octobre→mars) : `0 9 * * *` (9h UTC = 10h Paris)
> Mettre `0 8 * * *` par défaut (toléré en hiver, on tombe à 9h Paris, c'est OK).

⚠️ **Pré-requis prod** : ajouter aussi `DEFAULT_URI=https://sportplus-13.com` dans les
variables Railway si pas déjà fait. Sans cette variable, les liens des emails envoyés
en CLI/cron pointeront sur `http://localhost` (valeur par défaut de `.env`) → liens
cassés pour les clients.

#### Option A — Cron Schedule sur le service App existant *(simple)*
- Railway → service **App** → Settings → Deploy → **Cron Schedule**
- Schedule : `0 8 * * *`
- Start command : `php bin/console app:send-day-before-reminders --env=prod --no-debug`

⚠️ Limite : ça **relance le conteneur entier**. À n'utiliser que si Railway le supporte
sur ton plan actuel (vérifier la facturation : sur le plan gratuit/Hobby ça peut couper
le service web pendant l'exécution).

#### Option B — Service cron dédié *(recommandé, propre)*
Créer **un nouveau service** dans le **même projet Railway**, basé sur le même
repo/Dockerfile :
- Railway → projet SPORT+ → **+ New** → **Empty Service** (ou "From GitHub repo")
- Settings → Source → pointer le même repo `dybdyb17/Sport-`
- Settings → Deploy → **Cron Schedule** : `0 8 * * *`
- Settings → Deploy → **Custom Start Command** :
  `php bin/console app:send-day-before-reminders --env=prod --no-debug`
- Settings → Variables → **copier** les mêmes que le service App :
  `DATABASE_URL`, `MAILER_DSN`, `APP_EMAIL_FROM`, `APP_EMAIL_FROM_NAME`,
  `APP_EMAIL_ADMIN`, `APP_EMAIL_REPLY_TO`, `APP_SITE_URL`, `APP_SECRET`,
  `DEFAULT_URI`, `APP_ENV=prod`

Ce service ne sert pas de web : il démarre à l'heure prévue, exécute la commande,
puis s'arrête jusqu'au lendemain. Aucun impact sur le service web principal qui
continue de tourner normalement 24/24.

**→ Pour SPORT+ : recommandé d'utiliser l'option B.** Le service web reste stable,
et si la commande crashe un jour ça n'affecte pas le site public.

#### Vérifier que ça marche
- Tester d'abord en dry-run via Railway Run Command sur le service principal :
  `php bin/console app:send-day-before-reminders --dry-run --env=prod --no-debug`
- Forcer une exécution manuelle du service cron depuis le dashboard (bouton "Trigger
  Deploy") pour vérifier qu'il tourne sans erreur même hors horaire

## Important

- L'expéditeur DOIT être `@sportplus-13.com` (gmail bloqué par DMARC pour envoi auto)
- Les réponses clients arrivent sur `ls.sportplus13@gmail.com` (Reply-To)
- Le client voit : `SPORT+ Marseille <contact@sportplus-13.com>`
