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
        $this->config = self::read();
    }

    /**
     * Absolute path to the addon's config.yml (under data/, gitignored).
     */
    public static function file()
    {
        return \rex_addon::get('yconverter')->getDataPath('config.yml');
    }

    /**
     * Default settings, the single source of truth for every config key.
     *
     * @return array
     */
    public static function defaults()
    {
        return [
            'db' => [
                '2' => [
                    'host' => null,
                    'login' => null,
                    'password' => null,
                    'name' => null,
                    'persistent' => null,
                ],
            ],
            'core_version' => null,
            'table_prefix' => 'rex_',
            'media_source_path' => null,
            'media_source_url' => null,
            'ai_provider' => 'none',
            'ai_api_key' => null,
            'ai_model' => null,
            'ai_send_samples' => true,
        ];
    }

    /**
     * The full, defaulted config as stored on disk.
     *
     * @return array
     */
    public static function read()
    {
        return array_merge(self::defaults(), (array) \rex_file::getConfig(self::file()));
    }

    /**
     * Persist the full config array. Settings pages read(), overlay only their own fields,
     * and write() — so saving one sub-page never clears another's settings.
     */
    public static function write(array $config)
    {
        return \rex_file::putConfig(self::file(), $config);
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

    public function getAiProvider()
    {
        return isset($this->config['ai_provider']) ? (string) $this->config['ai_provider'] : 'none';
    }

    public function getAiApiKey()
    {
        return isset($this->config['ai_api_key']) ? (string) $this->config['ai_api_key'] : '';
    }

    public function getAiModel()
    {
        return isset($this->config['ai_model']) ? (string) $this->config['ai_model'] : '';
    }

    public function getAiSendSamples()
    {
        // Default ON (better results); operator can disable to send schema only.
        return !isset($this->config['ai_send_samples']) || (bool) $this->config['ai_send_samples'];
    }
}
