<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Exceptions;

use RuntimeException;

/**
 * The request may have reached the gateway (e.g. timeout after sending). The transaction might exist:
 * keep it pending and reconcile with a status lookup by our reference. Never start a new transaction.
 */
final class GatewayOutcomeUnknown extends RuntimeException {}
