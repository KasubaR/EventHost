<?php

namespace App\Exceptions;

use App\Support\InvitationMediaRules;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * A cover that passed the `image` rule (its header looks fine) but that
 * Intervention could not decode — a truncated or corrupt file. Thrown by
 * InvitationMediaStager::storeCover(); each caller reports it on its own field.
 */
class UnreadableCoverImageException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(InvitationMediaRules::COVER_UNREADABLE_MESSAGE, 0, $previous);
    }

    public function toValidationException(string $field): ValidationException
    {
        return ValidationException::withMessages([$field => $this->getMessage()]);
    }
}
