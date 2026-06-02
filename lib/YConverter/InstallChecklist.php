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
 * Advisory, best-effort check: looks at the cloned staging tables and reports which
 * REDAXO 5 addons the operator should install/activate to receive the data. It never
 * installs anything — there are too many addons (and they diverge too much) to automate.
 */
class InstallChecklist
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

    public function run()
    {
        $dbConfig = \rex::getProperty('db');
        if (!isset($dbConfig['1']['name'])) {
            return;
        }

        $prefix = $this->config->getConverterTablePrefix();
        $rows = $this->sql->getArray('
            SELECT table_name FROM information_schema.tables
            WHERE table_schema = :schema AND table_name LIKE :prefix',
            ['schema' => $dbConfig['1']['name'], 'prefix' => $prefix.'%'],
            \PDO::FETCH_NUM
        );

        $existing = [];
        foreach ($rows as $row) {
            $existing[$row[0]] = true;
        }

        $detected = [];
        foreach (AddonMap::r4ToR5Addons() as $baseTable => $r5Addon) {
            if (isset($existing[$prefix.$baseTable])) {
                $detected[$r5Addon] = $r5Addon;
            }
        }

        if (!\count($detected)) {
            return;
        }

        $lines = [];
        foreach ($detected as $r5Addon) {
            $available = \rex_addon::get($r5Addon)->isAvailable();
            $lines[] = sprintf('<code>%s</code> – %s', $r5Addon, $available ? 'vorhanden' : 'fehlt / nicht aktiviert');
        }

        $this->message->addInfo(
            'Hinweis (Best-Effort): Für die erkannten REDAXO-4-Addons sollten in REDAXO 5 die folgenden Addons '
            .'installiert und aktiviert sein, damit die übertragenen Daten genutzt werden können. '
            .'Es wird nichts automatisch installiert.<br /><br /><pre class="rex-code">'.implode('<br />', $lines).'</pre>'
        );
    }
}
