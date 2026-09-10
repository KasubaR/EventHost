<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Contributor-facing failure (amount exceeds the remaining balance, pledge
 * already fully paid, duplicate installment in progress) — the contribution
 * equivalent of TicketPurchaseException.
 */
class ContributionPaymentException extends RuntimeException {}
