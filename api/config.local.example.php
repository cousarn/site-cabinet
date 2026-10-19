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
    'paiement'      => 'paypal',   // 'paypal' : PayPal ou carte (page PayPal) + RIB en secours · 'virement' : RIB seul · 'stripe' : phase 2
    'paypal_id'     => 'YKJVL9Z4Z43ZQ', // identifiant marchand PayPal (public)
    // Notification IPN (api/paypal-ipn.php) — clés facultatives :
    // 'paypal_sandbox' => false,              // vérifier les IPN auprès du bac à sable (ipnpb.sandbox.paypal.com) ; défaut : vrai sur preprod.*, faux ailleurs
    // 'paypal_email'   => 'compte@exemple.fr', // adresse du compte PayPal du cabinet, acceptée comme destinataire (receiver_email/business) à défaut de receiver_id
    // Grille devis DCM en € HT (TVA 20 % appliquée par le site). Valeurs par défaut de _lib.php, à surcharger ici si besoin :
    // 'devis' => ['base' => 800, 'parentale' => 400, 'compensatoire' => 300, 'partage' => 700, 'negociation' => 500],
    // 'tva'   => 0.20,

    // Dépôt de pièces par lien personnel (/depot/<jeton>, console /admin/depots) :
    'depot' => [
        'cle_admin' => 'À REMPLACER — phrase secrète longue (≥ 12 caractères) qui ouvre la console /admin/depots',
        // 'validite_jours' => 30, 'fichier_max_mo' => 50, 'total_max_mo' => 500, 'fichiers_max' => 60, 'conservation_jours' => 90,
    ],
    // Relais des pièces vers SharePoint (application Entra « Site du cabinet — dépôt de pièces », permission d'application
    // Sites.Selected accordée en écriture au seul site « Dépôts clients »). Tant que ces clés sont vides, les pièces restent
    // sur le serveur et se téléchargent en zip depuis la console.
    'graph' => [
        'tenant'        => 'arnaudcousin-avocat.fr',     // ou l'identifiant du locataire (GUID)
        'client_id'     => '',                            // Identifiant d'application (client) de l'inscription Entra
        'client_secret' => '',                            // Valeur du secret client (copiée une seule fois depuis Entra) — JAMAIS dans Git
        'site'          => 'cabinetcousin.sharepoint.com:/sites/DepotsClients', // site « Dépôts clients » (bibliothèque Documents, résolue automatiquement)
        // 'drive_id'   => '',                            // facultatif : identifiant explicite de la bibliothèque (b!…), prime sur `site`
        'racine'        => 'Dépôts du site',              // dossier racine dans la bibliothèque ; un sous-dossier par affaire
    ],
    // Assistant de pré-entretien (/services/pre-entretien) : analyse préparatoire pour l'avocat, inactive tant que cle_api est vide.
    // 'assistant' => ['cle_api' => '', 'modele' => 'claude-haiku-5-5', 'max_tokens' => 1500, 'timeout' => 40],
];
