<?php
declare(strict_types=1);

namespace Blissful\Support;

use Blissful\Config;

/**
 * MAIL_DRIVER=log   → writes mail to storage/mail/ (development, tests)
 * MAIL_DRIVER=mail  → PHP mail(), i.e. the DirectAdmin host's Exim MTA
 */
final class Mailer
{
    public static array $sent = [];

    public static function send(string $to, string $subject, string $text): bool
    {
        $driver = Config::get('MAIL_DRIVER', 'log');
        $from = Config::get('MAIL_FROM', 'no-reply@blissfulevents.co.zw');
        $prefix = Config::env() === 'production' ? '' : '[Testing] ';
        $subject = $prefix . $subject;
        if ($driver === 'log') {
            self::$sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
            $dir = Config::storagePath('mail');
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            @file_put_contents($dir . '/' . Clock::now()->format('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.txt',
                "To: {$to}\nSubject: {$subject}\n\n{$text}\n");
            return true;
        }
        $headers = "From: Blissful Gigs <{$from}>\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
        $ok = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers, '-f' . $from);
        if (!$ok) {
            Log::warning('mail_failed', ['subject' => $subject]);
        }
        return $ok;
    }
}
