# Changelog

Alle nennenswerten Änderungen an diesem AddOn werden hier dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [2.0.0-alpha3] – 2026-06-04

### Behoben

- Die YForm-Zuordnungs-Vorschau (Schritt 4) brach mit „Class
  `YConverter\YConverter\Schema\SchemaDetector` not found" ab: ein qualifizierter
  Klassenname kollidierte mit dem `use YConverter\YConverter;`-Alias und wurde doppelt
  aufgelöst. Der Feldtyp-Katalog wird jetzt korrekt über einen `use`-Import angesprochen.
  (Regression aus 2.0.0-alpha2.)

## [2.0.0-alpha2] – 2026-06-03

### Hinzugefügt

- Unterstützung weiterer YForm-Feldtypen in der Erkennung und der Vorschau-Auswahl:
  **`be_user`** (YForm-Core) sowie **`custom_link`** und **`custom_link_multi`** (AddOn
  `mform`). Spalten wie `author`/`editor`/`redakteur` werden heuristisch als `be_user`
  erkannt; die Link-Felder stehen zur manuellen Auswahl bzw. als KI-Vorschlag bereit.

### Geändert

- Der Feldtyp-Katalog ist zentralisiert (`SchemaDetector::allowedTypes()`) und wird sowohl
  für die KI als auch für die Vorschau-Auswahl genutzt.
- Ein bereits gesetzter oder unbekannter Feldtyp bleibt in der Auswahl erhalten, damit bei
  der erneuten Erkennung keine Zuordnung verloren geht.

### Behoben

- Externe URLs werden nicht mehr auf einen (in YForm nicht existierenden) `url`-Feldtyp
  abgebildet, sondern auf ein `text`-Feld mit dem Attribut `type="url"`. Der `url`-Typ wurde
  aus dem Katalog entfernt.

## [2.0.0-alpha1] – 2026-06-03

Erste öffentliche **Alpha** der REDAXO-5-Neufassung. Schwerpunkt ist die **intelligente
Schema-Erkennung** beim Import eigener Tabellen nach YForm sowie die Neustrukturierung der
Einstellungen. Als Alpha-Version: nur in einer Test-/Wegwerf-Umgebung einsetzen, vorher
Backups anlegen.

### Hinzugefügt

- **Intelligente Schema-Erkennung (`lib/YConverter/Schema/`).** Spalten werden anhand von
  Spaltenname, MySQL-Datentyp und Beispielwerten passenden YForm-Feldtypen zugeordnet
  (regelbasiert, erweiterbar in `SchemaDetector::rules()`):
  - `status`/`online`/`active` (tinyint mit Werten 0/1) → `choice` mit `offline=0,online=1`
  - `url`/`website`/`homepage`/`link` → `url`
  - `*file*`/`image`/`foto`/`pdf` → `be_media` (`multiple` bei Plural-Namen/Text-Spalten)
  - `year`/`jahr` und Preis-/Betragsspalten → `number`
  - `description`/`body`/`text`/… → `textarea`
- **Unix-Zeitstempel → `datestamp`.** Integer-Spalten wie `createdate`, `updatedate`,
  `*_at` oder `timestamp` werden als `datestamp` (DB-Typ `datetime`) erkannt und die Werte
  beim Anwenden per `FROM_UNIXTIME` konvertiert.
- **Mehrsprachige Felder.** Spaltengruppen mit gemeinsamem Präfix und numerischem Suffix
  (`title_0`, `title_1`, …) werden zu einem `lang_text` / `lang_textarea` / `lang_media`-Feld
  zusammengeführt (AddOn `yform_lang_fields`). Die Suffixe werden den `rex_clang`-IDs
  zugeordnet (inkl. Versatz für REDAXO-4-0-basierte Sprachen), die Daten in das erwartete
  JSON-Format überführt. Ohne das AddOn bleiben die Spalten als Einzelfelder erhalten.
- **Vorschau-Assistent (Analysieren → Vorschau → Anwenden).** Jede Zuordnung wird mit
  Feldtyp, Label, Parametern, Konfidenz (HOCH/MITTEL/NIEDRIG) und Begründung angezeigt und
  ist vor dem Schreiben editierbar.
- **Spalten entfernen.** In der Vorschau lassen sich Spalten über den Feldtyp
  „— Spalte entfernen —“ komplett verwerfen.
- **HTML-Attribute über Parameter.** Unbekannte Parameter-Schlüssel (z. B. `class`,
  `data-profile`) werden als `attributes`-JSON gespeichert.
- **Nachträgliche Neu-Erkennung** bereits importierter YForm-Tabellen: bestehende
  Felddefinitionen (Typ, Label, Parameter, Attribute) werden geladen und übernommen, neue
  Spalten erkannt, Sprachgruppen zusammengeführt.
- **Optionale KI-Unterstützung** (OpenAI / Anthropic) für Spalten mit niedriger Konfidenz –
  ein HTTP-Aufruf via `rex_socket`, keine Composer-Abhängigkeit, abschaltbarer Versand von
  Beispielwerten.
- **Konsole `yconverter:run --dry-run`** – gibt die erkannten Feld-Zuordnungen aus, ohne zu
  schreiben (bricht vor den destruktiven Schritten ab).
- **Test-Runner** für die reine Erkennungslogik: `php tests/run.php` (ohne Composer/PHPUnit).

### Geändert

- **Einstellungen in Unterseiten gegliedert:** *Allgemein* (Quell-Datenbank und Medien) und
  *KI-Unterstützung*. Die Konfigurations-Ein-/Ausgabe ist in `Config` zentralisiert
  (`file()`/`defaults()`/`read()`/`write()`); jede Unterseite speichert nur ihre eigenen
  Felder, ohne die Einstellungen der anderen zu überschreiben.
- **Tabellenschema-Abgleich:** Nach dem Schreiben der Felddefinitionen wird die Datentabelle
  über YForms eigene Funktion `generateTableAndFields` an die Felddefinitionen angeglichen.
- **YForm-Import** wird durch die erkannten/­bestätigten `FieldMapping`-Objekte gesteuert
  (statt einer reinen Ableitung aus dem Spaltentyp); im Mapping-Modus wird nur noch die
  Vorschau angezeigt, der übrige Assistent ausgeblendet.

### Behoben

- KI-Vorschläge bzw. Parameter können die festen `rex_yform_field`-Spalten nicht mehr
  versehentlich überschreiben.
- Die Einstellung „Beispielwerte an die KI senden“ wird nun tatsächlich berücksichtigt.
- `--dry-run` ist garantiert nicht-destruktiv (bricht vor Klonen/Migrieren ab).

### Hintergrund

Diese Version ist die laufende **REDAXO-5-Neufassung**: Die Migration läuft vollständig in
REDAXO 5, gegliedert in Klonen → (Struktur/Inhalte/Vergleich/Übertragung je Paket) →
Medien → eigene Tabellen nach YForm. Die voll funktionsfähige REDAXO-4-Variante liegt im
Branch `redaxo4`.
