<?php

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\Migration as CiMigration;
use MedigitalDev\Ci4Base\Databases\BaseMigration;
use PHPUnit\Framework\TestCase;

final class BaseMigrationTest extends TestCase
{
    public function testCreatesAndDropsPostgreSchemaWithEscapedName(): void
    {
        $db = new class {
            public array $queries = [];

            public function getPlatform(): string
            {
                return 'Postgre';
            }

            public function query(string $query): bool
            {
                $this->queries[] = $query;

                return true;
            }
        };
        $migration = $this->makeMigration($db, new stdClass());

        $migration->createSchema('my"schema');
        $migration->dropSchema('my"schema');

        $this->assertSame([
            'CREATE SCHEMA "my""schema"',
            'DROP SCHEMA "my""schema"',
        ], $db->queries);
    }

    public function testCreatesAndDropsSqlServerSchemaWithEscapedName(): void
    {
        $db = new class {
            public array $queries = [];

            public function getPlatform(): string
            {
                return 'SQLSRV';
            }

            public function query(string $query): bool
            {
                $this->queries[] = $query;

                return true;
            }
        };
        $migration = $this->makeMigration($db, new stdClass());

        $migration->createSchema('my]schema');
        $migration->dropSchema('my]schema');

        $this->assertSame([
            'CREATE SCHEMA [my]]schema]',
            'DROP SCHEMA [my]]schema]',
        ], $db->queries);
    }

    public function testUsesForgeForMysqlSchemaCreationAndRemoval(): void
    {
        $forge = new class {
            public array $calls = [];

            public function createDatabase(string $name): bool
            {
                $this->calls[] = ['createDatabase', $name];

                return true;
            }

            public function dropDatabase(string $name): bool
            {
                $this->calls[] = ['dropDatabase', $name];

                return true;
            }
        };
        $db = new class {
            public function getPlatform(): string
            {
                return 'MySQLi';
            }
        };
        $migration = $this->makeMigration($db, $forge);

        $migration->createSchema('app_schema');
        $migration->dropSchema('app_schema');

        $this->assertSame([
            ['createDatabase', 'app_schema'],
            ['dropDatabase', 'app_schema'],
        ], $forge->calls);
    }

    public function testThrowsWhenMysqlForgeOperationFails(): void
    {
        $forge = new class {
            public function createDatabase(string $name): bool
            {
                return false;
            }

            public function dropDatabase(string $name): bool
            {
                return false;
            }
        };
        $db = new class {
            public function getPlatform(): string
            {
                return 'MySQLi';
            }
        };
        $migration = $this->makeMigration($db, $forge);

        try {
            $migration->createSchema('app_schema');
            $this->fail('Expected createSchema() to throw when Forge fails.');
        } catch (DatabaseException) {
        }

        $this->expectException(DatabaseException::class);
        $migration->dropSchema('app_schema');
    }

    public function testThrowsWhenSchemaQueryFails(): void
    {
        $db = new class {
            public function getPlatform(): string
            {
                return 'Postgre';
            }

            public function query(string $query): bool
            {
                return false;
            }
        };
        $migration = $this->makeMigration($db, new stdClass());
        $failures = 0;

        foreach (['createSchema', 'dropSchema'] as $method) {
            try {
                $migration->{$method}('app_schema');
            } catch (DatabaseException) {
                $failures++;
            }
        }

        $this->assertSame(2, $failures);
    }

    public function testRejectsEmptyNamesAndUnsupportedPlatforms(): void
    {
        $migration = $this->makeMigration(new class {
            public function getPlatform(): string
            {
                return 'SQLite3';
            }
        }, new stdClass());

        try {
            $migration->createSchema('');
            $this->fail('Expected createSchema() to reject an empty name.');
        } catch (InvalidArgumentException) {
        }

        try {
            $migration->dropSchema('');
            $this->fail('Expected dropSchema() to reject an empty name.');
        } catch (InvalidArgumentException) {
        }

        try {
            $migration->createSchema('app_schema');
            $this->fail('Expected createSchema() to reject an unsupported platform.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Creating schemas is not supported', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dropping schemas is not supported');
        $migration->dropSchema('app_schema');
    }

    private function makeMigration(object $db, object $forge): BaseMigration
    {
        $migration = (new ReflectionClass(BaseMigration::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass(CiMigration::class);
        $reflection->getProperty('db')->setValue($migration, $db);
        $reflection->getProperty('forge')->setValue($migration, $forge);

        return $migration;
    }
}
