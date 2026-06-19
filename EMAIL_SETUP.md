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
```
Redeploy Railway après ajout des variables (obligatoire pour prise en compte)

### 6. Tester
- En local avec Mailtrap : `php bin/console app:test-emails ton@email.fr`
- En prod après config : envoyer un vrai mail test vers une boîte Gmail perso

### 7. Cron rappels J-1 (Railway → Cron Jobs)
```
0 10 * * *  php bin/console app:send-day-before-reminders
```

## Important

- L'expéditeur DOIT être `@sportplus-13.com` (gmail bloqué par DMARC pour envoi auto)
- Les réponses clients arrivent sur `ls.sportplus13@gmail.com` (Reply-To)
- Le client voit : `SPORT+ Marseille <contact@sportplus-13.com>`
