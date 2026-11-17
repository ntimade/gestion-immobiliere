<?php

return [
    /*
     * Envoi des messages WhatsApp et SMS. Désactivé, tous les boutons d'envoi restent affichés mais grisés,
     * et l'ouverture de WhatsApp ou des SMS est refusée ; le reste (listes, factures, lettres) ne change pas.
     * Pour réactiver : MESSAGES_ENABLED=true dans le .env, puis « php artisan config:cache ».
     */
    'enabled' => (bool) env('MESSAGES_ENABLED', false),
];
