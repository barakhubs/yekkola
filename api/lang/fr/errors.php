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
        'not_found' => 'Leçon introuvable.',
        'preview_limit_reached' => 'Nombre maximal de leçons d’aperçu atteint.',
        'type_mismatch' => 'Ce contenu ne correspond pas au type de leçon.',
    ],
    'quiz' => [
        'not_a_quiz' => 'Cette leçon n’est pas un quiz.',
        'max_attempts_reached' => 'Vous avez utilisé vos :max_attempts tentatives pour ce quiz.',
        'already_submitted' => 'Cette tentative a déjà été envoyée.',
    ],
    'order' => [
        'course_unavailable' => 'Un des cours n’est pas disponible à l’achat.',
        'free_course' => 'Ce cours est gratuit : inscrivez-vous directement.',
        'mixed_currency' => 'Les cours d’une même commande doivent être dans la même devise.',
        'nothing_to_buy' => 'Vous êtes déjà inscrit à ces cours.',
        'currency_unavailable' => 'Cette devise n’est pas acceptée pour le moment.',
        'open_order_exists' => 'Une commande en attente de paiement existe déjà pour ce cours.',
        'separate_payer_disabled' => 'Le paiement pour un autre étudiant n’est pas disponible pour le moment.',
        'beneficiary_unavailable' => 'Impossible d’acheter un cours pour ce numéro.',
        'expired' => 'Cette commande a expiré. Créez-en une nouvelle.',
        'not_payable' => 'Cette commande ne peut plus être payée.',
        'not_found' => 'Commande introuvable.',
    ],
    'coupon' => [
        'invalid' => 'Ce code promo n’est pas valide.',
        'not_applicable' => 'Ce code promo ne s’applique pas à cet achat.',
        'exhausted' => 'Ce code promo a déjà été utilisé le nombre maximal de fois.',
        'already_used' => 'Vous avez déjà utilisé ce code promo.',
    ],
    'refund' => [
        'not_refundable' => 'Ce cours ne peut pas être remboursé.',
        'window_closed' => 'Le délai de remboursement de :days jours est dépassé.',
        'progress_too_high' => 'Vous avez suivi plus de :max_progress_pct % du cours : il ne peut plus être remboursé.',
        'already_requested' => 'Un remboursement est déjà en cours pour ce cours.',
        'invalid_transition' => 'Cette action n’est plus possible pour ce remboursement.',
        'own_order' => 'Vous ne pouvez pas décider d’un remboursement sur votre propre commande.',
        'payout_in_flight' => 'Le dernier versement est encore en cours chez l’opérateur.',
        'nothing_unapplied' => 'Ce paiement n’a aucun montant à rembourser.',
    ],
    'payment' => [
        'payer_required' => 'Indiquez le numéro qui va payer.',
        'in_progress' => 'Un paiement est déjà en attente de validation sur votre téléphone.',
        'rail_unavailable' => 'Cet opérateur n’est pas disponible pour le moment. Essayez-en un autre.',
        'amount_out_of_range' => 'Ce montant n’est pas accepté par cet opérateur.',
    ],
    'enrollment' => [
        'required' => 'Inscrivez-vous à ce cours pour y accéder.',
        'not_free' => 'Ce cours est payant.',
        'revoked' => 'Votre accès à ce cours a été retiré. Contactez le support.',
    ],
    'phone' => [
        'invalid' => 'Numéro de téléphone invalide.',
        'taken' => 'Ce numéro est déjà utilisé par un autre compte.',
        'unchanged' => 'C’est déjà votre numéro actuel.',
        'country_not_supported' => 'Les numéros de ce pays ne sont pas encore pris en charge.',
    ],
];
