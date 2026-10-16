<?php

declare(strict_types=1);

return [
    // Why a mobile-money payment failed (PRD-05 FR-09), shown on the waiting screen.
    'failure' => [
        'insufficient_funds' => 'Solde insuffisant sur ce compte mobile money.',
        'rejected_by_payer' => 'Le paiement a été refusé sur le téléphone.',
        'timeout' => 'Le paiement n’a pas été validé à temps.',
        'gateway_unavailable' => 'L’opérateur ne répond pas pour le moment. Réessayez ou choisissez un autre opérateur.',
        'invalid_recipient' => 'Ce numéro ne peut pas recevoir de paiement.',
        'failed' => 'Le paiement a échoué. Réessayez ou choisissez un autre opérateur.',
    ],
];
