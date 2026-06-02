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

namespace YConverter\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YConverter\AddonMap;
use YConverter\Cloner;
use YConverter\Config;
use YConverter\InstallChecklist;
use YConverter\MediaCopier;
use YConverter\Message;
use YConverter\Package\Core;
use YConverter\YConverter;
use YConverter\YFormImporter;

/**
 * Runs the complete REDAXO 4 -> 5 migration in one go, from the command line.
 *
 * Reusing the same pipeline as the backend, but without a web execution-time limit —
 * which matters above all for copying large media trees.
 *
 *   php redaxo/bin/console yconverter:run
 */
final class RunCommand extends \rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Führt die komplette REDAXO 4 → 5 Migration in einem Durchlauf aus.')
            ->addOption('package', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Nur diese Pakete migrieren (mehrfach möglich, Standard: alle)')
            ->addOption('skip-clone', null, InputOption::VALUE_NONE, 'Klon-Schritt überspringen')
            ->addOption('skip-media', null, InputOption::VALUE_NONE, 'Medien-Kopie überspringen')
            ->addOption('yform-tables', null, InputOption::VALUE_REQUIRED, 'Kommaseparierte Liste eigener Tabellen, die nach YForm konvertiert werden sollen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $io->title('YConverter');

        $config = new Config();
        if (!$config->isValid()) {
            $io->error(array_merge(['YConverter ist nicht vollständig konfiguriert:'], $config->getValidationErrors()));
            return 1;
        }

        $routing = AddonMap::packageRouting();
        $only = (array) $input->getOption('package');
        if ($only) {
            $routing = array_intersect_key($routing, array_flip($only));
            if (!$routing) {
                $io->error('Keines der angegebenen Pakete ist bekannt: '.implode(', ', $only));
                return 1;
            }
        }

        // 1. Clone (package-independent), table by table with a progress bar.
        if (!$input->getOption('skip-clone')) {
            $io->section('Klonen');
            $message = new Message();
            $cloner = new Cloner($config, $message);
            $cloner->dropTables();
            $tables = $cloner->getSourceTables();
            $failed = [];
            $io->progressStart(count($tables));
            foreach ($tables as $table) {
                try {
                    $cloner->cloneTable($table);
                } catch (\rex_sql_exception $e) {
                    $failed[] = $table.': '.$e->getMessage();
                }
                $io->progressAdvance();
            }
            $io->progressFinish();
            (new InstallChecklist($config, $message))->run();
            \rex_config::set('yconverter', 'yconverter', ['clone']);
            $this->renderMessages($io, $message->getAll());
            if ($failed) {
                $io->warning(array_merge(['Nicht geklont:'], $failed));
            }
        }

        // 2. Migrate every active package (update -> modify -> compare -> transfer) under a
        //    single progress bar. Core first so the language shift / media rows are in place.
        $active = [];
        foreach ($routing as $name => $class) {
            if ((new YConverter(new $class()))->packageHasSource()) {
                $active[$name] = $class;
            } else {
                $io->note($name.': keine Quelldaten vorhanden – übersprungen.');
            }
        }
        if ($active) {
            $io->section('Migrieren');
            $steps = ['update', 'modify', 'compare', 'transfer'];
            $io->progressStart(count($active) * count($steps));
            $migrateMessages = '';
            foreach ($active as $name => $class) {
                $converter = new YConverter(new $class());
                foreach ($steps as $step) {
                    switch ($step) {
                        case 'update': $converter->updateTables(); break;
                        case 'modify': $converter->modifyTables(); break;
                        case 'compare': $converter->compareTables(); break;
                        case 'transfer': $converter->transferData(); break;
                    }
                    $io->progressAdvance();
                }
                $migrateMessages .= $converter->getMessages();
            }
            $io->progressFinish();
            $this->renderMessages($io, $migrateMessages);
        }

        // 3. Custom tables -> YForm
        $yformTables = array_filter(array_map('trim', explode(',', (string) $input->getOption('yform-tables'))));
        if ($yformTables) {
            $io->section('Eigene Tabellen → YForm');
            $converter = new YConverter(new Core());
            $converter->convertCustomTables($yformTables);
            $this->renderMessages($io, $converter->getMessages());
        } else {
            $detected = (new YFormImporter($config, new Message()))->detectCustomTables();
            if ($detected) {
                $io->note('Erkannte eigene Tabellen (mit --yform-tables= konvertieren): '.implode(', ', $detected));
            }
        }

        // 4. Media last (no web timeout for large trees)
        if (!$input->getOption('skip-media')) {
            $io->section('Medien');
            $baseUrl = $config->getMediaSourceUrl();
            if ($baseUrl) {
                $copier = new MediaCopier($config, new Message());
                $missing = $copier->getMissingFiles();
                if (!$missing) {
                    $io->writeln('Alle Medien bereits vorhanden.');
                } else {
                    $dl = 0;
                    $sk = 0;
                    $failed = [];
                    $io->progressStart(count($missing));
                    foreach (array_chunk($missing, 50) as $chunk) {
                        $result = $copier->downloadFiles($baseUrl, $chunk);
                        $dl += $result['downloaded'];
                        $sk += $result['skipped'];
                        $failed = array_merge($failed, $result['failed']);
                        $io->progressAdvance(count($chunk));
                    }
                    $io->progressFinish();
                    if (!$copier->getMissingFiles()) {
                        \rex_config::set('yconverter', 'media', ['media']);
                    }
                    $io->writeln($dl.' geladen, '.$sk.' übersprungen, '.count($failed).' Fehler.');
                    if ($failed) {
                        $io->warning(array_slice($failed, 0, 20));
                    }
                }
            } else {
                $converter = new YConverter(new Core());
                $converter->copyMedia();
                $this->renderMessages($io, $converter->getMessages());
            }
        }

        $io->success('YConverter-Lauf abgeschlossen.');
        return 0;
    }

    /**
     * The Message collector returns backend HTML; flatten it to readable CLI lines.
     */
    private function renderMessages($io, $html)
    {
        if ('' === trim((string) $html)) {
            return;
        }

        $text = preg_replace('@<br\s*/?>@i', "\n", $html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        foreach (preg_split('/\n+/', $text) as $line) {
            $line = trim($line);
            if ('' !== $line) {
                $io->writeln('  '.$line);
            }
        }
    }
}
