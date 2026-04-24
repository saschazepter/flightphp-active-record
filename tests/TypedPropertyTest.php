<?php

namespace flight\tests;

use flight\tests\classes\TypedUser;
use PDO;

/**
 * Tests that insert() and update() correctly handle typed public properties
 * when values are assigned directly (bypassing __set / dirty tracking).
 */
class TypedPropertyTest extends \PHPUnit\Framework\TestCase
{
    protected PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/classes/TypedUser.php';
        @unlink('test_typed.db');
    }

    public static function tearDownAfterClass(): void
    {
        @unlink('test_typed.db');
    }

    public function setUp(): void
    {
        $this->pdo = new PDO('sqlite:test_typed.db');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user (
            id INTEGER PRIMARY KEY,
            name TEXT,
            password TEXT,
            created_dt TEXT
        )");
    }

    public function tearDown(): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS user");
    }

    public function testInsertWithTypedProperties(): void
    {
        $user = new TypedUser($this->pdo);
        $user->name = 'charlie';
        $user->password = 'hash3';
        $user->insert();

        // Verify persisted via raw query (avoids isHydrated/lastInsertId dependencies)
        $row = $this->pdo->query("SELECT * FROM user WHERE name = 'charlie'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'insert should persist when properties are set directly');
        $this->assertSame('charlie', $row['name']);
        $this->assertSame('hash3', $row['password']);
    }

    public function testUpdateWithTypedProperties(): void
    {
        $this->pdo->exec("INSERT INTO user (name, password) VALUES ('eve', 'hash5')");

        // Use the untyped User to find (avoids isHydrated dependency)
        // Then update via typed property assignment
        $user = new TypedUser($this->pdo);
        $user->dirty(['name' => 'eve']); // simulate populating data
        $user->eq('name', 'eve')->find();

        // Direct property assignment should be detected by syncDirtyFromProperties
        $user->name = 'eve_updated';
        $user->save();

        $row = $this->pdo->query("SELECT * FROM user WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('eve_updated', $row['name'], 'update should persist changed typed property');
    }

    public function testUpdateDoesNotTouchUnchangedFields(): void
    {
        $this->pdo->exec("INSERT INTO user (name, password) VALUES ('frank', 'hash6')");

        $user = new TypedUser($this->pdo);
        $user->eq('id', 1)->find();
        $user->name = 'frank_updated';
        $user->save();

        $row = $this->pdo->query("SELECT * FROM user WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('frank_updated', $row['name']);
        $this->assertSame('hash6', $row['password'], 'unchanged field should not be modified');
    }
}
