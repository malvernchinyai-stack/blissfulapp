<?php
declare(strict_types=1);

// Creates (or promotes) a staff account. Usage:
//   php bin/create-admin.php email@example.com "Full Name" superadmin|moderator|support|finance
// Prints a one-time password; TOTP enrolment is forced at first admin sign-in.
require dirname(__DIR__) . '/src/bootstrap.php';

use Blissful\Db;
use Blissful\Support\Audit;
use Blissful\Support\Clock;
use Blissful\Support\Crypto;

if (PHP_SAPI !== 'cli' || $argc < 4) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <email> <name> <role>\n");
    exit(1);
}
[$_, $email, $name, $role] = $argv;
if (!in_array($role, ['superadmin', 'moderator', 'support', 'finance'], true) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email or role.\n");
    exit(1);
}
$db = Db::get();
$password = Crypto::token(15);
$now = Clock::db();
$user = $db->one('SELECT id FROM users WHERE email = ?', [strtolower($email)]);
if ($user === null) {
    $id = $db->insert('users', ['email' => strtolower($email), 'password_hash' => Crypto::hashPassword($password), 'display_name' => $name,
        'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $db->insert('client_profiles', ['user_id' => $id, 'name' => $name, 'updated_at' => $now]);
} else {
    $id = (int) $user['id'];
    $db->exec('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [Crypto::hashPassword($password), $now, $id]);
}
$db->exec('INSERT INTO staff (user_id, role, totp_enabled, created_at) VALUES (?,?,0,?) ON DUPLICATE KEY UPDATE role = VALUES(role), totp_enabled = 0, totp_secret_enc = NULL',
    [$id, $role, $now]);
Audit::record(null, 'staff.created_cli', 'user', $id, ['role' => $role]);
echo "Staff account ready: {$email} ({$role})\nTemporary password: {$password}\nSign in at /admin and enrol an authenticator app. Change the password afterwards.\n";
