<?php

declare(strict_types=1);

return [
    // Why a mobile-money payment failed (PRD-05 FR-09), shown on the waiting screen.
    'failure' => [
        'insufficient_funds' => 'Insufficient balance on this mobile-money account.',
        'rejected_by_payer' => 'The payment was declined on the phone.',
        'timeout' => 'The payment was not approved in time.',
        'gateway_unavailable' => 'The operator is not responding right now. Try again or choose another operator.',
        'invalid_recipient' => 'This number cannot receive payments.',
        'failed' => 'The payment failed. Try again or choose another operator.',
    ],
];
