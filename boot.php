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
$addon = rex_addon::get('yconverter');

$configFile = $addon->getDataPath('config.yml');
$config = rex_file::getConfig($configFile);

// Register the REDAXO 4 source database as connection 2 (see config.yml -> db.2),
// both for the backend converter pages and for the CLI command.
// REDAXO predefines connection 2 with empty values, so merge per-connection with our
// values taking precedence (a plain `+` would keep REDAXO's empty placeholder).
if (isset($config['db']) && (rex::isBackend() || 'cli' === PHP_SAPI)) {
    $dbconfig = rex::getProperty('db');
    foreach ($config['db'] as $id => $connection) {
        $existing = isset($dbconfig[$id]) && is_array($dbconfig[$id]) ? $dbconfig[$id] : [];
        $dbconfig[$id] = array_merge($existing, $connection);
    }
    rex::setProperty('db', $dbconfig);
}
