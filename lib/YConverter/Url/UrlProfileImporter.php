<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Url;

use YConverter\Config;
use YConverter\Message;

/**
 * Reads the staged seo42 url_control_generate profiles, turns them into draft url-addon
 * profiles (via ProfileMigrator), and — on apply — writes rex_url_generator_profile rows and
 * rebuilds the url addon's profile cache + generated URLs using the addon's own APIs.
 */
class UrlProfileImporter
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

    public function isAvailable()
    {
        return \rex_addon::get('url')->isAvailable()
            && class_exists('Url\\Profile')
            && class_exists('Url\\Database')
            && class_exists('Url\\Cache')
            && class_exists('Url\\UrlManagerSql');
    }

    /**
     * @return array<int,array<string,mixed>> staged url_control_generate rows
     */
    public function detectProfiles()
    {
        $staging = $this->config->getConverterTable('url_control_generate');
        if (!$this->tableExists($staging)) {
            return [];
        }
        return $this->sql->getArray('SELECT * FROM ' . $this->sql->escapeIdentifier($staging) . ' ORDER BY id');
    }

    public function managerRowCount()
    {
        $staging = $this->config->getConverterTable('url_control_manager');
        if (!$this->tableExists($staging)) {
            return 0;
        }
        $rows = $this->sql->getArray('SELECT COUNT(*) AS c FROM ' . $this->sql->escapeIdentifier($staging));
        return isset($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
    }

    /**
     * Resolve an old source table to the R5 table its data now lives in.
     */
    public function resolveTable($oldTable)
    {
        $prefix = $this->config->getOutdatedTablePrefix();
        $base = (0 === strpos($oldTable, $prefix)) ? substr($oldTable, strlen($prefix)) : $oldTable;

        $yf = \rex::getTable('yf_' . $base);
        if ($this->tableExists($yf)) {
            return $yf;
        }
        $direct = \rex::getTable($base);
        if ($this->tableExists($direct)) {
            return $direct;
        }
        return null;
    }

    /**
     * @return UrlProfileMapping[]
     */
    public function analyze()
    {
        $clangIds = array_map('intval', array_keys(\rex_clang::getAll()));
        $mappings = [];
        foreach ($this->detectProfiles() as $row) {
            $resolved = $this->resolveTable((string) $row['table']);
            $mappings[] = ProfileMigrator::migrate($row, $clangIds, $resolved);
        }
        return $mappings;
    }

    /**
     * Write confirmed profiles and rebuild the url addon's profiles + URLs.
     *
     * @param UrlProfileMapping[] $mappings
     */
    public function apply(array $mappings)
    {
        if (!$this->isAvailable()) {
            $this->message->addError('Das URL-Addon ist nicht installiert/aktiviert — es können keine Profile angelegt werden.');
            return;
        }

        $profileTable = \rex::getTable('url_generator_profile');

        // Clean slate for our own migrated profiles before re-inserting the confirmed set, so
        // re-running is idempotent even when the operator edited namespace/article/table/clang
        // (the unique key is namespace+article_id+clang_id). Operator-made profiles (other
        // createuser) are never touched.
        $purge = \rex_sql::factory();
        $purge->setQuery('DELETE FROM ' . $purge->escapeIdentifier($profileTable) . ' WHERE createuser = :u', ['u' => 'yconverter']);

        $written = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($mappings as $mapping) {
            if ($mapping->remove || '' === $mapping->tableName) {
                continue;
            }
            $insert = \rex_sql::factory();
            $insert->setTable($profileTable);
            $insert->setValue('namespace', $mapping->namespace);
            $insert->setValue('article_id', $mapping->articleId);
            $insert->setValue('clang_id', $mapping->clangId);
            $insert->setValue('ep_pre_save_called', 0);
            $insert->setValue('table_name', \Url\Database::merge($mapping->dbId, $mapping->tableName));
            $insert->setValue('table_parameters', (string) json_encode($mapping->tableParameters));
            foreach (['relation_1_table_name', 'relation_2_table_name', 'relation_3_table_name'] as $col) {
                $insert->setValue($col, '');
            }
            foreach (['relation_1_table_parameters', 'relation_2_table_parameters', 'relation_3_table_parameters'] as $col) {
                $insert->setValue($col, '');
            }
            $insert->setValue('createdate', '' !== $mapping->createdate ? $mapping->createdate : $now);
            $insert->setValue('updatedate', '' !== $mapping->updatedate ? $mapping->updatedate : $now);
            $insert->setValue('createuser', 'yconverter');
            $insert->setValue('updateuser', 'yconverter');
            try {
                $insert->insert();
                ++$written;
            } catch (\rex_sql_exception $e) {
                // Most likely a duplicate namespace+article+clang within the confirmed set.
                $this->message->addWarning(sprintf('Profil für <code>%s</code> (Namespace "%s", clang %d) konnte nicht angelegt werden: %s', rex_escape($mapping->tableName), rex_escape($mapping->namespace), $mapping->clangId, rex_escape($e->getMessage())));
            }
        }

        // Rebuild profile cache + regenerate URLs (the recipe used by the url addon's own page).
        \Url\Cache::deleteProfiles();
        \Url\UrlManagerSql::deleteAll();
        foreach (\Url\Profile::getAll() as $profile) {
            $profile->buildUrls();
        }

        $urlCount = $this->sql->getArray('SELECT COUNT(*) AS c FROM ' . $this->sql->escapeIdentifier(\rex::getTable('url_generator_url')));
        $urls = isset($urlCount[0]['c']) ? (int) $urlCount[0]['c'] : 0;

        $this->message->addSuccess(sprintf('%d URL-Profil(e) angelegt; %d URL(s) generiert.', $written, $urls));
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
