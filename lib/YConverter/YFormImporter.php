<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 * @author Thomas Blum <thomas.blum@yakamara.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace YConverter;

/**
 * Generic, reusable converter for project-specific (custom) source tables into
 * YForm-managed tables.
 *
 * It auto-detects cloned staging tables that belong neither to the REDAXO core nor to a
 * well-known addon (see AddonMap::isKnownTable()), so the operator can pick which of them
 * to turn into YForm tables. Each selected table becomes `rex_yf_<name>`; YForm field
 * definitions are inferred purely from the MySQL column types — there is no
 * project-specific logic, so the operator refines field types/labels in YForm afterwards.
 */
class YFormImporter
{
    private $config;
    private $message;
    private $sql;

    public function __construct(Config $config, Message $message)
    {
        $this->sql = \rex_sql::factory();
        $this->sql->setDebug(false);

        $this->config = $config;
        $this->message = $message;
    }

    public function getMessage()
    {
        return $this->message;
    }

    /**
     * Cloned source tables (base name, without prefix) that are neither core nor a
     * well-known addon and can be offered for conversion to YForm.
     *
     * @return string[]
     */
    public function detectCustomTables()
    {
        $dbConfig = \rex::getProperty('db');
        if (!isset($dbConfig['1']['name'])) {
            return [];
        }

        $prefix = $this->config->getConverterTablePrefix();
        $rows = $this->sql->getArray('
            SELECT table_name FROM information_schema.tables
            WHERE table_schema = :schema AND table_name LIKE :prefix',
            ['schema' => $dbConfig['1']['name'], 'prefix' => $prefix.'%'],
            \PDO::FETCH_NUM
        );

        $custom = [];
        foreach ($rows as $row) {
            $base = substr($row[0], strlen($prefix));
            if ('' === $base || AddonMap::isKnownTable($base)) {
                continue;
            }
            $custom[] = $base;
        }
        sort($custom);

        return $custom;
    }

    /**
     * @param string[] $baseNames cloned source base names to convert
     */
    public function convert(array $baseNames)
    {
        foreach ($baseNames as $base) {
            $base = trim((string) $base);
            if ('' === $base || AddonMap::isKnownTable($base)) {
                continue;
            }
            try {
                $this->convertTable($base);
            } catch (\rex_sql_exception $e) {
                $this->message->addError(sprintf('Tabelle <code>%s</code> konnte nicht nach YForm konvertiert werden: %s', rex_escape($base), rex_escape($e->getMessage())));
            }
        }
    }

    private function convertTable($base)
    {
        $staging = $this->config->getConverterTable($base);
        $target = \rex::getTable('yf_'.$base);

        if (!$this->tableExists($staging)) {
            $this->message->addError(sprintf('Die geklonte Tabelle <code>%s</code> existiert nicht. Bitte zuerst den Klon-Schritt ausführen.', rex_escape($staging)));
            return;
        }

        // (Re)create the data table from the cloned data and ensure a primary id column,
        // which YForm requires. Re-runnable: the target is dropped first.
        $this->sql->setQuery('DROP TABLE IF EXISTS '.$this->sql->escapeIdentifier($target));
        $this->sql->setQuery('CREATE TABLE '.$this->sql->escapeIdentifier($target).' LIKE '.$this->sql->escapeIdentifier($staging));
        $this->sql->setQuery('INSERT INTO '.$this->sql->escapeIdentifier($target).' SELECT * FROM '.$this->sql->escapeIdentifier($staging));

        \rex_sql_table::get($target)->ensurePrimaryIdColumn()->alter();

        $columns = \rex_sql::showColumns($target);
        $this->registerYFormTable($target, $base);
        $this->registerYFormFields($target, $columns);

        $rows = $this->sql->getArray('SELECT COUNT(*) AS cnt FROM '.$this->sql->escapeIdentifier($target));
        $count = isset($rows[0]['cnt']) ? $rows[0]['cnt'] : 0;

        $this->message->addSuccess(sprintf(
            'Tabelle <code>%s</code> wurde als YForm-Tabelle <code>%s</code> mit %d Datensatz/Datensätzen angelegt. Die Feldtypen wurden aus den Spaltentypen abgeleitet und sollten in YForm geprüft werden.',
            rex_escape($base),
            rex_escape($target),
            $count
        ));
    }

    private function registerYFormTable($tableName, $base)
    {
        $yfTable = \rex::getTable('yform_table');
        if (!$this->tableExists($yfTable)) {
            $this->message->addError('Die Tabelle <code>rex_yform_table</code> existiert nicht. Ist das YForm-Addon installiert und aktiviert?');
            return;
        }

        $delete = \rex_sql::factory();
        $delete->setQuery('DELETE FROM '.$delete->escapeIdentifier($yfTable).' WHERE table_name = :t', ['t' => $tableName]);

        $now = date('Y-m-d H:i:s');
        $this->insertRow($yfTable, [
            'status' => 1,
            'table_name' => $tableName,
            'name' => $base,
            'description' => '',
            'list_amount' => 100,
            'list_sortfield' => 'id',
            'list_sortorder' => 'ASC',
            'prio' => $this->nextPrio($yfTable),
            'search' => 1,
            'hidden' => 0,
            'export' => 1,
            'import' => 1,
            'mass_deletion' => 1,
            'mass_edit' => 1,
            // The data table already has the correct structure; don't let YForm rebuild it.
            'schema_overwrite' => 0,
            'history' => 0,
            'createdate' => $now,
            'updatedate' => $now,
            'createuser' => 'yconverter',
            'updateuser' => 'yconverter',
        ]);
    }

    private function registerYFormFields($tableName, array $columns)
    {
        $yfField = \rex::getTable('yform_field');
        if (!$this->tableExists($yfField)) {
            return;
        }

        $delete = \rex_sql::factory();
        $delete->setQuery('DELETE FROM '.$delete->escapeIdentifier($yfField).' WHERE table_name = :t', ['t' => $tableName]);

        $now = date('Y-m-d H:i:s');
        $prio = 1;
        foreach ($columns as $column) {
            $name = $column['name'];
            if ('id' === $name) {
                // YForm manages the primary id itself.
                continue;
            }

            $this->insertRow($yfField, [
                'table_name' => $tableName,
                'prio' => $prio++,
                'type_id' => 'value',
                'type_name' => $this->mapType($column['type']),
                'db_type' => $column['type'],
                'list_hidden' => 0,
                'search' => 1,
                'name' => $name,
                'label' => $name,
                'createdate' => $now,
                'updatedate' => $now,
                'createuser' => 'yconverter',
                'updateuser' => 'yconverter',
            ]);
        }
    }

    /**
     * Maps a MySQL column type to a YForm value field type. Purely type-driven so it
     * generalises across projects; the operator refines specifics (multilang, media,
     * timestamps) in YForm afterwards.
     */
    private function mapType($mysqlType)
    {
        $type = strtolower($mysqlType);

        if (0 === strpos($type, 'tinyint(1)')) {
            return 'checkbox';
        }
        if (preg_match('/^(int|bigint|smallint|mediumint|tinyint)/', $type)) {
            return 'integer';
        }
        if (preg_match('/^(decimal|float|double)/', $type)) {
            return 'number';
        }
        if (0 === strpos($type, 'datetime') || 0 === strpos($type, 'timestamp')) {
            return 'datetime';
        }
        if (0 === strpos($type, 'date')) {
            return 'date';
        }
        if (0 === strpos($type, 'time')) {
            return 'time';
        }
        if (preg_match('/^(text|mediumtext|longtext|tinytext)/', $type)) {
            return 'textarea';
        }

        return 'text';
    }

    /**
     * Inserts a row, filling every existing column of the target table: known keys with
     * the given value, all other (e.g. YForm-version-specific NOT NULL) columns with an
     * empty default. The auto-increment id is left to the database.
     */
    private function insertRow($table, array $values)
    {
        $insert = \rex_sql::factory();
        $insert->setTable($table);

        foreach (\rex_sql::showColumns($table) as $column) {
            $name = $column['name'];
            if ('id' === $name) {
                continue;
            }
            $insert->setValue($name, array_key_exists($name, $values) ? $values[$name] : '');
        }

        $insert->insert();
    }

    private function nextPrio($table)
    {
        $sql = \rex_sql::factory();
        $rows = $sql->getArray('SELECT MAX(`prio`) AS m FROM '.$sql->escapeIdentifier($table));

        return isset($rows[0]['m']) ? ((int) $rows[0]['m'] + 1) : 1;
    }

    private function tableExists($table)
    {
        try {
            return \count(\rex_sql::showColumns($table)) > 0;
        } catch (\rex_sql_exception $e) {
            return false;
        }
    }
}
