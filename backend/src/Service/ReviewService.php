<?php
declare(strict_types=1);

namespace Blissful\Service;

use Blissful\Db;
use Blissful\Http\ApiError;
use Blissful\Support\Clock;
use Blissful\Support\Validator;

final class ReviewService
{
    public function create(int $userId, int $bookingId, array $input): array
    {
        $v = new Validator($input);
        $rating = $v->int('rating', 1, 5);
        $comment = $v->string('comment', 0, 1500, false);
        $v->done();
        return Db::get()->tx(function (Db $db) use ($userId, $bookingId, $rating, $comment) {
            $b = $db->one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            if ($b === null || ((int) $b['client_id'] !== $userId && (int) $b['musician_id'] !== $userId)) {
                throw ApiError::notFound('Booking');
            }
            if ($b['status'] !== 'completed') {
                throw ApiError::conflict('not_completed', 'You can review a booking once it is completed.');
            }
            if (Clock::fromDb($b['completed_at']) < Clock::now()->modify('-30 days')) {
                throw ApiError::conflict('review_window_closed', 'Reviews can be left up to 30 days after completion.');
            }
            $isClient = (int) $b['client_id'] === $userId;
            $subject = $isClient ? (int) $b['musician_id'] : (int) $b['client_id'];
            if ($subject === $userId) {
                throw ApiError::forbidden('You cannot review yourself.');
            }
            if ($db->value('SELECT 1 FROM reviews WHERE booking_id = ? AND author_id = ?', [$bookingId, $userId])) {
                throw ApiError::conflict('already_reviewed', 'You have already reviewed this booking.');
            }
            $role = $isClient ? 'musician' : 'client';
            $id = $db->insert('reviews', ['booking_id' => $bookingId, 'author_id' => $userId, 'subject_id' => $subject, 'subject_role' => $role,
                'rating' => $rating, 'comment' => $comment, 'created_at' => Clock::db()]);
            $table = $role === 'musician' ? 'musician_profiles' : 'client_profiles';
            $db->exec("UPDATE {$table} SET rating_sum = rating_sum + ?, rating_count = rating_count + 1 WHERE user_id = ?", [$rating, $subject]);
            NotificationService::send($subject, 'review_received', 'You received a review', str_repeat('★', $rating) . str_repeat('☆', 5 - $rating),
                ['booking_id' => $bookingId], "review:{$id}");
            return ['id' => $id, 'rating' => $rating, 'comment' => $comment];
        });
    }

    /** Moderation: hiding a review removes it from aggregates too. */
    public static function setHidden(int $reviewId, bool $hidden): void
    {
        Db::get()->tx(function (Db $db) use ($reviewId, $hidden) {
            $r = $db->one('SELECT * FROM reviews WHERE id = ? FOR UPDATE', [$reviewId]);
            if ($r === null || (bool) $r['is_hidden'] === $hidden) {
                return;
            }
            $db->exec('UPDATE reviews SET is_hidden = ? WHERE id = ?', [$hidden ? 1 : 0, $reviewId]);
            $table = $r['subject_role'] === 'musician' ? 'musician_profiles' : 'client_profiles';
            $sign = $hidden ? -1 : 1;
            $db->exec("UPDATE {$table} SET rating_sum = rating_sum + ?, rating_count = rating_count + ? WHERE user_id = ?",
                [$sign * (int) $r['rating'], $sign, $r['subject_id']]);
        });
    }
}
