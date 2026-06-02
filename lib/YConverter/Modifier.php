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

class Modifier
{
    private $config;
    private $message;
    private $sql;

    private $outdatedCode;
    private $replaces;
    private $tables;
    private $package;

    public function __construct(Config $config, Message $message, Package $package)
    {
        $this->sql = \rex_sql::factory();
        $this->sql->setDebug(false);

        $this->config = $config;
        $this->message = $message;

        $this->replaces = AddonMap::replaces($this->config);
        $this->outdatedCode = AddonMap::outdatedCode();
        $this->package = $package;
        $this->tables = $package->getTables();
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function updateTables()
    {
        foreach ($this->tables as $table => $versions) {
            foreach ($versions as $version => $params) {
                if (!$this->isTableForVersion($version)) {
                    continue;
                }

                $r5Table = $this->config->getConverterTable($table);
                $r5TableEscaped = $this->sql->escapeIdentifier($r5Table);

                if (isset($params['convertColumns'])) {
                    foreach ($params['convertColumns'] as $column => $convertType) {
                        $columnEscaped = $this->sql->escapeIdentifier($column);
                        switch ($convertType) {
                            case 'replace':
                                $items = $this->sql->getArray('SELECT `id`, '.$columnEscaped.' FROM '.$r5TableEscaped.' WHERE '.$columnEscaped.' != ""');
                                if (count($items)) {
                                    foreach ($items as $item) {
                                        $this->sql->setQuery('UPDATE '.$r5TableEscaped.' SET '.$columnEscaped.' = :replacedContent WHERE `id` = :id', ['id' => $item['id'], 'replacedContent' => $this->replaceContent($item[$column])]);
                                    }
                                }
                                $this->message->addSuccess(sprintf('Die Daten des Feldes <code>%s</code> der Tabelle <code>%s</code> wurden konvertiert', $column, $r5Table));
                                $this->checkOutdatedCode($r5Table, $column);
                                break;
                            case 'serialize':
                                $items = $this->sql->getArray('SELECT `id`, '.$columnEscaped.' FROM '.$r5TableEscaped.' WHERE '.$columnEscaped.' != ""');
                                if (count($items)) {
                                    foreach ($items as $item) {
                                        $this->sql->setQuery('UPDATE '.$r5TableEscaped.' SET '.$columnEscaped.' = \''.addslashes(json_encode(unserialize($item[$column]))).'\' WHERE `id` = "'.$item['id'].'"');
                                    }
                                }
                                $this->message->addSuccess(sprintf('Die serialisierten Daten des Feldes <code>%s</code> der Tabelle <code>%s</code> wurden konvertiert', $column, $r5Table));
                                break;
                            case 'timestamp':
                                $this->sql->setQuery('ALTER TABLE '.$r5TableEscaped.' CHANGE COLUMN '.$columnEscaped.' '.$columnEscaped.' varchar(20)');
                                $this->sql->setQuery('UPDATE '.$r5TableEscaped.' SET '.$columnEscaped.' = IF('.$columnEscaped.' > 0, FROM_UNIXTIME('.$columnEscaped.', "%Y-%m-%d %H:%i:%s"), NOW())');
                                $this->sql->setQuery('ALTER TABLE '.$r5TableEscaped.' CHANGE COLUMN '.$columnEscaped.' '.$columnEscaped.' datetime');
                                $this->message->addSuccess(sprintf('Die Timestamps des Feldes <code>%s</code> der Tabelle <code>%s</code> wurden konvertiert', $column, $r5Table));
                                break;
                        }
                    }
                }
            }
        }
    }

    public function callCallbacks()
    {
        // Callbacks erst nach den Anpassungen durchgehen
        $callbacks = [];
        foreach ($this->tables as $table => $versions) {
            foreach ($versions as $fromVersion => $params) {
                if (\rex_string::versionCompare($this->config->getOutdatedCoreVersion(), $fromVersion, '<')) {
                    continue;
                }
                $r5Table = $this->config->getConverterTable($table);

                if (isset($params['callbacks'])) {
                    foreach ($params['callbacks'] as $callback) {
                        $level = isset($callback['level']) ? $callback['level'] : YConverter::NORMAL;
                        $params['table'] = $table;
                        $params['r5Table'] = $r5Table;
                        $callbacks[$level][] = ['function' => $callback['function'], 'params' => $params];
                    }
                }
            }
        }

        foreach ([YConverter::EARLY, YConverter::NORMAL, YConverter::LATE] as $level) {
            if (isset($callbacks[$level]) && is_array($callbacks[$level])) {
                foreach ($callbacks[$level] as $callback) {
                    $this->package->{$callback['function']}($callback['params']);
                    //\call_user_func([$this, $callback['function']], $callback['params']);
                    $this->message->addSuccess(('Callback '.$callback['function'].' für '.$callback['params']['r5Table'].' aufgerufen'));
                }
            }
        }
    }

    private function isTableForVersion($tableVersion)
    {
        $coomparator = '>';
        $length = strcspn($tableVersion, '0123456789.');
        if ($length > 0) {
            $coomparator = substr($tableVersion, 0, $length);
            $tableVersion = substr($tableVersion, $length);
        }
        if (!\rex_string::versionCompare($this->config->getOutdatedCoreVersion(), $tableVersion, $coomparator)) {
            return false;
        }
        return true;
    }

    private function checkOutdatedCode($table, $column)
    {
        $items = $this->sql->getArray('SELECT `id`, `'.$column.'` FROM `'.$table.'` WHERE `'.$column.'` != ""');
        if (\count($items)) {
            foreach ($items as $item) {
                foreach ($this->outdatedCode as $m) {
                    $search = '';
                    if (isset($m['regex'])) {
                        $search = $m['regex'];
                    }

                    foreach ($m['matches'] as $match) {
                        $expr = $match;
                        if ($search != '') {
                            $expr = str_replace('$$SEARCH$$', $match, $search);
                        }
                        if (preg_match('@'.$expr.'@i', $item[$column])) {
                            preg_match_all('@'.$expr.'@i', $item[$column], $matches);
                            $matches = array_count_values($matches[0]);
                            foreach ($matches as $match => $count) {
                                $this->message->addWarning('
                                    <code>'.$match.'</code> sollte angepasst bzw. nicht mehr verwendet werden.<br /><br />
                                    <dl class="dl-horizontal">
                                        <dt>Tabelle</dt>
                                        <dd><code>'.$table.'</code></dd>
                                        <dt>Id</dt>
                                        <dd><code>'.$item['id'].'</code></dd>
                                        <dt>Feld</dt>
                                        <dd><code>'.$column.'</code></dd>
                                        <dt>Vorkommen</dt>
                                        <dd><code>'.$count.'</code></dd>
                                    </dl>
                                ');
                            }
                        }
                    }
                }
            }
        }
    }

    private function replaceContent($content)
    {
        foreach ($this->replaces as $r) {
            $search = '';
            if (isset($r['regex'])) {
                $search = $r['regex'];
            }

            foreach ($r['replaces'] as $pair) {
                foreach ($pair as $expr => $replace) {
                    if ($search != '') {
                        $expr = str_replace('$$SEARCH$$', $expr, $search);
                    }
                    $content = preg_replace('@'.$expr.'@i', $replace, $content);
                }
            }
        }
        return $content;
    }
}
