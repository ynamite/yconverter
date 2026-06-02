# YConverter

Migriert eine bestehende **REDAXO 4** Installation (Schema **und** Daten) in eine frische
**REDAXO 5** Installation. Der gesamte Vorgang läuft innerhalb von REDAXO 5 – in der alten
REDAXO-4-Installation muss **nichts** installiert werden.

> **Hinweis:** Dieses AddOn befindet sich in aktiver Entwicklung. Die voll funktionsfähige
> Variante für REDAXO 4 (Export aus REDAXO 4 heraus) liegt im Branch
> [redaxo4](https://github.com/yakamara/yconverter/tree/redaxo4).

## Voraussetzungen

- Eine frische REDAXO 5 Installation (Verbindung `1` = Zieldatenbank).
- Zugriff auf die Quelldaten der REDAXO-4-Seite als **zweite Datenbankverbindung (`2`)** –
  entweder direkt auf die laufende REDAXO-4-Datenbank **oder** auf eine Datenbank, in die ein
  `mysqldump` der alten Seite eingespielt wurde (siehe unten).

## Einrichtung (Einstellungen)

Unter *YConverter → Einstellungen* werden hinterlegt:

- **REDAXO Version** und **Tabellenprefix** der Quelle (z. B. `rex_`).
- **Datenbankverbindung** zur Quelle. Diese wird als Verbindung `2` in
  `data/addons/yconverter/config.yml` (`db.2`) gespeichert und beim Start als zusätzliche
  REDAXO-Verbindung registriert (`rex_sql::factory(2)`).
- **Medien-Quellpfad**: absoluter Pfad zum `files/`-Verzeichnis der REDAXO-4-Installation
  (für den Medien-Schritt).

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
3. **Medien kopieren** – kopiert die Dateien aus dem konfigurierten `files/`-Verzeichnis nach
   `media/` (ohne `files/addons/`).
4. **Eigene Tabellen → YForm** – Tabellen, die weder zum Core noch zu bekannten Addons
   gehören, werden zur Auswahl angeboten und als YForm-Tabellen (`rex_yf_…`) angelegt; die
   Feldtypen werden aus den Spaltentypen abgeleitet und sollten in YForm nachgeprüft werden.

### Alles in einem Schritt (CLI)

Für einen vollständigen, zeitlich nicht limitierten Durchlauf (empfohlen für große Medien-
Verzeichnisse) steht ein Konsolenbefehl bereit:

```
php redaxo/bin/console yconverter:run
```

Optionen u. a.: `--package=`, `--skip-clone`, `--skip-media`, `--media-source=`,
`--yform-tables=` (kommaseparierte Liste der zu konvertierenden eigenen Tabellen).

## Hinweise

- Die Übertragung ist **destruktiv** für die Zieltabellen (`TRUNCATE`). Der Klon-Schritt
  verwirft die `yconverter_*`-Tabellen bei jedem Lauf neu. Der Ablauf ist wiederholbar.
- Die Addon-Zuordnung (Code-Umschreibungen, Tabellen-Routing, Installations-Checkliste) ist
  **Best-Effort**: es gibt zu viele, zu unterschiedliche Addons, um alle automatisch
  abzudecken. Erweiterbar in `lib/YConverter/AddonMap.php`.
