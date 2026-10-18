<?php
/**
 * À copier SUR LE SERVEUR OVH, hors racine web : `/config/site-cabinet.php` (à côté de `www/` et `preprod/`),
 * via le gestionnaire de fichiers ou FTP. Jamais dans le dépôt Git. Les deux environnements (préprod, prod) le lisent.
 * Les clés absentes gardent la valeur par défaut de _lib.php.
 */
return [
    'cabinet_email' => 'cabinet@arnaudcousin-avocat.fr',
    'from_email'    => 'site@arnaudcousin-avocat.fr', // adresse à créer dans l'espace client OVH (Emails) pour un envoi fiable
    'rib'           => "Titulaire : Maître Arnaud Cousin\nIBAN : FRxx xxxx xxxx xxxx xxxx xxxx xxx\nBIC : xxxxxxxx",
    'paiement'      => 'virement', // 'stripe' quand le compte Stripe sera ouvert
    // Grille devis DCM en € HT (TVA 20 % appliquée par le site). Valeurs par défaut de _lib.php, à surcharger ici si besoin :
    // 'devis' => ['base' => 800, 'parentale' => 400, 'compensatoire' => 300, 'partage' => 700, 'negociation' => 500],
    // 'tva'   => 0.20,
];
