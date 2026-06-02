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

use YConverter\Package\Package;

class YConverter
{
    public const EARLY = -1;
    public const NORMAL = 0;
    public const LATE = 1;

    private $config;
    private $sql;
    private $message;
    private $package;

    public function __construct(Package $package)
    {

        $this->sql = \rex_sql::factory();
        $this->sql->setDebug(false);

        $this->config = new Config();
        $this->message = new Message();
        $this->package = $package;
        $this->package->setConfig($this->config);
    }

    public function cloneTables()
    {
        $cloner = new Cloner($this->config, $this->message);
        $cloner->fetchTables();
        $this->message = $cloner->getMessage();

        $checklist = new InstallChecklist($this->config, $this->message);
        $checklist->run();
        $this->message = $checklist->getMessage();

        \rex_config::set('yconverter', 'yconverter', ['clone']);
    }

    public function getMessages()
    {
        return $this->message->getAll();
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function dropStagingTables()
    {
        $cloner = new Cloner($this->config, $this->message);
        $cloner->dropTables();
        $this->message = $cloner->getMessage();
    }

    /**
     * Whether the current package has cloned source tables to migrate. Packages whose
     * addon was never used in the source (no matching staging tables) return false so the
     * migration can skip them instead of failing on missing tables.
     */
    public function packageHasSource()
    {
        $sourceTables = $this->package->getSourceTables();
        if (!$sourceTables) {
            return true;
        }

        foreach ($sourceTables as $table) {
            try {
                if (\count(\rex_sql::showColumns($this->config->getConverterTable($table))) > 0) {
                    return true;
                }
            } catch (\rex_sql_exception $e) {
                // table does not exist -> keep looking
            }
        }

        return false;
    }

    public function updateTables()
    {
        $updater = new Updater($this->config, $this->message, $this->package);
        $updater->run();
        $this->message = $updater->getMessage();

        $array = \rex_config::get('yconverter', $this->package->getName(), []);
        $array[] = 'update';
        \rex_config::set('yconverter', $this->package->getName(), $array);
    }

    public function modifyTables()
    {
        $modifier = new Modifier($this->config, $this->message, $this->package);
        $modifier->updateTables();
        $modifier->callCallbacks();
        $this->message = $modifier->getMessage();

        $array = \rex_config::get('yconverter', $this->package->getName(), []);
        $array[] = 'modify';
        \rex_config::set('yconverter', $this->package->getName(), $array);
    }

    public function compareTables()
    {
        $compare = new Compare($this->config, $this->message, $this->package);
        $compare->run();
        $this->message = $compare->getMessage();

        // multiple executable
        // set in transferData
        //
        //$array = \rex_config::get('yconverter', 'core', []);
        //$array[] = 'missing';
        //\rex_config::set('yconverter', 'core', $array);
    }

    public function transferData()
    {
        $shuttle = new Shuttle($this->config, $this->message, $this->package);
        $shuttle->transfer();
        $this->message = $shuttle->getMessage();

        $array = \rex_config::get('yconverter', $this->package->getName(), []);
        $array[] = 'compare';
        $array[] = 'transfer';
        \rex_config::set('yconverter', $this->package->getName(), $array);
    }

    public function copyMedia()
    {
        $copier = new MediaCopier($this->config, $this->message);
        $copier->copy();
        $this->message = $copier->getMessage();

        \rex_config::set('yconverter', 'media', ['media']);
    }

    /**
     * @param string[] $baseNames cloned source base names to convert into YForm tables
     */
    public function convertCustomTables(array $baseNames)
    {
        $importer = new YFormImporter($this->config, $this->message);
        $importer->convert($baseNames);
        $this->message = $importer->getMessage();
    }
}
