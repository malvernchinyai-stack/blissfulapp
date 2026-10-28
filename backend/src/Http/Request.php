<?php
declare(strict_types=1);

namespace Blissful\Http;

final class Request
{
    public array $params = [];
    public ?array $user = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $rawBody,
        public readonly array $post = [],
        public readonly array $files = [],
        public readonly string $ip = '0.0.0.0'
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        // Apache on some shared hosts strips Authorization unless rewritten into this variable.
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $headers,
            (string) file_get_contents('php://input', false, null, 0, 1_048_576),
            $_POST,
            $_FILES,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Parsed JSON body; throws 400 on malformed JSON. */
    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }
        try {
            $data = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiError(400, 'bad_json', 'The request body is not valid JSON.');
        }
        if (!is_array($data)) {
            throw new ApiError(400, 'bad_json', 'The request body must be a JSON object.');
        }
        return $data;
    }

    public function bearer(): ?string
    {
        $h = $this->header('authorization');
        if ($h !== null && preg_match('/^Bearer\s+([A-Za-z0-9_\-]{20,200})$/', $h, $m)) {
            return $m[1];
        }
        return null;
    }

    public function userId(): int
    {
        if ($this->user === null) {
            throw ApiError::unauthorized();
        }
        return (int) $this->user['id'];
    }

    public function intParam(string $name): int
    {
        $v = $this->params[$name] ?? '';
        if (!ctype_digit((string) $v)) {
            throw ApiError::notFound();
        }
        return (int) $v;
    }

    public function queryInt(string $name, int $default, int $min, int $max): int
    {
        $v = $this->query[$name] ?? null;
        if ($v === null || !is_string($v) || !preg_match('/^-?\d+$/', $v)) {
            return $default;
        }
        return max($min, min($max, (int) $v));
    }
}
