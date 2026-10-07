<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

enum TransactionKind: string
{
    case Collection = 'collection';
    case Disbursement = 'disbursement';
}
