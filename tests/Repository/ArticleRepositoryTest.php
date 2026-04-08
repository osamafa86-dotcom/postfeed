<?php
declare(strict_types=1);

namespace Tests\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use NewsFlow\Repository\ArticleRepository;

final class ArticleRepositoryTest extends TestCase
{
    private PDO $db;
    private ArticleRepository $repo;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec("CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT, slug TEXT, css_class TEXT
        )");
        $this->db->exec("CREATE TABLE sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT, logo_letter TEXT, logo_color TEXT, logo_bg TEXT
        )");
        $this->db->exec("CREATE TABLE articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT, slug TEXT, excerpt TEXT, content TEXT,
            image_url TEXT, source_url TEXT,
            category_id INTEGER, source_id INTEGER,
            is_breaking INTEGER DEFAULT 0,
            is_hero INTEGER DEFAULT 0,
            status TEXT DEFAULT 'published',
            published_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("INSERT INTO categories (name, slug, css_class) VALUES ('سياسة','political','cat-political')");
        $this->db->exec("INSERT INTO sources (name) VALUES ('وكالة سند')");

        $this->repo = new ArticleRepository($this->db);
    }

    public function testInsertAndFind(): void
    {
        $id = $this->repo->insert([
            'title'        => 'عنوان تجريبي',
            'slug'         => 'test-slug',
            'excerpt'      => 'خلاصة',
            'content'      => '<p>محتوى</p>',
            'image_url'    => '',
            'source_url'   => 'https://example.com/a',
            'category_id'  => 1,
            'source_id'    => 1,
            'status'       => 'published',
            'published_at' => '2026-04-08 10:00:00',
        ]);

        $this->assertGreaterThan(0, $id);
        $row = $this->repo->findById($id);
        $this->assertNotNull($row);
        $this->assertSame('عنوان تجريبي', $row['title']);
    }

    public function testExistsByTitleAndSource(): void
    {
        $this->repo->insert([
            'title' => 'خبر فريد',
            'slug'  => 'unique',
            'content' => 'x',
            'source_id' => 1,
            'category_id' => 1,
        ]);

        $this->assertTrue($this->repo->existsByTitleAndSource('خبر فريد', 1));
        $this->assertFalse($this->repo->existsByTitleAndSource('خبر مختلف', 1));
        $this->assertFalse($this->repo->existsByTitleAndSource('خبر فريد', 2));
    }

    public function testGetLatestRespectsLimitAndFilters(): void
    {
        // Insert 3 published, 1 breaking (excluded), 1 hero (excluded)
        for ($i = 0; $i < 3; $i++) {
            $this->repo->insert([
                'title' => "خبر $i",
                'slug' => "s$i",
                'content' => 'x',
                'source_id' => 1,
                'category_id' => 1,
                'published_at' => sprintf('2026-04-08 10:0%d:00', $i),
            ]);
        }
        $this->db->exec("INSERT INTO articles (title, slug, content, is_breaking, status, published_at) VALUES ('brk','brk','x',1,'published','2026-04-08 10:05:00')");
        $this->db->exec("INSERT INTO articles (title, slug, content, is_hero, status, published_at) VALUES ('hero','hero','x',1,'published','2026-04-08 10:06:00')");

        $rows = $this->repo->getLatest(10);
        $this->assertCount(3, $rows);
        $titles = array_column($rows, 'title');
        $this->assertNotContains('brk', $titles);
        $this->assertNotContains('hero', $titles);
    }

    public function testCountBySource(): void
    {
        $this->db->exec("INSERT INTO sources (name) VALUES ('مصدر آخر')");
        $this->repo->insert(['title'=>'a','slug'=>'a','content'=>'x','source_id'=>1,'category_id'=>1]);
        $this->repo->insert(['title'=>'b','slug'=>'b','content'=>'x','source_id'=>1,'category_id'=>1]);
        $this->repo->insert(['title'=>'c','slug'=>'c','content'=>'x','source_id'=>2,'category_id'=>1]);

        $this->assertSame(2, $this->repo->countBySource(1));
        $this->assertSame(1, $this->repo->countBySource(2));
    }

    public function testDelete(): void
    {
        $id = $this->repo->insert(['title'=>'rm','slug'=>'rm','content'=>'x','source_id'=>1,'category_id'=>1]);
        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->findById($id));
    }
}
