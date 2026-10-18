<?php
/**
 * Copier en `config.local.php` SUR LE SERVEUR OVH (jamais dans le dépôt Git) et compléter.
 * Les clés absentes gardent la valeur par défaut de _lib.php.
 */
return [
    'cabinet_email' => 'cabinet@arnaudcousin-avocat.fr',
    'from_email'    => 'site@arnaudcousin-avocat.fr', // adresse à créer dans l'espace client OVH (Emails) pour un envoi fiable
    'rib'           => "Titulaire : Maître Arnaud Cousin\nIBAN : FRxx xxxx xxxx xxxx xxxx xxxx xxx\nBIC : xxxxxxxx",
    'paiement'      => 'virement', // 'stripe' quand le compte Stripe sera ouvert
    // 'devis' => ['base' => 960, 'enfant' => 240, ...], // ajuster la grille DCM si besoin
];
