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
use YConverter\Config;
use YConverter\InstallChecklist;
use YConverter\Message;
use YConverter\Package\Core;
use YConverter\Report;
use YConverter\YConverter;
use YConverter\YFormImporter;

$func = rex_request('func', 'string');
$pack = rex_request('package', 'string');
$csrfToken = rex_csrf_token::factory('yconverter');

$packageRouting = AddonMap::packageRouting();

$packageLabels = [
    'core' => 'Core',
    'cronjob' => 'Cronjob',
    'sprog' => 'Sprog',
    'yform' => 'YForm',
];
$packageNotes = [
    'cronjob' => rex_i18n::msg('yconverter_cronjob_info'),
];
$packageSteps = [
    'update' => rex_i18n::msg('yconverter_update_table_structures_to_last_version'),
    'modify' => rex_i18n::msg('yconverter_modify_table_contents'),
    'compare' => rex_i18n::msg('yconverter_compare_tables_and_columns'),
    'transfer' => rex_i18n::msg('yconverter_transfer_data_to_instance'),
];

// --- Actions ---------------------------------------------------------------

if ($func && !$csrfToken->isValid()) {
    echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
} elseif ('reset' === $func) {
    // Drop the yconverter_* staging tables and clear the progress flags so the converter
    // starts fresh. The live REDAXO 5 tables are not touched.
    $converter = new YConverter(new Core());
    $converter->dropStagingTables();
    foreach (array_merge(['yconverter', 'media', 'customtables'], array_keys($packageRouting)) as $configKey) {
        rex_config::remove('yconverter', $configKey);
    }
    echo $converter->getMessages();
    echo rex_view::success(rex_i18n::msg('yconverter_reset_done'));
} elseif ('' !== $func) {
    if (!($config = new Config())->isValid()) {
        echo rex_view::error(rex_i18n::msg('yconverter_invalid_config').'<br />- '.implode('<br />- ', $config->getValidationErrors()));
    } elseif ('migrate' === $func) {
        // Migrate every package that has source data: structure -> contents -> compare -> transfer.
        foreach ($packageRouting as $name => $class) {
            $converter = new YConverter(new $class());
            if (!$converter->packageHasSource()) {
                echo rex_view::info(rex_i18n::msg('yconverter_package_skipped', $name));
                continue;
            }
            $converter->updateTables();
            $converter->modifyTables();
            $converter->compareTables();
            $converter->transferData();
            echo $converter->getMessages();
        }
    } else {
        $packageClass = isset($packageRouting[$pack]) ? $packageRouting[$pack] : Core::class;
        $converter = new YConverter(new $packageClass());

        switch ($func) {
            case 'clone':
                $converter->cloneTables();
                break;
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
            case 'run':
                $converter->updateTables();
                $converter->modifyTables();
                $converter->compareTables();
                $converter->transferData();
                break;
            case 'media':
                $converter->copyMedia();
                break;
            case 'yformimport':
                $converter->convertCustomTables(rex_request('yconverter_tables', 'array', []));
                break;
        }
        echo $converter->getMessages();
    }
}

// --- Helpers ---------------------------------------------------------------

$url = function ($func, $package = '') use ($csrfToken) {
    return rex_url::currentBackendPage(['func' => $func, 'package' => $package] + $csrfToken->getUrlParams());
};

$renderConfig = new Config();

// Which staging tables currently exist — the source of truth for "clone done", so the
// state survives a page refresh regardless of rex_config.
$stagingTables = [];
$dbConfig = rex::getProperty('db');
if (isset($dbConfig['1']['name'])) {
    $rows = rex_sql::factory()->getArray(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = :s AND table_name LIKE :p',
        ['s' => $dbConfig['1']['name'], 'p' => $renderConfig->getConverterTablePrefix().'%'],
        PDO::FETCH_NUM
    );
    foreach ($rows as $r) {
        $stagingTables[] = $r[0];
    }
}

// Packages that actually have cloned source tables (so addons unused in the source are
// skipped instead of erroring on missing tables).
$activePackages = [];
foreach ($packageRouting as $name => $class) {
    $sourceTables = (new $class())->getSourceTables();
    if (!$sourceTables) {
        $activePackages[$name] = $class;
        continue;
    }
    foreach ($sourceTables as $sourceTable) {
        if (in_array($renderConfig->getConverterTable($sourceTable), $stagingTables, true)) {
            $activePackages[$name] = $class;
            break;
        }
    }
}

// completion state (steps stay clickable so they can be re-run)
$cloneDone = count($stagingTables) > 0;
$migrateDone = $cloneDone && (bool) count($activePackages);
foreach (array_keys($activePackages) as $name) {
    if (!in_array('transfer', rex_config::get('yconverter', $name, []), true)) {
        $migrateDone = false;
    }
}
$mediaDone = in_array('media', rex_config::get('yconverter', 'media', []), true);

// Chunked, non-blocking AJAX runner (clone/migrate/media). Falls back to synchronous
// links when the API class is not yet indexed (addon not re-installed after this update).
$apiUrl = class_exists('rex_api_yconverter_run') ? rex_url::backendController(rex_api_yconverter_run::getUrlParams()) : null;

$progressBox = function ($id) {
    return '<div id="'.$id.'" class="yconv-progress" style="display:none; margin-top:15px;">'
        .'<div class="progress"><div class="progress-bar progress-bar-striped active" role="progressbar" style="width:0%; min-width:2.5em;">0%</div></div>'
        .'<p class="yconv-status text-muted"></p>'
        .'<div class="yconv-messages"></div>'
        .'</div>';
};
$runButton = function ($what, $target, $label, $lg = false) use ($apiUrl) {
    return '<button type="button" class="btn btn-primary'.($lg ? ' btn-lg' : '').' yconv-run" data-what="'.$what.'" data-url="'.rex_escape($apiUrl).'" data-target="'.$target.'">'.$label.'</button>';
};
$actionLink = function ($func, $package, $label, $lg = false) use ($url) {
    return '<a class="btn btn-primary'.($lg ? ' btn-lg' : '').'" href="'.$url($func, $package).'">'.$label.'</a>';
};

// Wizard step renderer: completed steps collapse, the current step is highlighted, future
// steps are muted — so it is always clear where to continue.
$renderStep = function ($number, $title, $done, $isCurrent, $bodyHtml) {
    $id = 'yconv-step-'.$number;
    if ($done && !$isCurrent) {
        return '<div class="panel panel-default">'
            .'<div class="panel-heading" role="button" data-toggle="collapse" data-target="#'.$id.'" style="cursor:pointer;">'
            .'<span class="label label-success">&#10003;</span> '.$number.'. '.rex_escape($title)
            .'<small class="pull-right text-muted">'.rex_i18n::msg('yconverter_done').' &middot; anzeigen</small>'
            .'</div>'
            .'<div id="'.$id.'" class="panel-collapse collapse"><div class="panel-body">'.$bodyHtml.'</div></div>'
            .'</div>';
    }
    $panelClass = $isCurrent ? 'panel-primary' : 'panel-default';
    $style = (!$isCurrent && !$done) ? ' style="opacity:0.6;"' : '';
    $badge = $done ? ' <span class="label label-success pull-right">'.rex_i18n::msg('yconverter_done').'</span>' : '';
    return '<div class="panel '.$panelClass.'"'.$style.'>'
        .'<div class="panel-heading"><span class="label label-default">'.$number.'</span> '.rex_escape($title).$badge.'</div>'
        .'<div class="panel-body">'.$bodyHtml.'</div></div>';
};

// First not-yet-done step is the current/highlighted one (4 = optional final step).
$currentStep = !$cloneDone ? 1 : (!$migrateDone ? 2 : (!$mediaDone ? 3 : 4));

// --- Render ----------------------------------------------------------------

$out = rex_view::info(
    rex_i18n::msg('yconverter_intro')
    .' <a href="'.rex_url::backendPage('yconverter/settings').'">'.rex_i18n::msg('yconverter_open_settings').'</a>'
);

// Step 1 — clone
$lg = (1 === $currentStep);
$body = '<p>'.rex_i18n::msg('yconverter_step1_text').'</p>';
$body .= $apiUrl
    ? $runButton('clone', 'yconv-clone', rex_i18n::msg('yconverter_execute'), $lg).$progressBox('yconv-clone')
    : $actionLink('clone', 'yconverter', rex_i18n::msg('yconverter_execute'), $lg);
if ($cloneDone && 'clone' !== $func) {
    // Persist the clone result across refreshes: count + table list + install checklist.
    $checklistMessage = new Message();
    (new InstallChecklist($renderConfig, $checklistMessage))->run();
    $body .= rex_view::success(rex_i18n::msg('yconverter_tables_cloned_count', count($stagingTables)))
        .'<p><a data-toggle="collapse" href="#yconverter-cloned">'.rex_i18n::msg('yconverter_show_cloned_tables').'</a></p>'
        .'<div id="yconverter-cloned" class="collapse"><pre class="rex-code">'.rex_escape(implode("\n", $stagingTables)).'</pre></div>'
        .$checklistMessage->getAll();
}
$out .= $renderStep(1, rex_i18n::msg('yconverter_step1_heading'), $cloneDone, 1 === $currentStep, $body);

// Step 2 — migrate all data (+ collapsible per-area controls)
$advanced = '<div class="row">';
foreach ($activePackages as $name => $class) {
    $label = isset($packageLabels[$name]) ? $packageLabels[$name] : ucfirst($name);
    $items = '<a class="list-group-item list-group-item-info" href="'.$url('run', $name).'"><strong>'.rex_i18n::msg('yconverter_run_all').'</strong></a>';
    foreach ($packageSteps as $stepFunc => $stepLabel) {
        $items .= '<a class="list-group-item" href="'.$url($stepFunc, $name).'">'.rex_escape($stepLabel).'</a>';
    }
    $note = isset($packageNotes[$name]) ? '<div class="panel-footer">'.$packageNotes[$name].'</div>' : '';
    $advanced .= '<div class="col-md-3"><div class="panel panel-default"><div class="panel-heading">'.rex_escape($label).'</div><div class="list-group">'.$items.'</div>'.$note.'</div></div>';
}
$advanced .= '</div>';

$lg = (2 === $currentStep);
$body = '<p>'.rex_i18n::msg('yconverter_step2_text').'</p>';
$body .= $apiUrl
    ? $runButton('migrate', 'yconv-migrate', rex_i18n::msg('yconverter_migrate_all_packages'), $lg).$progressBox('yconv-migrate')
    : $actionLink('migrate', '', rex_i18n::msg('yconverter_migrate_all_packages'), $lg);
$body .= '<p style="margin-top:15px;"><a data-toggle="collapse" href="#yconverter-advanced" aria-expanded="false">'.rex_i18n::msg('yconverter_advanced_single_packages').'</a></p>'
    .'<div id="yconverter-advanced" class="collapse">'.$advanced.'</div>';
if ($apiUrl && is_file(Report::path())) {
    $body .= '<p style="margin-top:10px;"><a class="btn btn-xs btn-default" href="'.rex_escape($apiUrl.'&action=report').'">'.rex_i18n::msg('yconverter_download_report').'</a></p>';
}
$out .= $renderStep(2, rex_i18n::msg('yconverter_step2_heading'), $migrateDone, 2 === $currentStep, $body);

// Step 3 — media
$lg = (3 === $currentStep);
$body = '<p>'.rex_i18n::msg('yconverter_step3_text').'</p>';
if ($renderConfig->getMediaSourceUrl()) {
    // HTTP download: chunked + progress bar so the UI does not block and cannot time out.
    $body .= $apiUrl
        ? $runButton('media', 'yconv-media', rex_i18n::msg('yconverter_execute'), $lg).$progressBox('yconv-media')
        : $actionLink('media', '', rex_i18n::msg('yconverter_execute'), $lg);
} else {
    // Local copy is synchronous (fast, local filesystem).
    $body .= $actionLink('media', '', rex_i18n::msg('yconverter_execute'), $lg);
}
$out .= $renderStep(3, rex_i18n::msg('yconverter_step3_heading'), $mediaDone, 3 === $currentStep, $body);

// Step 4 — custom tables -> YForm (generic; vegafilm is just one example)
$body = '';
if (!$renderConfig->isValid()) {
    $body = '<p>'.rex_i18n::msg('yconverter_yform_no_custom_tables').'</p>';
} else {
    $customTables = (new YFormImporter($renderConfig, new Message()))->detectCustomTables();
    if (count($customTables)) {
        $checkboxes = '';
        foreach ($customTables as $customTable) {
            $checkboxes .= sprintf(
                '<div class="checkbox"><label><input type="checkbox" name="yconverter_tables[]" value="%s" checked> <code>%s</code> &rarr; <code>%s</code></label></div>',
                rex_escape($customTable),
                rex_escape($customTable),
                rex_escape(rex::getTable('yf_'.$customTable))
            );
        }
        $body = '<form action="'.rex_url::currentBackendPage().'" method="post">'
            .'<input type="hidden" name="func" value="yformimport" />'
            .$csrfToken->getHiddenField()
            .'<p>'.rex_i18n::msg('yconverter_yform_custom_tables_info').'</p>'
            .$checkboxes
            .'<button class="btn btn-primary'.(4 === $currentStep ? ' btn-lg' : '').'" type="submit">'.rex_i18n::msg('yconverter_execute').'</button>'
            .'</form>';
    } else {
        $body = '<p>'.rex_i18n::msg('yconverter_yform_no_custom_tables').'</p>';
    }
}
$out .= $renderStep(4, rex_i18n::msg('yconverter_yform_custom_tables'), false, 4 === $currentStep, $body);

// Reset / start over (de-emphasized)
$out .= '<p class="text-right" style="margin-top:8px;"><a class="btn btn-xs btn-link text-muted" href="'.$url('reset').'">'.rex_i18n::msg('yconverter_reset_and_start_again').'</a></p>';

if ($apiUrl) {
    $out .= '<script>
(function(){
  function esc(s){ var d = document.createElement("div"); d.textContent = s; return d.innerHTML; }
  var buttons = document.querySelectorAll(".yconv-run");
  for (var b = 0; b < buttons.length; b++){
    buttons[b].addEventListener("click", function(){
      var btn = this;
      var base = btn.getAttribute("data-url");
      var what = btn.getAttribute("data-what");
      var box = document.getElementById(btn.getAttribute("data-target"));
      var bar = box.querySelector(".progress-bar");
      var status = box.querySelector(".yconv-status");
      var messages = box.querySelector(".yconv-messages");
      var dl = 0, sk = 0, failed = [], warnings = 0;

      btn.disabled = true;
      box.style.display = "";
      bar.className = "progress-bar progress-bar-striped active"; bar.style.width = "0%"; bar.textContent = "0%";
      status.className = "yconv-status text-muted"; status.textContent = "Starten...";
      messages.innerHTML = "";

      function post(unit){
        return fetch(base, {method: "POST", credentials: "same-origin", headers: {"Content-Type": "application/json"}, body: JSON.stringify(unit)}).then(function(r){ return r.json(); });
      }
      function fail(err){
        bar.className = "progress-bar progress-bar-danger";
        status.className = "yconv-status text-danger"; status.textContent = err;
        btn.disabled = false;
      }
      function done(){
        bar.className = "progress-bar progress-bar-success"; bar.style.width = "100%"; bar.textContent = "100%";
        var msg;
        var extra = "";
        if (what === "media"){
          msg = "Fertig. " + dl + " Datei(en) geladen, " + sk + " bereits vorhanden.";
        } else if (what === "migrate"){
          msg = "Migration abgeschlossen. " + warnings + " Hinweis(e) zur Prüfung. Danach weiter mit dem nächsten Schritt.";
          extra = " <a class=\"btn btn-xs btn-default\" href=\"" + base + "&action=report\">Bericht herunterladen</a>";
          if (messages.innerHTML.replace(/\s/g, "") !== ""){
            messages.innerHTML = "<details style=\"margin-top:10px;\"><summary>Details / Hinweise anzeigen</summary>" + messages.innerHTML + "</details>";
          }
        } else {
          msg = "Fertig.";
        }
        if (failed.length){ msg += " " + failed.length + " Fehler."; messages.innerHTML += "<pre>" + failed.map(esc).join("\n") + "</pre>"; }
        status.className = "yconv-status";
        status.innerHTML = "<strong>" + esc(msg) + "</strong>" + extra + " <a href=\"#\" class=\"yconv-reload\">Seite neu laden</a>";
        var rl = status.querySelector(".yconv-reload");
        if (rl){ rl.addEventListener("click", function(e){ e.preventDefault(); location.reload(); }); }
        btn.disabled = false;
      }

      fetch(base + "&action=plan&what=" + encodeURIComponent(what), {credentials: "same-origin"})
        .then(function(r){ return r.json(); })
        .then(function(plan){
          if (!plan || plan.ok === false){ fail(plan && plan.error ? plan.error : "Planung fehlgeschlagen."); return; }
          var units = plan.units || [];
          var total = units.length;
          if (!total){ done(); return; }
          var i = 0;
          function next(){
            if (i >= total){ done(); return; }
            var unit = units[i];
            status.textContent = (i + 1) + " / " + total + (unit.label ? " - " + unit.label : "");
            post(unit).then(function(res){
              if (!res || res.ok === false){ fail(res && res.error ? res.error : ("Fehler bei: " + (unit.label || unit.action))); return; }
              if (typeof res.downloaded === "number"){ dl += res.downloaded; sk += (res.skipped || 0); }
              if (typeof res.warnings === "number"){ warnings += res.warnings; }
              if (res.failed && res.failed.length){ failed = failed.concat(res.failed); }
              if (res.html){ messages.innerHTML += res.html; }
              i++;
              var pct = Math.round(i / total * 100);
              bar.style.width = pct + "%"; bar.textContent = pct + "%";
              next();
            }).catch(function(e){ fail("Netzwerkfehler: " + e); });
          }
          next();
        })
        .catch(function(e){ fail("Netzwerkfehler: " + e); });
    });
  }
})();
</script>';
}

echo $out;
