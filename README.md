# YConverter

Migriert eine bestehende **REDAXO 4** Installation (Schema **und** Daten) in eine frische
**REDAXO 5** Installation. Der gesamte Vorgang läuft innerhalb von REDAXO 5 – in der alten
REDAXO-4-Installation muss **nichts** installiert werden.

> **Hinweis:** Dieses AddOn befindet sich in aktiver Entwicklung und wird aktuell als
> **Alpha** (`2.0.0-alpha1`) bereitgestellt – bitte nur in einer Test-/Wegwerf-Umgebung und
> mit vorherigem Backup einsetzen. Die voll funktionsfähige Variante für REDAXO 4 (Export aus
> REDAXO 4 heraus) liegt im Branch
> [redaxo4](https://github.com/yakamara/yconverter/tree/redaxo4).

## Funktionsumfang

- **Vollständige Migration** von Struktur und Daten für Core, Cronjob, Sprog und YForm.
- **Klonen ohne Eingriff in die Altinstallation** – die Quelle wird nur gelesen
  (Live-Datenbank oder `mysqldump`).
- **Medien-Übernahme** per HTTP-Download von der alten Seite oder per lokalem Dateipfad
  (chunked, fortsetzbar, ohne Timeout).
- **Intelligente Schema-Erkennung für eigene Tabellen → YForm:** Spalten werden anhand von
  Name, Datentyp und Beispielwerten passenden YForm-Feldtypen zugeordnet – inklusive
  mehrsprachiger Felder (`yform_lang_fields`) und Unix-Zeitstempel-Konvertierung.
- **Vorschau-Assistent:** Jede Zuordnung wird vor dem Schreiben angezeigt und ist
  editierbar (Feldtyp, Label, Parameter, Spalte entfernen).
- **Nachträgliche Neu-Erkennung** bereits importierter YForm-Tabellen, ohne bestehende
  Felddefinitionen zu verlieren.
- **Optionale KI-Unterstützung** (OpenAI / Anthropic) für unsichere Spalten.
- **Konsolenbefehl** für den kompletten Durchlauf inkl. read-only `--dry-run`-Vorschau.

## Voraussetzungen

- Eine frische REDAXO 5 Installation (Verbindung `1` = Zieldatenbank).
- Zugriff auf die Quelldaten der REDAXO-4-Seite als **zweite Datenbankverbindung (`2`)** –
  entweder direkt auf die laufende REDAXO-4-Datenbank **oder** auf eine Datenbank, in die ein
  `mysqldump` der alten Seite eingespielt wurde (siehe unten).
- Optional für mehrsprachige YForm-Felder: das AddOn
  [`yform_lang_fields`](https://github.com/klxm/yform_lang_fields).
- Optional für die KI-Unterstützung: ein API-Key von OpenAI oder Anthropic.

## Einrichtung (Einstellungen)

Die Einstellungen sind in zwei Unterseiten gegliedert:

### Allgemein

- **Quell-Datenbank (REDAXO 4):** REDAXO-Version und Tabellenprefix der Quelle (z. B. `rex_`)
  sowie die Zugangsdaten der Quelldatenbank. Diese werden als Verbindung `2` in
  `data/addons/yconverter/config.yml` (`db.2`) gespeichert und beim Start als zusätzliche
  REDAXO-Verbindung registriert (`rex_sql::factory(2)`).
- **Medien:** Entweder eine **Quell-URL** (Basis-URL der alten Seite – die Dateien werden per
  HTTP aus `…/files/` geladen; empfohlen, solange die alte Seite online ist) **oder** ein
  absoluter **Quellpfad** zum `files/`-Verzeichnis.

### KI-Unterstützung

- **Anbieter** (`Keiner` / OpenAI / Anthropic), **API-Key** und optional ein **Modell**.
- **Beispielwerte an die KI senden** (Standard: an) – ausgeschaltet werden nur Spaltennamen
  und -typen übertragen.

Die KI ist vollständig optional: Ohne API-Key arbeitet die Erkennung rein heuristisch. Ein
gespeicherter API-Key bzw. ein gespeichertes DB-Passwort bleibt erhalten, wenn das jeweilige
Feld beim Speichern leer gelassen wird. Das Speichern einer Unterseite verändert die
Einstellungen der anderen nicht.

### Migration aus einem SQL-Dump (statt Live-Verbindung)

Es ist kein separater Datei-Import nötig: Spiele den `mysqldump` der REDAXO-4-Datenbank in
eine beliebige, vom REDAXO-5-Server erreichbare Datenbank ein (z. B. eine zweite Datenbank
auf demselben MySQL-Server) und trage diese Datenbank in den Einstellungen als
Quellverbindung ein. Der weitere Ablauf ist identisch zur Live-Verbindung.

## Ablauf

Unter *YConverter → Converter*:

1. **Klonen** – kopiert alle Quelltabellen als `yconverter_*`-Staging-Tabellen in die
   REDAXO-5-Datenbank. Im Anschluss zeigt eine Best-Effort-Checkliste, welche REDAXO-5-Addons
   für die erkannten alten Addons installiert/aktiviert sein sollten (es wird nichts
   automatisch installiert).
2. Pro Paket (Core, Cronjob, Sprog, YForm) jeweils **Struktur aktualisieren → Inhalte
   anpassen → Vergleichen → Übertragen**. Die Übertragung leert die echten R5-Tabellen
   (`TRUNCATE`) und füllt sie aus den Staging-Tabellen.
3. **Medien kopieren** – lädt die Dateien per HTTP von der Quell-URL bzw. kopiert sie aus dem
   konfigurierten `files/`-Verzeichnis nach `media/` (ohne `files/addons/`). Bereits
   vorhandene Dateien werden übersprungen.
Die beiden Folgeschritte – **eigene Tabellen → YForm** und **seo42-URL-Migration** – liegen in
eigenen Reitern *„YForm"* und *„SEO42"* (unter *Converter*) und sind weiter unten beschrieben.

## Eigene Tabellen → YForm (Schema-Erkennung)

Im Reiter *Converter → YForm*: Tabellen, die weder zum Core noch zu bekannten Addons gehören,
können als YForm-Tabellen (`rex_yf_…`) angelegt werden. Statt die Feldtypen nur aus dem
Spaltentyp abzuleiten, schlägt eine regelbasierte Erkennung passende YForm-Feldtypen vor.

### Ablauf: Analysieren → Vorschau → Anwenden

1. Auswahl der Tabellen – getrennt nach **neuen** (geklonten, noch nicht importierten) und
   **bereits importierten** YForm-Tabellen.
2. **Analysieren** zeigt eine Vorschau-Tabelle: pro Feld der vorgeschlagene **YForm-Feldtyp**,
   **Label**, **Parameter**, eine **Konfidenz** (HOCH/MITTEL/NIEDRIG) und eine kurze
   **Begründung**. Im Mapping-Modus wird ausschließlich die Vorschau angezeigt.
3. Jede Zeile ist editierbar; über den Feldtyp **„— Spalte entfernen —“** lässt sich eine
   Spalte komplett verwerfen.
4. **Mappings anwenden** schreibt genau die bestätigten Zuordnungen.

### Erkannte Muster (Auszug)

| Spalte / Typ | YForm-Feldtyp |
| --- | --- |
| `status`/`online`/`active` (tinyint, Werte 0/1) | `choice` mit `offline=0,online=1` |
| `createdate`/`updatedate`/`*_at`/`timestamp` (Integer-Zeitstempel) | `datestamp` – Werte werden per `FROM_UNIXTIME` nach `datetime` konvertiert |
| `url`/`website`/`homepage`/`link` | `text` mit Attribut `type="url"` |
| `author`/`editor`/`redakteur`/`bearbeiter`/`owner` | `be_user` |
| `email`/`mail` | `email` |
| `color`/`farbe`(`_hex`/`_code`) | `color_swatch` (wenn `mform` installiert, sonst Text) |
| `*file*`/`image`/`foto`/`pdf` | `be_media` (`multiple` bei Plural-Namen oder Text-Spalten) |
| `year`/`jahr` | `number` |
| `price`/`betrag`/… (decimal) | `number` |
| `description`/`text`/`body`/… | `textarea` |
| `prefix_0` … `prefix_n` (eine Spalte je Sprache) | `lang_text` / `lang_textarea` / `lang_media` |
| sonst | abgeleitet aus dem Spaltentyp |

Der Feldtyp-Katalog ist **abhängig von installierten Addons**: YForm-Core-Typen (u. a.
`be_user`, `be_link`, `email`) sind immer verfügbar; `lang_*` benötigt `yform_lang_fields`,
und `custom_link`, `custom_link_multi`, `imagelist`, `color_swatch`, `medialist`, `linklist`
benötigen `mform`. Nicht installierte Typen werden weder in der Vorschau angeboten noch von
der KI vorgeschlagen oder geschrieben.

### Mehrsprachige Felder

Mehrere Spalten mit gemeinsamem Präfix und numerischem Suffix (`title_0`, `title_1`, …)
werden als Sprachgruppe erkannt. Die Suffixe werden den `rex_clang`-IDs zugeordnet (direkt
oder per einheitlichem Versatz – REDAXO 4 zählt ab 0, REDAXO 5 ab 1) und in **ein** Feld
(`lang_text` / `lang_textarea` / `lang_media` des AddOns `yform_lang_fields`)
zusammengeführt. Die Daten werden in das von `yform_lang_fields` erwartete JSON-Format
überführt. Ist das AddOn nicht installiert, bleiben die Spalten als Einzelfelder erhalten
(mit Sprachkennzeichnung im Label).

### Parameter & HTML-Attribute

Parameter werden zeilenweise als `name=wert` angegeben. Schlüssel, die einer echten
`rex_yform_field`-Spalte entsprechen (z. B. `choices`, `multiple`), werden direkt gesetzt;
unbekannte Schlüssel (z. B. `class`, `data-profile`) werden als HTML-Attribute im
`attributes`-JSON gespeichert, z. B.:

```
class=tiny-editor
data-profile=massif
```
→ `{"class":"tiny-editor","data-profile":"massif"}`

### Nachträgliche Neu-Erkennung

Bereits importierte YForm-Tabellen können erneut analysiert werden. Dabei werden die
**bestehenden Felddefinitionen** (Typ, Label, Parameter, Attribute) geladen und angezeigt, um
manuelle Anpassungen nicht zu verlieren. Neue Spalten werden frisch erkannt, Sprachgruppen
werden weiterhin zusammengeführt. Nach dem Schreiben der Felddefinitionen wird die
Tabellenstruktur über YForms eigene Funktion (`generateTableAndFields`) abgeglichen.

> **Achtung:** Beim Aktualisieren werden die Felddefinitionen der Tabelle ersetzt. Daten und
> Tabellen-Registrierung bleiben erhalten.

## seo42 URL-Control → URL-Addon

War auf der alten Seite seo42s URL-Generierung aktiv (`rex_url_control_generate`), bietet
der Reiter *Converter → SEO42* an, daraus Profile für das [`url`-Addon](https://github.com/FriendsOfREDAXO/url)
(`rex_url_generator_profile`) zu erzeugen. Ableitbar sind: ein Profil je altem Eintrag,
`clang` +1 (R4 0-basiert → R5 1-basiert), ID-/Segment-Spalten und einfache Einschränkungen
sowie die Zieltabelle (alte Tabelle → `rex_yf_<name>`). Namespace und Artikel-ID werden
vorbelegt, aber zur Prüfung markiert. Nach „Profile anlegen" werden über die Addon-eigenen
Funktionen die Profile registriert und die URLs neu generiert. `rex_url_control_manager`
(manuelle URL-Methoden) wird nur als Hinweis gemeldet, nicht automatisch migriert.
Voraussetzung: das `url`-Addon ist installiert.

## Alles in einem Schritt (CLI)

Für einen vollständigen, zeitlich nicht limitierten Durchlauf (empfohlen für große Medien-
Verzeichnisse) steht ein Konsolenbefehl bereit:

```
php redaxo/bin/console yconverter:run
```

Optionen:

- `--package=` (`-p`) – nur diese Pakete migrieren (mehrfach möglich; Standard: alle).
- `--skip-clone` – Klon-Schritt überspringen.
- `--skip-media` – Medien-Kopie überspringen.
- `--yform-tables=` – kommaseparierte Liste eigener Tabellen, die nach YForm konvertiert
  werden sollen.
- `--dry-run` – **schreibt nichts**: gibt nur die erkannten Feld-Zuordnungen für neue und
  bereits importierte Tabellen aus (eignet sich zur Prüfung der Erkennung; bricht vor allen
  destruktiven Schritten ab).

## Hinweise

- Die Übertragung ist **destruktiv** für die Zieltabellen (`TRUNCATE`). Der Klon-Schritt
  verwirft die `yconverter_*`-Tabellen bei jedem Lauf neu. Der Ablauf ist wiederholbar.
- Die Addon-Zuordnung (Code-Umschreibungen, Tabellen-Routing, Installations-Checkliste) ist
  **Best-Effort**: es gibt zu viele, zu unterschiedliche Addons, um alle automatisch
  abzudecken. Erweiterbar in `lib/YConverter/AddonMap.php`.
- Nach dem Hinzufügen/Umbenennen von Klassen unter `lib/` oder Änderungen an `package.yml`
  muss das AddOn **neu installiert/aktiviert** oder der REDAXO-Cache geleert werden.

## Entwicklung

Die Schema-Erkennung liegt unter `lib/YConverter/Schema/`:

- `SchemaDetector` – Regelwerk (in `rules()` erweiterbar), i18n-Gruppierung, optionaler
  KI-Durchlauf.
- `FieldMapping` – das Ergebnis-Objekt je Feld.
- `ValueSampler` – sammelt Beispielwerte für wertabhängige Regeln.
- `LangDataMerger` – Zusammenführung der Sprachspalten in das `yform_lang_fields`-JSON.
- `Ai/*` – optionale Provider (OpenAI/Anthropic, ein HTTP-Aufruf via `rex_socket`, keine
  Composer-Abhängigkeit) und der JSON-Parser.

Die reine Erkennungslogik ist ohne Composer/PHPUnit testbar:

```
php tests/run.php
```
