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

use YConverter\Schema\FieldMapping;
use YConverter\Schema\LangDataMerger;
use YConverter\Schema\SchemaDetector;
use YConverter\Schema\ValueSampler;
use YConverter\Schema\Ai\AiProviderFactory;

/**
 * Generic, reusable converter for project-specific (custom) source tables into
 * YForm-managed tables.
 *
 * It auto-detects cloned staging tables that belong neither to the REDAXO core nor to a
 * well-known addon (see AddonMap::isKnownTable()), so the operator can pick which of them
 * to turn into YForm tables. Each selected table becomes `rex_yf_<name>`; YForm field
 * definitions are derived via SchemaDetector from column types, names, and sampled values.
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
     * Every table already registered in rex_yform_table (candidates for re-detection).
     *
     * @return array<int,array{table_name:string,name:string}>
     */
    public function detectExistingYFormTables()
    {
        $yfTable = \rex::getTable('yform_table');
        if (!$this->tableExists($yfTable)) {
            return [];
        }
        return $this->sql->getArray('SELECT table_name, name FROM ' . $this->sql->escapeIdentifier($yfTable) . ' ORDER BY name');
    }

    /**
     * Run schema detection for a single table without writing anything.
     *
     * @param string $sourceTable the table to read columns + sample values from
     *                            (staging yconverter_* for new imports, live rex_yf_* for re-detect)
     * @param string $yfTableName the live YForm table name (for existing-field lookup); '' for new
     *
     * @return FieldMapping[]
     */
    public function analyze($sourceTable, $yfTableName = '')
    {
        $columns = \rex_sql::showColumns($sourceTable);
        $sampler = (new ValueSampler($sourceTable))->asCallable();

        $clangIds = array_map('intval', array_keys(\rex_clang::getAll()));
        $langAvailable = LangDataMerger::langFieldsAvailable();

        $existingFields = [];
        if ('' !== $yfTableName) {
            $existingFields = $this->existingFieldTypes($yfTableName);
        }

        $detector = new SchemaDetector(AiProviderFactory::fromConfig($this->config));

        return $detector->detect($columns, $sampler, $clangIds, $langAvailable, $existingFields);
    }

    /**
     * @return array<string,string> column/field name => type_name for currently-registered value fields
     */
    private function existingFieldTypes($yfTableName)
    {
        $yfField = \rex::getTable('yform_field');
        if (!$this->tableExists($yfField)) {
            return [];
        }
        $rows = $this->sql->getArray(
            'SELECT name, type_name FROM ' . $this->sql->escapeIdentifier($yfField) . ' WHERE table_name = :t AND type_id = :v',
            ['t' => $yfTableName, 'v' => 'value']
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['name']] = $row['type_name'];
        }
        return $out;
    }

    /**
     * Import a freshly cloned custom table as a new YForm table.
     *
     * @param string         $base     staging base name (without prefix)
     * @param FieldMapping[] $mappings confirmed mappings (from analyze() or the preview)
     */
    public function import($base, array $mappings)
    {
        $staging = $this->config->getConverterTable($base);
        $target = \rex::getTable('yf_' . $base);

        if (!$this->tableExists($staging)) {
            $this->message->addError(sprintf('Die geklonte Tabelle <code>%s</code> existiert nicht. Bitte zuerst den Klon-Schritt ausführen.', rex_escape($staging)));
            return;
        }

        $this->sql->setQuery('DROP TABLE IF EXISTS ' . $this->sql->escapeIdentifier($target));
        $this->sql->setQuery('CREATE TABLE ' . $this->sql->escapeIdentifier($target) . ' LIKE ' . $this->sql->escapeIdentifier($staging));
        $this->sql->setQuery('INSERT INTO ' . $this->sql->escapeIdentifier($target) . ' SELECT * FROM ' . $this->sql->escapeIdentifier($staging));
        \rex_sql_table::get($target)->ensurePrimaryIdColumn()->alter();

        $this->applyMappings($target, $mappings);
        $this->registerYFormTable($target, $base);

        $rows = $this->sql->getArray('SELECT COUNT(*) AS cnt FROM ' . $this->sql->escapeIdentifier($target));
        $count = isset($rows[0]['cnt']) ? $rows[0]['cnt'] : 0;

        $this->message->addSuccess(sprintf(
            'Tabelle <code>%s</code> wurde als YForm-Tabelle <code>%s</code> mit %d Datensatz/Datensätzen angelegt.',
            rex_escape($base),
            rex_escape($target),
            $count
        ));
    }

    /**
     * Re-detect an already-imported YForm table: refresh its field definitions (and run the
     * i18n transform) WITHOUT recreating the data table or the rex_yform_table row.
     *
     * @param FieldMapping[] $mappings
     */
    public function refreshFields($yfTableName, array $mappings)
    {
        if (!$this->tableExists($yfTableName)) {
            $this->message->addError(sprintf('Die Tabelle <code>%s</code> existiert nicht.', rex_escape($yfTableName)));
            return;
        }

        $this->applyMappings($yfTableName, $mappings);

        $this->message->addSuccess(sprintf(
            'Felddefinitionen für <code>%s</code> wurden aktualisiert.',
            rex_escape($yfTableName)
        ));
    }

    /**
     * Run the i18n data transforms, then (re)write the yform_field rows from the mappings.
     *
     * @param FieldMapping[] $mappings
     */
    private function applyMappings($tableName, array $mappings)
    {
        $merger = new LangDataMerger();
        foreach ($mappings as $mapping) {
            if (!empty($mapping->members['map'])) {
                foreach ($merger->merge($tableName, $mapping) as $note) {
                    $this->message->addWarning($note);
                }
            }
        }

        $this->registerYFormFields($tableName, $mappings);
    }

    /**
     * @param FieldMapping[] $mappings
     */
    private function registerYFormFields($tableName, array $mappings)
    {
        $yfField = \rex::getTable('yform_field');
        if (!$this->tableExists($yfField)) {
            return;
        }

        $delete = \rex_sql::factory();
        $delete->setQuery('DELETE FROM ' . $delete->escapeIdentifier($yfField) . ' WHERE table_name = :t', ['t' => $tableName]);

        $now = date('Y-m-d H:i:s');
        $prio = 1;
        foreach ($mappings as $mapping) {
            $values = [
                'table_name' => $tableName,
                'prio' => $prio++,
                'type_id' => $mapping->typeId,
                'type_name' => $mapping->typeName,
                'db_type' => $mapping->dbType,
                'list_hidden' => 0,
                'search' => 1,
                'name' => $mapping->name,
                'label' => $mapping->label,
                'createdate' => $now,
                'updatedate' => $now,
                'createuser' => 'yconverter',
                'updateuser' => 'yconverter',
            ];
            // Merge type-specific params (e.g. choices, multiple), but never let a rule or
            // AI proposal overwrite the fixed columns above.
            foreach ($mapping->params as $paramName => $paramValue) {
                if (array_key_exists($paramName, $values)) {
                    continue;
                }
                $values[$paramName] = $paramValue;
            }
            $this->insertRow($yfField, $values);
        }
    }

    /**
     * Convenience non-interactive path: detect + import each base name (auto-apply).
     *
     * @param string[] $baseNames
     */
    public function convert(array $baseNames)
    {
        foreach ($baseNames as $base) {
            $base = trim((string) $base);
            if ('' === $base || AddonMap::isKnownTable($base)) {
                continue;
            }
            try {
                $mappings = $this->analyze($this->config->getConverterTable($base), '');
                $this->import($base, $mappings);
            } catch (\rex_sql_exception $e) {
                $this->message->addError(sprintf('Tabelle <code>%s</code> konnte nicht konvertiert werden: %s', rex_escape($base), rex_escape($e->getMessage())));
            }
        }
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
