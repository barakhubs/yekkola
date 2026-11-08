<?php

declare(strict_types=1);

// Notification texts (PRD-10). `sms` lines: 160 GSM-7 characters at most — é, è, à, ù are fine; avoid ê, â, ô, î, ç.
return [
    'open' => 'Ouvrir',

    'payment_succeeded' => [
        'receipt' => ['title' => 'Paiement reçu', 'body' => 'Votre paiement de :amount (commande :number) est confirmé. Vos cours sont disponibles.', 'sms' => 'Yekkola : paiement de :amount recu (commande :number). Vos cours sont disponibles dans l\'application.'],
        'gift' => ['title' => 'Un cours vous a été offert', 'body' => 'La commande :number vous donne accès à de nouveaux cours.', 'sms' => 'Yekkola : un cours vous a ete offert (commande :number).'],
        'payer' => ['title' => 'Paiement reçu', 'body' => 'Paiement de :amount reçu pour la commande :number.', 'sms' => 'Yekkola : merci, paiement de :amount recu pour la commande :number.'],
    ],
    'payment_failed' => [
        'failed' => ['title' => 'Paiement non abouti', 'body' => 'Le paiement de la commande :number n’a pas abouti. Vous pouvez réessayer.'],
        'expired' => ['title' => 'Commande expirée', 'body' => 'La commande :number n’a pas été payée à temps. Vous pouvez en passer une nouvelle.'],
    ],
    'refund_decided' => [
        'approved' => ['title' => 'Remboursement accepté', 'body' => 'Votre remboursement de :amount (commande :number) est accepté et en cours d’envoi.'],
        'rejected' => ['title' => 'Remboursement refusé', 'body' => 'Votre demande de remboursement pour la commande :number a été refusée.'],
    ],
    'course_updated' => ['title' => 'Cours mis à jour', 'body' => 'Le cours « :course » a une nouvelle version.'],
    'question_answered' => ['title' => 'Réponse du professeur', 'body' => 'Le professeur a répondu à votre question « :title ».'],
    'certificate_issued' => ['title' => 'Certificat obtenu', 'body' => 'Félicitations ! Votre certificat pour « :course » est prêt.'],
    'application_decided' => [
        'approved' => ['title' => 'Candidature acceptée', 'body' => 'Bienvenue parmi les professeurs Yekkola ! Vous pouvez créer votre premier cours.', 'sms' => 'Yekkola : votre candidature de professeur est acceptee. Bienvenue ! Creez votre premier cours sur yekkola.'],
        'rejected' => ['title' => 'Candidature refusée', 'body' => 'Votre candidature de professeur n’a pas été retenue. Les raisons sont dans votre espace.', 'sms' => 'Yekkola : votre candidature de professeur n\'a pas ete retenue. Detail dans votre espace.'],
        'needs_info' => ['title' => 'Informations demandées', 'body' => 'L’équipe a besoin de précisions sur votre candidature de professeur.', 'sms' => 'Yekkola : nous avons besoin de precisions sur votre candidature. Ouvrez votre espace pour repondre.'],
    ],
    'version_decided' => [
        'approved' => ['title' => 'Cours approuvé', 'body' => '« :course » est approuvé et en ligne.'],
        'rejected' => ['title' => 'Cours à revoir', 'body' => '« :course » n’a pas été approuvé. Les remarques de l’équipe sont dans le studio.'],
    ],
    'new_question' => ['title' => 'Nouvelle question', 'body' => 'Un étudiant demande : « :title ».'],
    'new_review' => ['title' => 'Nouvel avis', 'body' => 'Nouvel avis (:rating/5) sur « :course ».'],
    'new_sale' => ['title' => 'Nouvelle vente', 'body' => 'Un étudiant a acheté « :course ».'],
    'payout_sent' => ['title' => 'Versement envoyé', 'body' => ':amount envoyés sur votre numéro se terminant par :last_digits.', 'sms' => 'Yekkola : versement de :amount envoye sur votre numero se terminant par :last_digits.'],
    'payout_failed' => ['title' => 'Versement échoué', 'body' => 'Le versement de :amount sur votre numéro se terminant par :last_digits a échoué. Vérifiez votre moyen de paiement.', 'sms' => 'Yekkola : le versement de :amount a echoue. Verifiez votre numero de paiement dans votre espace professeur.'],
    'kyc_decided' => [
        'verified' => ['title' => 'Identité vérifiée', 'body' => 'Votre identité est vérifiée : vos versements peuvent partir.'],
        'rejected' => ['title' => 'Vérification refusée', 'body' => 'Votre vérification d’identité a été refusée. Envoyez de nouveaux documents.'],
    ],
    'account_status' => [
        'active' => ['title' => 'Compte réactivé', 'body' => 'Votre compte est de nouveau actif.', 'sms' => 'Yekkola : votre compte est de nouveau actif.'],
        'suspended' => ['title' => 'Compte suspendu', 'body' => 'Votre compte est suspendu. Contactez le support pour en savoir plus.', 'sms' => 'Yekkola : votre compte est suspendu. Contactez le support pour en savoir plus.'],
        'banned' => ['title' => 'Compte fermé', 'body' => 'Votre compte a été fermé pour non-respect des règles.', 'sms' => 'Yekkola : votre compte a ete ferme pour non-respect des regles.'],
    ],
    'course_taken_down' => ['title' => 'Cours retiré', 'body' => '« :course » a été retiré par l’équipe Yekkola : :reason'],
    'warning' => ['title' => 'Avertissement de l’équipe Yekkola', 'body' => 'Un de vos contenus enfreint les règles : :note'],
];
