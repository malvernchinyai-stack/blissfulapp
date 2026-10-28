<?php
declare(strict_types=1);

namespace Blissful\Http;

final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = []
    ) {
        parent::__construct($message);
    }

    public static function validation(array $fields): self
    {
        return new self(422, 'validation_failed', 'Some fields need attention.', $fields);
    }

    public static function notFound(string $what = 'Resource'): self
    {
        return new self(404, 'not_found', "{$what} not found.");
    }

    public static function forbidden(string $message = 'You are not allowed to do that.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    public static function unauthorized(string $message = 'Please sign in again.'): self
    {
        return new self(401, 'unauthorized', $message);
    }
}
