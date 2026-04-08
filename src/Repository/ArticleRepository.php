<?php
declare(strict_types=1);

namespace NewsFlow\Repository;

use PDO;
use NewsFlow\Database;

/**
 * Centralised access to the articles table.
 * All SQL for articles lives here. Legacy procedural helpers delegate here.
 */
final class ArticleRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** @return array<int, array<string, mixed>> */
    public function getHero(int $limit = 3): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                    s.name as source_name, s.logo_letter, s.logo_color, s.logo_bg
             FROM articles a
             LEFT JOIN categories c ON a.category_id = c.id
             LEFT JOIN sources s ON a.source_id = s.id
             WHERE a.is_hero = 1 AND a.status = 'published'
             ORDER BY a.published_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function getLatest(int $limit = 6): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                    s.name as source_name, s.logo_color
             FROM articles a
             LEFT JOIN categories c ON a.category_id = c.id
             LEFT JOIN sources s ON a.source_id = s.id
             WHERE a.status = 'published' AND a.is_breaking = 0 AND a.is_hero = 0
             ORDER BY a.published_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function getByCategory(string $slug, int $limit = 4): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                    s.name as source_name, s.logo_color
             FROM articles a
             LEFT JOIN categories c ON a.category_id = c.id
             LEFT JOIN sources s ON a.source_id = s.id
             WHERE c.slug = ? AND a.status = 'published'
               AND a.is_breaking = 0 AND a.is_hero = 0
             ORDER BY a.published_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, $slug, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function getBreaking(int $limit = 5): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, c.name as cat_name, c.css_class, s.name as source_name
             FROM articles a
             LEFT JOIN categories c ON a.category_id = c.id
             LEFT JOIN sources s ON a.source_id = s.id
             WHERE a.is_breaking = 1 AND a.status = 'published'
               AND a.published_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY a.published_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (count($rows) >= 3) return $rows;

        // Fallback
        $stmt = $this->db->prepare(
            "SELECT a.*, c.name as cat_name, c.css_class, s.name as source_name
             FROM articles a
             LEFT JOIN categories c ON a.category_id = c.id
             LEFT JOIN sources s ON a.source_id = s.id
             WHERE a.status = 'published'
             ORDER BY a.published_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function getPalestine(int $limit = 6): array
    {
        $keywords = ['فلسطين','غزة','الضفة','القدس','الاحتلال','الفلسطيني','حماس','المقاومة','الأقصى','رفح','خان يونس','جنين','نابلس','طوفان','الشهداء','شهيد'];
        $conditions = [];
        $params = [];
        foreach ($keywords as $kw) {
            $conditions[] = 'a.title LIKE ?';
            $params[] = '%' . $kw . '%';
        }
        $where = '(' . implode(' OR ', $conditions) . ')';

        $sql = "SELECT a.*, c.name as cat_name, c.slug as cat_slug, c.css_class,
                       s.name as source_name, s.logo_color
                FROM articles a
                LEFT JOIN categories c ON a.category_id = c.id
                LEFT JOIN sources s ON a.source_id = s.id
                WHERE {$where} AND a.status = 'published'
                ORDER BY a.published_at DESC LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $i = 1;
        foreach ($params as $p) $stmt->bindValue($i++, $p, PDO::PARAM_STR);
        $stmt->bindValue($i, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM articles WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function existsByTitleAndSource(string $title, int $sourceId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM articles WHERE title = ? AND source_id = ?");
        $stmt->execute([$title, $sourceId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO articles
             (title, slug, excerpt, content, image_url, source_url, category_id, source_id, status, published_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['title'],
            $data['slug'],
            $data['excerpt'] ?? '',
            $data['content'] ?? '',
            $data['image_url'] ?? '',
            $data['source_url'] ?? '',
            $data['category_id'] ?? null,
            $data['source_id'] ?? null,
            $data['status'] ?? 'published',
            $data['published_at'] ?? date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM articles WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function countBySource(int $sourceId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM articles WHERE source_id = ?");
        $stmt->execute([$sourceId]);
        return (int) $stmt->fetchColumn();
    }

    public function countAll(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
    }
}
