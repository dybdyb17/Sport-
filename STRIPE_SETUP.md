# Stripe Checkout — configuration SPORT+

## Sécurité immédiate

Une clé secrète Stripe ne doit jamais être envoyée dans un message, copiée dans un ticket ou ajoutée à Git.
Si une clé `sk_test_...` ou `sk_live_...` a été partagée, elle doit être **révoquée puis remplacée immédiatement** depuis le Dashboard Stripe.

Le dépôt contient uniquement des noms de variables vides. Les vraies valeurs doivent être enregistrées :

- en local dans `.env.local` (fichier ignoré par Git) ;
- sur Railway dans **Variables** ;
- jamais dans `.env`, un template Twig ou du JavaScript.

## Variables nécessaires

```dotenv
APP_URL=https://votre-domaine.fr
STRIPE_PUBLIC_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

`STRIPE_PUBLIC_KEY` est prévue pour une éventuelle intégration Stripe Elements. Le Checkout hébergé actuellement mis en place crée la session côté serveur avec `STRIPE_SECRET_KEY`.

## Configuration du webhook Stripe

Dans Stripe en mode test :

1. Ouvrir **Developers > Webhooks**.
2. Ajouter un endpoint HTTPS : `https://votre-domaine.fr/stripe/webhook`.
3. Écouter au minimum :
   - `checkout.session.completed` ;
   - `checkout.session.async_payment_succeeded`.
4. Copier le secret de signature `whsec_...` dans `STRIPE_WEBHOOK_SECRET` sur Railway.

Le webhook est la source fiable de confirmation. La page de retour après paiement essaie aussi de confirmer immédiatement la session, mais l’activation de l’offre ne dépend pas uniquement du retour du navigateur.

## Test local avec Stripe CLI

```bash
stripe login
stripe listen --forward-to http://127.0.0.1:8001/stripe/webhook
```

La CLI affiche un secret temporaire `whsec_...` à placer dans `.env.local` pendant le test.

Démarrer ensuite Symfony et utiliser une carte de test Stripe :

- numéro : `4242 4242 4242 4242` ;
- expiration : n’importe quelle date future ;
- CVC : trois chiffres ;
- code postal : n’importe quelle valeur valide.

## Fonctionnement du parcours

1. Le membre connecté clique sur **Payer avec Stripe**.
2. SPORT+ calcule le prix depuis l’offre enregistrée en base ; le navigateur ne choisit jamais le montant.
3. Le serveur crée une Checkout Session Stripe et redirige vers la page sécurisée hébergée par Stripe.
4. Stripe renvoie le membre vers SPORT+ après le paiement.
5. Le webhook signé confirme le paiement et crée une seule adhésion Membre Fondateur.
6. L’identifiant de session, l’identifiant du PaymentIntent et la date de paiement sont conservés pour le suivi administratif.

Le traitement est idempotent : si Stripe renvoie plusieurs fois le même événement, aucune seconde adhésion n’est créée.
