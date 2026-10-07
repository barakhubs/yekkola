<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Exceptions;

use RuntimeException;

/**
 * The gateway could not be reached or refused the request before anything was sent to the payer/recipient.
 * Safe to retry later with the same reference.
 */
final class GatewayUnavailable extends RuntimeException {}
