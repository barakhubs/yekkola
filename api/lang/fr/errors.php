<?php

declare(strict_types=1);

// Titles for API error codes (docs/architecture.md §4.1). Nested keys mirror the dotted code.
return [
    'generic' => 'Une erreur est survenue.',
    'validation' => [
        'failed' => 'Certaines informations sont invalides.',
    ],
    'auth' => [
        'unauthenticated' => 'Veuillez vous connecter pour continuer.',
        'forbidden' => "Vous n'avez pas l'autorisation d'effectuer cette action.",
        'csrf_mismatch' => 'Votre session a expiré. Veuillez réessayer.',
    ],
    'resource' => [
        'not_found' => 'Ressource introuvable.',
    ],
    'http' => [
        'bad_request' => 'Requête invalide.',
        'method_not_allowed' => 'Méthode non autorisée.',
        'payload_too_large' => 'Le contenu envoyé est trop volumineux.',
        'error' => 'La requête n’a pas pu être traitée.',
    ],
    'rate_limited' => 'Trop de tentatives. Veuillez réessayer plus tard.',
    'server' => [
        'error' => 'Erreur du serveur. Veuillez réessayer.',
    ],
    'idempotency' => [
        'key_missing' => 'L’en-tête Idempotency-Key est requis pour cette requête.',
        'key_reused' => 'Cette clé d’idempotence a déjà été utilisée pour une autre requête.',
        'in_progress' => 'Une requête identique est déjà en cours de traitement.',
    ],
    'webhook' => [
        'invalid_signature' => 'Signature du webhook invalide.',
        'invalid_payload' => 'Contenu du webhook invalide.',
    ],
    'phone' => [
        'invalid' => 'Numéro de téléphone invalide.',
    ],
];
