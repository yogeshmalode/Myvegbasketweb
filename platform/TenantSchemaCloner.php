<?php

declare(strict_types=1);

namespace MyVegBasket\Platform;

use PDO;
use RuntimeException;

final class TenantSchemaCloner
{
    public static function cloneStructure(PDO $source, PDO $target): int
    {
        $sourceDriver = $source->getAttribute(PDO::ATTR_DRIVER_NAME);
        $targetDriver = $target->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($sourceDriver !== $targetDriver || !in_array($sourceDriver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Source and tenant databases must use the same supported database driver.');
        }

        if ($sourceDriver === 'sqlite') {
            return self::cloneSqliteStructure($source, $target);
        }

        return self::cloneMysqlStructure($source, $target);
    }

    private static function cloneMysqlStructure(PDO $source, PDO $target): int
    {
        $sourceDatabase = (string)$source->query('SELECT DATABASE()')->fetchColumn();
        $targetDatabase = (string)$target->query('SELECT DATABASE()')->fetchColumn();
        if ($sourceDatabase === '' || $targetDatabase === '' || $sourceDatabase === $targetDatabase) {
            throw new RuntimeException('The tenant schema target must be a separate database.');
        }

        $sourceObjects = $source->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $tables = [];
        foreach ($sourceObjects as $object) {
            if (($object[1] ?? '') !== 'BASE TABLE') {
                throw new RuntimeException('Automatic schema provisioning does not copy views; migrate them explicitly.');
            }
            $table = (string)$object[0];
            if (!str_starts_with($table, 'platform_')) {
                $tables[] = $table;
            }
        }
        if (!$tables) {
            throw new RuntimeException('The source database has no application tables to copy.');
        }

        $specialObjects = (int)$source->query(
            "SELECT
                (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()) +
                (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE())"
        )->fetchColumn();
        if ($specialObjects > 0) {
            throw new RuntimeException('The source database has triggers or routines; automatic schema copy is unsafe.');
        }

        $targetObjects = $target->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        if ($targetObjects) {
            throw new RuntimeException('The new tenant database is not empty; refusing to overwrite its schema.');
        }

        $target->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $table)) {
                    throw new RuntimeException('The source schema contains an unsupported table name.');
                }
                $quotedTable = '`' . $table . '`';
                $statement = $source->query('SHOW CREATE TABLE ' . $quotedTable);
                $createTable = $statement->fetch(PDO::FETCH_NUM);
                if (!is_array($createTable) || !isset($createTable[1])) {
                    throw new RuntimeException('Could not read a source table definition.');
                }
                $createSql = preg_replace('/\sAUTO_INCREMENT=\d+/i', '', (string)$createTable[1]);
                if (!is_string($createSql) || $createSql === '') {
                    throw new RuntimeException('Could not prepare a source table definition.');
                }
                $target->exec($createSql);
            }
        } finally {
            $target->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        return count($tables);
    }

    private static function cloneSqliteStructure(PDO $source, PDO $target): int
    {
        if ($source === $target) {
            throw new RuntimeException('The tenant schema target must be a separate database.');
        }

        $sourceObjects = $source->query(
            "SELECT type, name, sql FROM sqlite_master
             WHERE type IN ('table', 'view', 'trigger')
               AND name NOT LIKE 'sqlite_%'
               AND name NOT LIKE 'platform_%'
             ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name"
        )->fetchAll(PDO::FETCH_ASSOC);
        $targetObjects = $target->query(
            "SELECT COUNT(*) FROM sqlite_master
             WHERE type IN ('table', 'view', 'trigger') AND name NOT LIKE 'sqlite_%'"
        )->fetchColumn();
        if ((int)$targetObjects !== 0) {
            throw new RuntimeException('The new tenant database is not empty; refusing to overwrite its schema.');
        }

        foreach ($sourceObjects as $object) {
            if ($object['type'] !== 'table') {
                throw new RuntimeException('Automatic schema provisioning does not copy views or triggers.');
            }
            $target->exec((string)$object['sql']);
        }

        return count($sourceObjects);
    }
}
