<?php
declare(strict_types=1);

namespace Blissful\Support;

use Blissful\Db;

final class Audit
{
    public static function record(?int $actorId, string $action, ?string $targetType = null, ?int $targetId = null, array $meta = [], ?string $ip = null): void
    {
        Db::get()->insert('audit_logs', [
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip' => $ip,
            'meta_json' => $meta === [] ? null : json_encode(Log::redact($meta)),
            'created_at' => Clock::db(),
        ]);
    }
}
