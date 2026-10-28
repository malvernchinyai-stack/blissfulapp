<?php
declare(strict_types=1);

namespace Blissful\Http;

final class Response
{
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = []
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function error(ApiError $e): self
    {
        $payload = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
        if ($e->fields !== []) {
            $payload['error']['fields'] = $e->fields;
        }
        return self::json($payload, $e->status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $to): self
    {
        return new self(303, '', ['Location' => $to]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Strict-Transport-Security' => 'max-age=31536000',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
        foreach ($defaults + $this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }
}
