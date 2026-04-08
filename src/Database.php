<?php
declare(strict_types=1);

namespace NewsFlow;

use PDO;
use PDOException;

/**
 * PDO wrapper with singleton access.
 * Reuses the legacy getDB() connection if available so the new OO code
 * and existing procedural code share the same connection.
 */
final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance !== null) return self::$instance;

        if (function_exists('getDB')) {
            self::$instance = getDB();
            return self::$instance;
        }

        throw new \RuntimeException('Database not initialised and getDB() not available');
    }

    /**
     * Inject an existing PDO (for tests).
     */
    public static function setConnection(PDO $pdo): void
    {
        self::$instance = $pdo;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
