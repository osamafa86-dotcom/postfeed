<?php
/**
 * News Maps — data layer.
 *
 * One row per (article, primary location) linking the
 * articles table to a geographic point. `extracted_by`
 * tracks provenance so admin can filter for AI-generated
 * vs. manually-corrected entries.
 *
 * Primary use: /map page queries the last N days of active
 * locations joined with articles for clustered markers.
 */

if (!function_exists('nm_ensure_table')) {

function nm_ensure_table(): void {
    static $ensured = false;
    if ($ensured) return;
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS article_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        article_id INT NOT NULL,
        latitude  DECIMAL(10, 7) NOT NULL,
        longitude DECIMAL(10, 7) NOT NULL,
        place_name_ar VARCHAR(255) NOT NULL DEFAULT '',
        place_name_en VARCHAR(255) NOT NULL DEFAULT '',
        country_code CHAR(2) NOT NULL DEFAULT '',
        admin_region VARCHAR(100) NOT NULL DEFAULT '',
        confidence FLOAT NOT NULL DEFAULT 0,
        extracted_by ENUM('gazetteer','ai','manual','geotag') NOT NULL DEFAULT 'gazetteer',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_article (article_id),
        INDEX idx_coords (latitude, longitude),
        INDEX idx_country (country_code),
        INDEX idx_created (created_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ensured = true;
}

function nm_save_location(int $articleId, array $loc): bool {
    nm_ensure_table();
    if (empty($loc['lat']) || empty($loc['lng'])) return false;
    $stmt = getDB()->prepare(
        "INSERT INTO article_locations
           (article_id, latitude, longitude, place_name_ar, place_name_en,
            country_code, admin_region, confidence, extracted_by)
         VALUES (:id, :lat, :lng, :ar, :en, :cc, :ar_reg, :conf, :by)
         ON DUPLICATE KEY UPDATE
           latitude=VALUES(latitude), longitude=VALUES(longitude),
           place_name_ar=VALUES(place_name_ar), place_name_en=VALUES(place_name_en),
           country_code=VALUES(country_code), admin_region=VALUES(admin_region),
           confidence=VALUES(confidence), extracted_by=VALUES(extracted_by)"
    );
    return $stmt->execute([
        ':id'     => $articleId,
        ':lat'    => (float)$loc['lat'],
        ':lng'    => (float)$loc['lng'],
        ':ar'     => (string)($loc['place_ar'] ?? ''),
        ':en'     => (string)($loc['place_en'] ?? ''),
        ':cc'     => strtoupper((string)($loc['country'] ?? '')),
        ':ar_reg' => (string)($loc['region'] ?? ''),
        ':conf'   => (float)($loc['confidence'] ?? 0.5),
        ':by'     => (string)($loc['by'] ?? 'gazetteer'),
    ]);
}

function nm_delete_location(int $articleId): bool {
    nm_ensure_table();
    $stmt = getDB()->prepare("DELETE FROM article_locations WHERE article_id = ?");
    return $stmt->execute([$articleId]);
}

/**
 * Fetch the last N days of located articles, joined with the
 * article metadata needed by the map sidebar. Returns one row
 * per (article, location) — articles without a location don't
 * appear on the map.
 */
function nm_recent_locations(int $days = 7, int $limit = 500): array {
    nm_ensure_table();
    $days  = max(1, min(90, $days));
    $limit = max(1, min(2000, $limit));
    $stmt = getDB()->prepare(
        "SELECT a.id, a.title, a.slug, a.image_url, a.published_at,
                a.is_breaking, a.category_id,
                c.name AS cat_name, c.slug AS cat_slug,
                s.name AS source_name,
                l.latitude, l.longitude, l.place_name_ar, l.place_name_en,
                l.country_code, l.admin_region, l.extracted_by
           FROM article_locations l
           JOIN articles   a ON a.id = l.article_id
      LEFT JOIN categories c ON c.id = a.category_id
      LEFT JOIN sources    s ON s.id = a.source_id
          WHERE a.status = 'published'
            AND a.published_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
          ORDER BY a.published_at DESC
          LIMIT :lim"
    );
    $stmt->bindValue(':days', $days, PDO::PARAM_INT);
    $stmt->bindValue(':lim',  $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function nm_stats(): array {
    nm_ensure_table();
    $db = getDB();
    $total = (int)$db->query("SELECT COUNT(*) FROM article_locations")->fetchColumn();
    $last24 = (int)$db->query(
        "SELECT COUNT(*) FROM article_locations l JOIN articles a ON a.id=l.article_id
          WHERE a.published_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
    )->fetchColumn();
    $byCountry = $db->query(
        "SELECT country_code, COUNT(*) AS n FROM article_locations
          WHERE country_code <> ''
       GROUP BY country_code ORDER BY n DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);
    $byMethod = $db->query(
        "SELECT extracted_by, COUNT(*) AS n FROM article_locations
       GROUP BY extracted_by"
    )->fetchAll(PDO::FETCH_ASSOC);
    return [
        'total'         => $total,
        'last_24h'      => $last24,
        'by_country'    => $byCountry,
        'by_method'     => $byMethod,
    ];
}

} // function_exists guard
