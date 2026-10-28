<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Config;
use Blissful\Db;
use Blissful\Http\ApiError;
use Blissful\Support\Clock;
use Blissful\Support\Settings;

/**
 * Uploads are stored outside the web root with random names. Type is detected from file content
 * (not the client-supplied name/MIME), size is capped per purpose, and private files are only
 * streamed to their owner or authorised staff.
 */
final class FileService
{
    public const RULES = [
        'avatar' => ['max' => 5_000_000, 'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 'visibility' => 'public'],
        'portfolio' => ['max' => 10_000_000, 'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav'], 'visibility' => 'public'],
        'verification' => ['max' => 8_000_000, 'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'], 'visibility' => 'private'],
    ];

    /** @param array{tmp_name:string,size:int,error:int} $upload */
    public function store(int $ownerId, string $purpose, array $upload, bool $alreadyMoved = false): array
    {
        $rule = self::RULES[$purpose] ?? null;
        if ($rule === null) {
            throw ApiError::validation(['purpose' => 'Unknown upload purpose.']);
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw ApiError::validation(['file' => 'Upload failed or no file was sent.']);
        }
        $tmp = $upload['tmp_name'];
        if (!$alreadyMoved && !is_uploaded_file($tmp)) {
            throw ApiError::validation(['file' => 'Upload failed.']);
        }
        $size = filesize($tmp);
        if ($size === false || $size === 0 || $size > $rule['max']) {
            throw ApiError::validation(['file' => 'File is empty or larger than ' . intdiv($rule['max'], 1_000_000) . ' MB.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
        if (!isset($rule['mimes'][$mime])) {
            throw ApiError::validation(['file' => 'This file type is not allowed.']);
        }
        if (str_starts_with($mime, 'image/') && @getimagesize($tmp) === false) {
            throw ApiError::validation(['file' => 'The image could not be read.']);
        }
        $count = (int) Db::get()->value('SELECT COUNT(*) FROM files WHERE owner_id = ? AND deleted_at IS NULL', [$ownerId]);
        if ($count >= 60) {
            throw ApiError::conflict('file_quota', 'You have reached the upload limit. Remove some files first.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $rule['mimes'][$mime];
        $dir = Config::storagePath('uploads/' . $rule['visibility']);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $dest = $dir . '/' . $name;
        $ok = $alreadyMoved ? rename($tmp, $dest) : move_uploaded_file($tmp, $dest);
        if (!$ok) {
            throw new \RuntimeException('Could not store upload');
        }
        chmod($dest, 0640);
        $retain = null;
        if ($purpose === 'verification') {
            $days = Settings::int('verification_doc_retention_days', 90);
            $retain = Clock::db(Clock::now()->modify("+{$days} days"));
        }
        $id = Db::get()->insert('files', [
            'owner_id' => $ownerId, 'purpose' => $purpose, 'visibility' => $rule['visibility'], 'storage_name' => $name,
            'mime' => $mime, 'size_bytes' => $size, 'sha256' => hash_file('sha256', $dest), 'retain_until' => $retain,
            'created_at' => Clock::db(),
        ]);
        return ['id' => $id, 'mime' => $mime, 'size_bytes' => $size, 'url' => self::url($id, $rule['visibility'])];
    }

    public static function url(?int $fileId, string $visibility = 'public'): ?string
    {
        if ($fileId === null) {
            return null;
        }
        return rtrim(Config::get('APP_URL', ''), '/') . '/api/files/' . $fileId;
    }

    /** @return array{path:string,mime:string,size:int}|null */
    public function resolveForDownload(int $fileId, ?array $viewer, bool $isStaff = false): ?array
    {
        $f = Db::get()->one('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL', [$fileId]);
        if ($f === null) {
            return null;
        }
        if ($f['visibility'] === 'private') {
            $owner = $viewer !== null && (int) $viewer['id'] === (int) $f['owner_id'];
            if (!$owner && !$isStaff) {
                return null; // 404 rather than 403: don't reveal private file ids
            }
        }
        $path = Config::storagePath('uploads/' . $f['visibility'] . '/' . $f['storage_name']);
        return is_file($path) ? ['path' => $path, 'mime' => $f['mime'], 'size' => (int) $f['size_bytes']] : null;
    }

    public function softDelete(int $ownerId, int $fileId): void
    {
        Db::get()->exec('UPDATE files SET deleted_at = ? WHERE id = ? AND owner_id = ? AND deleted_at IS NULL', [Clock::db(), $fileId, $ownerId]);
    }

    /** Physically removes soft-deleted files and expired verification documents. Run by cron. */
    public function purgeDeleted(): int
    {
        $db = Db::get();
        $now = Clock::db();
        $db->exec("UPDATE files SET deleted_at = ? WHERE purpose = 'verification' AND deleted_at IS NULL AND retain_until IS NOT NULL AND retain_until < ?", [$now, $now]);
        $n = 0;
        foreach ($db->all('SELECT id, visibility, storage_name FROM files WHERE deleted_at IS NOT NULL') as $f) {
            $p = Config::storagePath('uploads/' . $f['visibility'] . '/' . $f['storage_name']);
            if (is_file($p)) {
                @unlink($p);
            }
            $db->exec('DELETE FROM files WHERE id = ?', [$f['id']]);
            $n++;
        }
        return $n;
    }
}
