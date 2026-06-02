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
 * Brings the REDAXO 4 media files into the REDAXO 5 media directory (`rex_path::media()`).
 *
 * Two modes:
 *  - URL (preferred): download each file listed in the migrated `rex_media` table over HTTP
 *    from the old site (`<url>/files/<filename>`). No SSH/credentials needed because the
 *    media is served publicly. Re-runnable: existing files are skipped.
 *  - Local path: copy from a locally reachable `files/` directory, excluding `files/addons/`.
 *
 * For large libraries prefer the CLI command (yconverter:run) — no web execution-time limit.
 */
class MediaCopier
{
    private $config;
    private $message;

    public function __construct(Config $config, Message $message)
    {
        $this->config = $config;
        $this->message = $message;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function copy()
    {
        $url = $this->config->getMediaSourceUrl();
        if (!empty($url)) {
            $this->copyFromUrl($url);
            return;
        }

        $source = $this->config->getMediaSourcePath();
        if (!empty($source)) {
            $this->copyFromPath($source);
            return;
        }

        $this->message->addError('Es ist weder eine Medien-Quell-URL noch ein lokaler Medien-Quellpfad konfiguriert (siehe Einstellungen).');
    }

    private function copyFromUrl($baseUrl)
    {
        $files = $this->getMediaList();
        if (!\count($files)) {
            $this->message->addWarning('In <code>rex_media</code> wurden keine Dateien gefunden. Bitte zuerst die Pakete migrieren (Schritt 2), damit die Medienliste vorhanden ist.');
            return;
        }

        $result = $this->downloadFiles($baseUrl, $files);

        $this->message->addSuccess(sprintf(
            '%d Mediendatei(en) heruntergeladen, %d bereits vorhanden (übersprungen).',
            $result['downloaded'],
            $result['skipped']
        ));

        if (\count($result['failed'])) {
            $escaped = array_map('rex_escape', $result['failed']);
            $this->message->addWarning('Folgende Dateien konnten nicht geladen werden (z. B. nicht öffentlich oder gelöscht):<br /><br /><pre class="rex-code">'.implode('<br />', $escaped).'</pre>');
        }
    }

    /**
     * Media filenames from the migrated rex_media table (ordered, stable).
     *
     * @return string[]
     */
    public function getMediaList()
    {
        $sql = \rex_sql::factory();
        $rows = $sql->getArray('SELECT DISTINCT `filename` FROM '.$sql->escapeIdentifier(\rex::getTable('media')).' WHERE `filename` IS NOT NULL AND `filename` != "" ORDER BY `filename`');

        return array_column($rows, 'filename');
    }

    /**
     * Media filenames from rex_media that are not yet present in the media dir. Used so a
     * restart only retries missing/failed files and the progress reflects remaining work.
     *
     * @return string[]
     */
    public function getMissingFiles()
    {
        $target = rtrim(\rex_path::media(), '/\\');

        $missing = [];
        foreach ($this->getMediaList() as $filename) {
            if (!is_file($target.'/'.$filename)) {
                $missing[] = $filename;
            }
        }

        return $missing;
    }

    /**
     * Downloads the given media filenames from <baseUrl>/files/ into the media dir,
     * skipping files already present. Used for the chunked AJAX/CLI runs.
     *
     * @param string[] $filenames
     * @return array{downloaded: int, skipped: int, failed: string[]}
     */
    public function downloadFiles($baseUrl, array $filenames)
    {
        $filesUrl = rtrim($baseUrl, '/').'/files/';
        $target = rtrim(\rex_path::media(), '/\\');

        $downloaded = 0;
        $skipped = 0;
        $failed = [];

        foreach ($filenames as $filename) {
            $dest = $target.'/'.$filename;

            if (is_file($dest)) {
                ++$skipped;
                continue;
            }

            $result = $this->download($filesUrl.rawurlencode($filename));
            if ($result['ok']) {
                \rex_file::put($dest, $result['body']);
                ++$downloaded;
            } else {
                $failed[] = $filename.' – '.$result['error'];
            }
        }

        return ['downloaded' => $downloaded, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * @return array{ok: bool, body?: string, error?: string}
     */
    private function download($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_USERAGENT => 'YConverter',
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (false === $body || '' !== $error) {
                return ['ok' => false, 'error' => '' !== $error ? $error : 'cURL-Fehler'];
            }
            if ($status < 200 || $status >= 300) {
                return ['ok' => false, 'error' => 'HTTP '.$status];
            }
            return ['ok' => true, 'body' => $body];
        }

        $context = stream_context_create(['http' => ['timeout' => 300, 'follow_location' => 1, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        if (false === $body) {
            return ['ok' => false, 'error' => 'Anfrage fehlgeschlagen'];
        }
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('@\s(\d{3})\s@', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if (0 !== $status && ($status < 200 || $status >= 300)) {
            return ['ok' => false, 'error' => 'HTTP '.$status];
        }
        return ['ok' => true, 'body' => $body];
    }

    private function copyFromPath($source)
    {
        $source = rtrim($source, '/\\');

        if (!is_dir($source) || !is_readable($source)) {
            $this->message->addError(sprintf('Der Medien-Quellpfad <code>%s</code> existiert nicht oder ist nicht lesbar.', rex_escape($source)));
            return;
        }

        $target = rtrim(\rex_path::media(), '/\\');
        $copied = 0;
        $failed = [];

        foreach (scandir($source) as $entry) {
            // Skip the addons sub-directory (REDAXO 4 stored addon-internal files there)
            // and the directory entries themselves.
            if ('.' === $entry || '..' === $entry || 'addons' === $entry) {
                continue;
            }

            $src = $source.'/'.$entry;
            $dst = $target.'/'.$entry;

            if (is_dir($src)) {
                if (\rex_dir::copy($src, $dst)) {
                    $copied += $this->countFiles($src);
                } else {
                    $failed[] = $entry;
                }
            } elseif (\rex_file::copy($src, $dst)) {
                ++$copied;
            } else {
                $failed[] = $entry;
            }
        }

        $this->message->addSuccess(sprintf(
            '%d Mediendatei(en) wurden von <code>%s</code> nach <code>%s</code> kopiert. Das Verzeichnis <code>addons</code> wurde ausgelassen.',
            $copied,
            rex_escape($source),
            rex_escape($target)
        ));

        if (\count($failed)) {
            $this->message->addWarning('Folgende Einträge konnten nicht kopiert werden:<br /><br /><pre class="rex-code">'.rex_escape(implode("\n", $failed)).'</pre>');
        }
    }

    private function countFiles($dir)
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                ++$count;
            }
        }
        return $count;
    }
}
