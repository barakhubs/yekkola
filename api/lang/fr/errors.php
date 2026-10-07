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
        'session_expired' => 'Vous avez été déconnecté. Veuillez vous reconnecter.',
        'client_unknown' => 'Client non reconnu : envoyez les informations de l’appareil ou utilisez le site web.',
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
    'otp' => [
        'cooldown' => 'Veuillez patienter avant de demander un nouveau code.',
        'rate_limited' => 'Trop de codes demandés. Réessayez plus tard.',
        'expired' => 'Ce code a expiré. Demandez un nouveau code.',
        'invalid' => 'Code incorrect.',
        'too_many_attempts' => 'Trop de tentatives. Demandez un nouveau code.',
    ],
    'account' => [
        'banned' => 'Ce compte a été désactivé.',
        'suspended' => 'Ce compte est suspendu. Contactez le support.',
    ],
    'device' => [
        'limit_reached' => "Nombre maximal d'appareils atteint. Retirez un appareil pour continuer.",
        'not_found' => 'Appareil introuvable.',
    ],
    'bot_challenge' => [
        'failed' => 'Vérification de sécurité échouée. Réessayez.',
    ],
    'export' => [
        'not_ready' => "Aucun export disponible pour l'instant.",
        'in_progress' => 'Un export est déjà en cours de préparation.',
    ],
    'phone' => [
        'invalid' => 'Numéro de téléphone invalide.',
        'taken' => 'Ce numéro est déjà utilisé par un autre compte.',
        'unchanged' => 'C’est déjà votre numéro actuel.',
    ],
];
