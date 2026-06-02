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

class Config
{
    private $config;

    public function __construct()
    {
        $configFile = \rex_addon::get('yconverter')->getDataPath('config.yml');
        $this->config = \rex_file::getConfig($configFile);
    }

    public function isValid()
    {
        return [] === $this->getValidationErrors();
    }

    /**
     * @return string[] human readable problems; empty when the config is usable
     */
    public function getValidationErrors()
    {
        $errors = [];

        if (empty($this->config['core_version'])) {
            $errors[] = 'Die REDAXO-4-Version (core_version) ist nicht gesetzt.';
        }
        if (empty($this->config['table_prefix'])) {
            $errors[] = 'Das Tabellen-Präfix (table_prefix) ist nicht gesetzt.';
        }

        $db = isset($this->config['db'][$this->getOutdatedDatabaseId()]) ? $this->config['db'][$this->getOutdatedDatabaseId()] : null;
        if (empty($db['host']) || empty($db['login']) || empty($db['name'])) {
            $errors[] = 'Die Verbindung zur Quell-Datenbank (REDAXO 4) ist unvollständig.';
        }

        return $errors;
    }


    public function getOutdatedCoreVersion()
    {
        return $this->config['core_version'];
    }

    public function getOutdatedTablePrefix()
    {
        return $this->config['table_prefix'];
    }

    public function getOutdatedTable($table)
    {
        return $this->getOutdatedTablePrefix().$table;
    }

    public function getConverterTablePrefix()
    {
        return 'yconverter_';
    }

    public function getConverterTable($table)
    {
        return $this->getConverterTablePrefix().$table;
    }

    public function getOutdatedDatabaseId()
    {
        return '2';
    }

    public function getMediaSourcePath()
    {
        return isset($this->config['media_source_path']) ? $this->config['media_source_path'] : null;
    }

    public function getMediaSourceUrl()
    {
        return isset($this->config['media_source_url']) ? $this->config['media_source_url'] : null;
    }

    public function getNewPhpValueField()
    {
        return '19';
    }

    public function getNewHtmlValueField()
    {
        return '20';
    }
}
