<?php
namespace ArmmModsets\Armm;

use RuntimeException;

/**
 * An error whose message is safe to show to panel users. Any other exception is
 * reported to the panel log and answered with a generic message instead.
 */
final class ArmmException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 502)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
