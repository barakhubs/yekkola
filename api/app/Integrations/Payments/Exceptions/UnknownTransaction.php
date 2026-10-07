<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Exceptions;

use RuntimeException;

/**
 * The gateway has no record of this reference (yet). Not a failure: gateways are often briefly
 * inconsistent right after a start. Keep the transaction pending and look again later.
 */
final class UnknownTransaction extends RuntimeException {}
