<?php

namespace MedigitalDev\Ci4Base\Databases;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\Exceptions\DatabaseException;
use InvalidArgumentException;
use RuntimeException;

class BaseMigration extends Migration
{
    /**
     * Perform a migration step.
     *
     * @return void
     */
    public function up()
    {
        return parent::up();
    }

    /**
     * Revert a migration step.
     *
     * @return void
     */
    public function down()
    {
        return parent::down();
    }

    /**
     * Create a schema on platforms that support schemas separately from databases.
     * On MySQL, schemas are databases, so this uses Forge::createDatabase().
     */
    public function createSchema(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Schema name cannot be empty.');
        }

        $platform = $this->db->getPlatform();

        if ($platform === 'MySQLi') {
            if (! $this->forge->createDatabase($name)) {
                throw new DatabaseException('Unable to create the schema (database) "' . $name . '".');
            }

            return;
        }

        $quotedName = match ($platform) {
            'Postgre' => '"' . str_replace('"', '""', $name) . '"',
            'SQLSRV' => '[' . str_replace(']', ']]', $name) . ']',
            default => throw new RuntimeException(
                'Creating schemas is not supported for database platform "' . $platform . '".',
            ),
        };

        if (! $this->db->query('CREATE SCHEMA ' . $quotedName)) {
            throw new DatabaseException('Unable to create the schema "' . $name . '".');
        }
    }

    /**
     * Drop a schema on platforms that support schemas separately from databases.
     * On MySQL, schemas are databases, so this drops the entire database.
     */
    public function dropSchema(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Schema name cannot be empty.');
        }

        $platform = $this->db->getPlatform();

        if ($platform === 'MySQLi') {
            if (! $this->forge->dropDatabase($name)) {
                throw new DatabaseException('Unable to drop the schema (database) "' . $name . '".');
            }

            return;
        }

        $quotedName = match ($platform) {
            'Postgre' => '"' . str_replace('"', '""', $name) . '"',
            'SQLSRV' => '[' . str_replace(']', ']]', $name) . ']',
            default => throw new RuntimeException(
                'Dropping schemas is not supported for database platform "' . $platform . '".',
            ),
        };

        if (! $this->db->query('DROP SCHEMA ' . $quotedName)) {
            throw new DatabaseException('Unable to drop the schema "' . $name . '".');
        }
    }
}
