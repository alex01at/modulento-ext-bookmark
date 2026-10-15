<?php

declare(strict_types=1);

namespace Modulento\Bookmark;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * An account's own bookmarks of offers and providers. A bookmark is just
 * its row - there is nothing to update, only to add or remove - and it is
 * never joined to the core's offer/provider tables by a foreign key: a
 * bookmark outlives whatever later happens to its target, and listing for
 * an account reads the current offer/provider through the core's own
 * classes, which decide on their own what still exists and is public.
 */
final class Bookmarks
{
    public const KINDS = ['offer', 'provider'];

    public function __construct(private PDO $db)
    {
    }

    /** Adds or removes the bookmark; returns whether it is bookmarked now. */
    public function toggle(int $accountId, string $kind, int $targetId): bool
    {
        if ($this->has($accountId, $kind, $targetId)) {
            $stmt = $this->db->prepare('DELETE FROM x_bookmark_bookmark WHERE account_id = :account_id AND kind = :kind AND target_id = :target_id');
            $stmt->execute(['account_id' => $accountId, 'kind' => $kind, 'target_id' => $targetId]);

            return false;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO x_bookmark_bookmark (account_id, kind, target_id, created_at) VALUES (:account_id, :kind, :target_id, :now)'
        );
        $stmt->execute(['account_id' => $accountId, 'kind' => $kind, 'target_id' => $targetId, 'now' => Clock::now()]);

        return true;
    }

    public function has(int $accountId, string $kind, int $targetId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM x_bookmark_bookmark WHERE account_id = :account_id AND kind = :kind AND target_id = :target_id');
        $stmt->execute(['account_id' => $accountId, 'kind' => $kind, 'target_id' => $targetId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<int> target ids, newest bookmark first */
    public function targetIds(int $accountId, string $kind): array
    {
        $stmt = $this->db->prepare('SELECT target_id FROM x_bookmark_bookmark WHERE account_id = :account_id AND kind = :kind ORDER BY id DESC');
        $stmt->execute(['account_id' => $accountId, 'kind' => $kind]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
