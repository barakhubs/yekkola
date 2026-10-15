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
        'reauthentication_required' => 'Pour votre sécurité, reconnectez-vous puis réessayez.',
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
    'request' => [
        'busy' => 'Une autre opération est en cours. Réessayez dans un instant.',
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
        'locked' => 'Trop de codes incorrects pour ce numéro. Réessayez plus tard.',
        'temporarily_unavailable' => 'L’envoi de codes est temporairement indisponible. Réessayez plus tard.',
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
        'not_found' => 'Aucun export demandé.',
        'rate_limited' => 'Vous pouvez demander un export par jour.',
        'filename' => 'yekkola-mes-donnees.json',
    ],
    'professor' => [
        'suspended' => 'Votre compte professeur est suspendu.',
        'already_professor' => 'Vous êtes déjà professeur sur Yekkola.',
        'slug_already_changed' => 'L’adresse de votre page ne peut être modifiée qu’une seule fois.',
        'required' => 'Cette section est réservée aux professeurs approuvés.',
    ],
    'application' => [
        'not_found' => 'Aucune candidature trouvée.',
        'locked' => 'Cette candidature ne peut plus être modifiée.',
        'incomplete' => 'Complétez tous les champs obligatoires avant d’envoyer votre candidature.',
        'reapply_cooldown' => 'Vous pourrez soumettre une nouvelle candidature plus tard.',
        'invalid_transition' => 'Cette action n’est pas possible pour une candidature dans cet état.',
        'own_application' => 'Vous ne pouvez pas examiner votre propre dossier.',
        'too_many_credentials' => 'Nombre maximal de fichiers atteint.',
    ],
    'terms' => [
        'not_accepted' => 'Vous devez accepter le contrat professeur.',
        'acceptance_required' => 'Le contrat professeur a changé. Acceptez la nouvelle version pour continuer.',
    ],
    'kyc' => [
        'already_pending' => 'Une vérification d’identité est déjà en cours.',
        'already_verified' => 'Votre identité est déjà vérifiée.',
        'back_required' => 'Ajoutez le verso de votre pièce d’identité.',
        'not_found' => 'Aucune vérification d’identité trouvée.',
        'not_pending' => 'Cette vérification a déjà été traitée.',
    ],
    'course' => [
        'draft_not_editable' => 'Ce brouillon est en cours de révision et ne peut pas être modifié.',
        'no_draft' => 'Ce cours n’a pas de brouillon en cours.',
        'draft_exists' => 'Un brouillon existe déjà pour ce cours.',
        'not_published' => 'Ce cours n’a jamais été publié.',
        'invalid_transition' => 'Cette action n’est pas possible pour ce cours actuellement.',
        'not_ready_for_review' => 'Complétez le cours avant de l’envoyer en révision.',
        'free_limit_reached' => 'Vous avez atteint le nombre maximal de cours gratuits.',
        'free_hours_limit_reached' => 'Ce cours dépasserait la limite de :limit heures de contenu gratuit.',
        'cannot_delete_published' => 'Un cours déjà publié ne peut pas être supprimé ; archivez-le.',
        'own_course' => 'Vous ne pouvez pas examiner votre propre cours.',
        'invalid_order' => 'L’ordre envoyé ne correspond pas au contenu du cours.',
    ],
    'lesson' => [
        'preview_limit_reached' => 'Nombre maximal de leçons d’aperçu atteint.',
        'type_mismatch' => 'Ce contenu ne correspond pas au type de leçon.',
    ],
    'phone' => [
        'invalid' => 'Numéro de téléphone invalide.',
        'taken' => 'Ce numéro est déjà utilisé par un autre compte.',
        'unchanged' => 'C’est déjà votre numéro actuel.',
        'country_not_supported' => 'Les numéros de ce pays ne sont pas encore pris en charge.',
    ],
];
