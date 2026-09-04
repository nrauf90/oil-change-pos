<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Someone else saved this bill between the moment it was opened and the moment
 * it was saved back. Refusing is the only safe answer: the alternative silently
 * throws away whichever of the two sets of changes arrived first.
 */
class StaleDraftOrderException extends RuntimeException
{
    public function __construct(public readonly ?string $lastSavedBy = null)
    {
        parent::__construct($this->buildMessage());
    }

    private function buildMessage(): string
    {
        return $this->lastSavedBy === null
            ? 'This bill was changed by someone else while you had it open. Reopen it to see their changes.'
            : sprintf(
                '%s changed this bill while you had it open. Reopen it to see their changes.',
                $this->lastSavedBy,
            );
    }
}
