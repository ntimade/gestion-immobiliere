# Gestion immobilière

Gestion locative multi-propriétaires : biens, appartements et chambres numérotés, locataires, cautions, factures de loyer, avances sur plusieurs mois, impayés et rappels par WhatsApp et SMS.

## Fonctionnement

- Un **bien** est un seul logement (appartement, chambre, studio…) ou un ensemble de logements saisis directement dans le formulaire du bien. Chaque logement a son tarif, son statut (occupé ou libre) et sa fiche : surface, étage, pièces, meublé, équipements.
- Chaque bien a sa localisation (ville, quartier, repère, position sur la carte) et ses revenus : revenu mensuel actuel, revenu estimé si tout est loué, manque à gagner, historique sur 6 mois.
- L'**entrée** d'un locataire crée un bail : date d'entrée, jour d'échéance propre au locataire, loyer et caution.
- Les **factures** sont créées automatiquement chaque mois, à la première connexion du jour.
- Un **paiement** règle les mois les plus anciens d'abord. S'il dépasse ce qui est dû, il couvre les mois suivants : c'est une avance.
- Les **rappels** apparaissent dans la page Rappels et messages 3 jours avant l'échéance (réglable). Un bouton ouvre WhatsApp ou les SMS avec le message écrit : il reste à appuyer sur Envoyer. Un mois payé d'avance n'y apparaît pas.
- La **sortie** libère le logement et enregistre ce qui est rendu ou retenu sur la caution.

## Installation

Prérequis : PHP 8.2+, Composer, MySQL/MariaDB.

1. `composer install`
2. Copier `.env.example` vers `.env`, renseigner `DB_*` et `APP_URL`, puis `php artisan key:generate`
3. Créer la base `gestion_immobiliere`, puis `php artisan migrate`
4. `php artisan serve`

Le poste de support (`/support`) a ses propres comptes : `php artisan support:admin adresse@exemple.com`.

## À configurer avant la mise en ligne

Mettre `APP_ENV=production` et `APP_DEBUG=false` : avec `APP_DEBUG=true`, une panne affiche le détail technique de l'erreur au lieu de la page prévue.

## Tests

`php vendor/bin/phpunit`
