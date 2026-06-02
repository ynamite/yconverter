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

use YConverter\AddonMap;
use YConverter\Cloner;
use YConverter\Config;
use YConverter\InstallChecklist;
use YConverter\MediaCopier;
use YConverter\Message;
use YConverter\Package\Core;
use YConverter\Report;
use YConverter\YConverter;

/**
 * Backend AJAX endpoint that drives the clone / migrate / media steps as a sequence of
 * small "units", so the browser can show a progress bar and the work never blocks the UI
 * or times out.
 *
 * action=plan&what=clone|migrate|media  -> {units: [unit, ...], total}
 * each unit (POSTed back as JSON) carries its own `action` and is executed individually,
 * returning {ok, html?, downloaded?, skipped?, failed?, error?}.
 */
class rex_api_yconverter_run extends rex_api_function
{
    protected $published = false;

    private const MEDIA_CHUNK = 25;
    private const MIGRATE_STEPS = ['update' => 'Struktur', 'modify' => 'Inhalte', 'compare' => 'Vergleich', 'transfer' => 'Übertragen'];
    private const PACKAGE_LABELS = ['core' => 'Core', 'cronjob' => 'Cronjob', 'sprog' => 'Sprog', 'yform' => 'YForm'];

    public function execute()
    {
        rex_response::cleanOutputBuffers();

        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
        $action = isset($input['action']) ? (string) $input['action'] : rex_request::get('action', 'string', '');

        // Download the migration report (no config required, streams a file).
        if ('report' === $action) {
            rex_response::sendFile(Report::path(), 'text/markdown; charset=utf-8', 'attachment', 'yconverter-migration-report.md');
            exit;
        }

        $config = new Config();
        if (!$config->isValid()) {
            $this->fail('YConverter ist nicht vollständig konfiguriert.');
        }

        try {
            switch ($action) {
                case 'plan':
                    $this->send($this->plan($config, rex_request::get('what', 'string', '')) + ['ok' => true]);
                    break;
                case 'clone_start':
                    (new Cloner($config, new Message()))->dropTables();
                    $this->send(['ok' => true]);
                    break;
                case 'clone_table':
                    $table = (string) ($input['table'] ?? '');
                    try {
                        (new Cloner($config, new Message()))->cloneTable($table);
                        $this->send(['ok' => true]);
                    } catch (rex_sql_exception $e) {
                        // Don't abort the whole clone for one bad table — warn and continue.
                        $this->send(['ok' => true, 'html' => rex_view::warning('Tabelle <code>'.rex_escape($table).'</code> konnte nicht geklont werden: '.rex_escape($e->getMessage()))]);
                    }
                    break;
                case 'clone_finish':
                    $message = new Message();
                    (new InstallChecklist($config, $message))->run();
                    rex_config::set('yconverter', 'yconverter', ['clone']);
                    $this->send(['ok' => true, 'html' => $message->getAll()]);
                    break;
                case 'migrate_start':
                    Report::reset();
                    $this->send(['ok' => true]);
                    break;
                case 'migrate_step':
                    $converter = $this->runMigrateStep($input);
                    $message = $converter->getMessage();
                    Report::appendFromMessage($message, (string) ($input['label'] ?? (($input['package'] ?? '').' '.($input['step'] ?? ''))));
                    $this->send([
                        'ok' => true,
                        'html' => $converter->getMessages(),
                        'warnings' => $message->countTypes(['warning', 'error']),
                    ]);
                    break;
                case 'media_download':
                    $files = isset($input['files']) && is_array($input['files']) ? $input['files'] : [];
                    $result = (new MediaCopier($config, new Message()))->downloadFiles($config->getMediaSourceUrl(), $files);
                    $this->send(['ok' => true] + $result);
                    break;
                case 'media_finish':
                    if (!count((new MediaCopier($config, new Message()))->getMissingFiles())) {
                        rex_config::set('yconverter', 'media', ['media']);
                    }
                    $this->send(['ok' => true]);
                    break;
                default:
                    $this->fail('Unbekannte Aktion.');
            }
        } catch (rex_sql_exception $e) {
            $this->send(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function plan(Config $config, $what)
    {
        $units = [];
        $extra = [];

        if ('clone' === $what) {
            $units[] = ['action' => 'clone_start', 'label' => 'Vorbereiten'];
            foreach ((new Cloner($config, new Message()))->getSourceTables() as $table) {
                $units[] = ['action' => 'clone_table', 'table' => $table, 'label' => $table];
            }
            $units[] = ['action' => 'clone_finish', 'label' => 'Abschluss'];
        } elseif ('migrate' === $what) {
            $units[] = ['action' => 'migrate_start', 'label' => 'Vorbereiten'];
            foreach ($this->activePackages($config) as $name => $class) {
                $packageLabel = self::PACKAGE_LABELS[$name] ?? ucfirst($name);
                foreach (self::MIGRATE_STEPS as $step => $stepLabel) {
                    $units[] = ['action' => 'migrate_step', 'package' => $name, 'step' => $step, 'label' => $packageLabel.': '.$stepLabel];
                }
            }
            $extra['reportPath'] = Report::relativePath();
        } elseif ('media' === $what) {
            $missing = (new MediaCopier($config, new Message()))->getMissingFiles();
            foreach (array_chunk($missing, self::MEDIA_CHUNK) as $i => $chunk) {
                $units[] = ['action' => 'media_download', 'files' => $chunk, 'label' => count($chunk).' Dateien'];
            }
            $units[] = ['action' => 'media_finish', 'label' => 'Abschluss'];
        }

        return ['units' => $units] + $extra;
    }

    private function runMigrateStep(array $input)
    {
        $name = (string) ($input['package'] ?? '');
        $step = (string) ($input['step'] ?? '');

        $routing = AddonMap::packageRouting();
        $class = $routing[$name] ?? Core::class;
        $converter = new YConverter(new $class());

        switch ($step) {
            case 'update':
                $converter->updateTables();
                break;
            case 'modify':
                $converter->modifyTables();
                break;
            case 'compare':
                $converter->compareTables();
                break;
            case 'transfer':
                $converter->transferData();
                break;
        }

        return $converter;
    }

    /**
     * @return array<string, class-string>
     */
    private function activePackages(Config $config)
    {
        $active = [];
        foreach (AddonMap::packageRouting() as $name => $class) {
            $sources = (new $class())->getSourceTables();
            if (!$sources) {
                $active[$name] = $class;
                continue;
            }
            foreach ($sources as $table) {
                try {
                    if (count(rex_sql::showColumns($config->getConverterTable($table))) > 0) {
                        $active[$name] = $class;
                        break;
                    }
                } catch (rex_sql_exception $e) {
                    // staging table missing -> keep looking
                }
            }
        }

        return $active;
    }

    private function send(array $data)
    {
        rex_response::sendJson($data);
        exit;
    }

    private function fail($message)
    {
        rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
        rex_response::sendJson(['ok' => false, 'error' => $message]);
        exit;
    }
}
