<?php
declare(strict_types=1);

namespace Blissful\Support;

use Blissful\Http\ApiError;
use DateTimeImmutable;

/**
 * Collects field errors and returns cleaned values. Call done() to throw a 422 if anything failed.
 * Strings are trimmed and stripped of control characters; output escaping happens at render time.
 */
final class Validator
{
    private array $errors = [];

    public function __construct(private readonly array $data)
    {
    }

    public function has(string $f): bool
    {
        return array_key_exists($f, $this->data) && $this->data[$f] !== null;
    }

    public function string(string $f, int $min = 0, int $max = 255, bool $required = true, ?string $default = null): ?string
    {
        if (!$this->has($f) || (is_string($this->data[$f]) && trim($this->data[$f]) === '')) {
            if ($required) {
                $this->errors[$f] = 'This field is required.';
            }
            return $default;
        }
        $v = $this->data[$f];
        if (!is_string($v)) {
            $this->errors[$f] = 'Must be text.';
            return $default;
        }
        $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '');
        $len = mb_strlen($v);
        if ($len < $min) {
            $this->errors[$f] = "Must be at least {$min} characters.";
        } elseif ($len > $max) {
            $this->errors[$f] = "Must be at most {$max} characters.";
        }
        return $v;
    }

    public function email(string $f, bool $required = true): ?string
    {
        $v = $this->string($f, 3, 190, $required);
        if ($v !== null && !isset($this->errors[$f]) && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$f] = 'Enter a valid email address.';
        }
        return $v === null ? null : mb_strtolower($v);
    }

    public function phone(string $f, bool $required = false): ?string
    {
        $v = $this->string($f, 7, 20, $required);
        if ($v !== null && !isset($this->errors[$f])) {
            $v = preg_replace('/[\s\-()]/', '', $v);
            if (!preg_match('/^\+?\d{7,15}$/', $v)) {
                $this->errors[$f] = 'Enter a valid phone number, e.g. +263771234567.';
            }
        }
        return $v;
    }

    public function password(string $f): ?string
    {
        if (!$this->has($f) || !is_string($this->data[$f])) {
            $this->errors[$f] = 'This field is required.';
            return null;
        }
        $v = $this->data[$f];
        if (strlen($v) < 10) {
            $this->errors[$f] = 'Use at least 10 characters.';
        } elseif (strlen($v) > 200) {
            $this->errors[$f] = 'Password is too long.';
        } elseif (!preg_match('/[A-Za-z]/', $v) || !preg_match('/\d/', $v)) {
            $this->errors[$f] = 'Use letters and at least one number.';
        }
        return $v;
    }

    public function int(string $f, int $min, int $max, bool $required = true, ?int $default = null): ?int
    {
        if (!$this->has($f)) {
            if ($required) {
                $this->errors[$f] = 'This field is required.';
            }
            return $default;
        }
        $v = $this->data[$f];
        if (is_string($v) && preg_match('/^-?\d{1,15}$/', $v)) {
            $v = (int) $v;
        }
        if (!is_int($v)) {
            $this->errors[$f] = 'Must be a whole number.';
            return $default;
        }
        if ($v < $min || $v > $max) {
            $this->errors[$f] = "Must be between {$min} and {$max}.";
        }
        return $v;
    }

    /** Money in minor units (integer cents). Floats are rejected on purpose. */
    public function money(string $f, bool $required = true, int $max = 10_000_000_00): ?int
    {
        if ($this->has($f) && is_float($this->data[$f])) {
            $this->errors[$f] = 'Send amounts as whole cents (integer minor units).';
            return null;
        }
        return $this->int($f, 0, $max, $required, $required ? null : 0);
    }

    public function bool(string $f, bool $default = false): bool
    {
        if (!$this->has($f)) {
            return $default;
        }
        if (!is_bool($this->data[$f])) {
            $this->errors[$f] = 'Must be true or false.';
            return $default;
        }
        return $this->data[$f];
    }

    public function enum(string $f, array $allowed, bool $required = true, ?string $default = null): ?string
    {
        if (!$this->has($f)) {
            if ($required) {
                $this->errors[$f] = 'This field is required.';
            }
            return $default;
        }
        $v = $this->data[$f];
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            $this->errors[$f] = 'Choose one of: ' . implode(', ', $allowed) . '.';
            return $default;
        }
        return $v;
    }

    public function datetime(string $f, bool $required = true): ?DateTimeImmutable
    {
        if (!$this->has($f)) {
            if ($required) {
                $this->errors[$f] = 'This field is required.';
            }
            return null;
        }
        $v = $this->data[$f];
        $dt = is_string($v) ? Clock::parseIso($v) : null;
        if ($dt === null) {
            $this->errors[$f] = 'Use an ISO 8601 date-time with a time zone offset.';
        }
        return $dt;
    }

    public function timezone(string $f, string $default = 'Africa/Harare'): string
    {
        if (!$this->has($f)) {
            return $default;
        }
        $v = $this->data[$f];
        if (!is_string($v) || !in_array($v, \DateTimeZone::listIdentifiers(), true)) {
            $this->errors[$f] = 'Unknown time zone.';
            return $default;
        }
        return $v;
    }

    public function currency(string $f, string $default = 'USD'): string
    {
        $v = $this->has($f) ? $this->data[$f] : $default;
        $allowed = Settings::get('supported_currencies', ['USD']);
        if (!is_string($v) || !in_array($v, $allowed, true) || !Money::isSupported($v)) {
            $this->errors[$f] = 'Unsupported currency.';
            return $default;
        }
        return $v;
    }

    public function decimal(string $f, float $min, float $max, bool $required = false): ?string
    {
        if (!$this->has($f)) {
            if ($required) {
                $this->errors[$f] = 'This field is required.';
            }
            return null;
        }
        $v = $this->data[$f];
        if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
            $this->errors[$f] = 'Must be a number.';
            return null;
        }
        if ((float) $v < $min || (float) $v > $max) {
            $this->errors[$f] = "Must be between {$min} and {$max}.";
            return null;
        }
        return number_format((float) $v, 6, '.', '');
    }

    public function url(string $f, bool $required = false): ?string
    {
        $v = $this->string($f, 8, 500, $required);
        if ($v !== null && !isset($this->errors[$f])) {
            $scheme = parse_url($v, PHP_URL_SCHEME);
            if (filter_var($v, FILTER_VALIDATE_URL) === false || $scheme !== 'https') {
                $this->errors[$f] = 'Use a full https:// link.';
            }
        }
        return $v;
    }

    /** @return int[] */
    public function intList(string $f, int $maxItems, bool $required = false): array
    {
        if (!$this->has($f)) {
            if ($required) {
                $this->errors[$f] = 'This field is required.';
            }
            return [];
        }
        $v = $this->data[$f];
        if (!is_array($v) || !array_is_list($v) || count($v) > $maxItems) {
            $this->errors[$f] = "Must be a list of up to {$maxItems} items.";
            return [];
        }
        foreach ($v as $item) {
            if (!is_int($item) || $item < 1) {
                $this->errors[$f] = 'List items must be ids.';
                return [];
            }
        }
        return array_values(array_unique($v));
    }

    public function raw(string $f): mixed
    {
        return $this->data[$f] ?? null;
    }

    public function addError(string $f, string $message): void
    {
        $this->errors[$f] = $message;
    }

    public function done(): void
    {
        if ($this->errors !== []) {
            throw ApiError::validation($this->errors);
        }
    }
}
