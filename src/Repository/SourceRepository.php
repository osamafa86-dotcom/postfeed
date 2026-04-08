<?php
declare(strict_types=1);

namespace NewsFlow\Repository;

use PDO;
use NewsFlow\Database;

final class SourceRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** @return array<int, array<string, mixed>> */
    public function activeWithRss(): array
    {
        return $this->db
            ->query("SELECT * FROM sources WHERE is_active = 1 AND rss_url IS NOT NULL AND rss_url != ''")
            ->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->query("SELECT * FROM sources ORDER BY name")->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM sources WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateFetchState(int $sourceId, ?string $error, int $newCount, int $total): void
    {
        $stmt = $this->db->prepare(
            "UPDATE sources SET last_fetched_at = NOW(), last_error = ?,
             last_new_count = ?, total_articles = ? WHERE id = ?"
        );
        $stmt->execute([$error, $newCount, $total, $sourceId]);
    }
}
