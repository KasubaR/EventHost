<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Buyer-facing purchase failure (sold out, hold expired, invalid quantity,
 * duplicate checkout in progress) — distinct from TicketingActivationException,
 * which covers the host/admin-facing activation workflow.
 */
class TicketPurchaseException extends RuntimeException {}
