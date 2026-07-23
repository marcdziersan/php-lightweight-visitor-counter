# Lightweight & Privacy-Focused PHP Visitor Counter

Ein flexibles, performantes und datenschutzfreundliches PHP-Skript zur Erfassung von Seitenaufrufen. Das Skript fungiert primär als dynamisches Zähler-Bild (GD Library), bietet jedoch bei Aufruf mit dem Parameter `?analyse` ein übersichtliches Dashboard mit Statistiken sowie eine JSON-Schnittstelle (`?json`) für asynchrone JavaScript-Aufrufe.

## Features

* **Multi-Backend-Unterstützung**: Flexible Datenhaltung wahlweise über **SQLite**, **MySQLi**, **JSON** oder **XML**.
* **Datenschutzkonform (DSGVO-orientiert)**:
  * Anonymisierung der IP-Adresse mittels SHA-256-Hashing (keine Speicherung von Klartext-IPs).
  * 24-Stunden-Sperre pro IP-Hash zur Vermeidung von Mehrfachzählungen.
* **Integrierte Analysen**:
  * Aufruf-Statistiken aufgeschlüsselt nach **Tagen**, **Kalenderwochen**, **Monaten** und **Jahren**.
  * **System- & Browser-Erkennung**: Automatisches Parsen von Betriebssystemen (Windows, macOS, Linux, Android, iOS) und Browsern (Chrome, Firefox, Safari, Edge, Opera).
  * Einsicht in die letzten Aufruf-Logs (Zeitpunkt, Referer, User-Agent).
* **Flexibler Aufruf**: Ausgabe als PNG-Grafik, JSON-REST-Response oder HTML-Dashboard.
* **Keine externen Abhängigkeiten**: Läuft direkt mit PHP-Bordmitteln.

## Funktionsweise

Das Skript verarbeitet Anfragen auf drei verschiedene Arten:

1. **Einbindung als Bild (Standard):**
   Wird die Datei normal aufgerufen (z. B. via `<img>`-Tag), registriert das Skript den Besuch und generiert dynamisch ein PNG-Bild, das den aktuellen Gesamtzählerstand anzeigt.
2. **Asynchroner JSON-Aufruf (`?json`):**
   Erlaubt das lautlose Tracking im Hintergrund per JavaScript oder das Abrufen des aktuellen Standes als JSON.
3. **Dashboard-Aufruf (`?analyse`):**
   Wird die URL mit dem Parameter `?analyse` aufgerufen (z. B. `counter.php?analyse`), rendert das Skript ein responsives HTML-Dashboard mit allen gesammelten Statistiken.

## Konfiguration

Die Konfiguration erfolgt direkt am Anfang der Datei `counter.php`. Passe dort einfach die Zuweisung von `$db_type` und gegebenenfalls die Pfade oder Datenbank-Zugangsdaten an:

```php
// Wähle den Datenspeicher: 'sqlite', 'json', 'xml' oder 'mysqli'
$db_type = 'sqlite'; 

// Pfad für dateibasierte Datenspeicher
$data_dir = __DIR__ . '/counter_data';

// Zugangsdaten (nur erforderlich, wenn $db_type = 'mysqli')
$db_host = 'localhost';$db_user = 'dein_user';
$db_pass = 'dein_passwort';$db_name = 'deine_datenbank';
```

## Einbindung im HTML & Frontend

1. Einbindung als Bild (Standard)
Um den Zähler als Bild auf deiner Website anzuzeigen und Aufrufe zu erfassen:
```html
<img src="path/to/counter.php" alt="Besucherzähler" />
```

2. Einbindung per JavaScript (Asynchron / Ohne Bild)
Falls kein Zähler-Bild angezeigt werden soll oder der Counter in einer Web-App/PWA genutzt wird, kann der Aufruf asynchron über den Parameter ?json erfolgen.

Variante A: Lautloser Request mit sendBeacon (Empfohlen für pure Analytics)
Sendet den Zähler-Call performant im Hintergrund, ohne das Ladeverhalten der Seite zu beeinträchtigen:
```html
<script>
  window.addEventListener('load', () => {
    navigator.sendBeacon('path/to/counter.php?json');
  });
</script>
```

Variante B: Request mit fetch() (Um die Zahl im HTML anzuzeigen)
Registriert den Aufruf und gibt das JSON-Ergebnis zurück, um den Zählerstand dynamisch in ein DOM-Element zu schreiben:
```html
<span id="visitor-count">...</span>

<script>
  fetch('path/to/counter.php?json')
    .then(response => response.json())
    .then(data => {
      document.getElementById('visitor-count').textContent = data.total_visits;
    })
    .catch(error => console.error('Counter Fehler:', error));
</script>
```

## Rechtliche Hinweise & Datenschutz
Dieses Skript wurde mit Fokus auf Datenschutz entwickelt. Es verwendet folgende Schutzmaßnahmen:

- IP-Pseudonymisierung: IP-Adressen werden unmittelbar nach dem Empfang mit dem Algorithmus SHA-256 einweg-gehasht. Eine Rückumwandlung in die ursprüngliche IP-Adresse ist technisch ausgeschlossen.
- User-Agent & Referer: Der User-Agent wird ausschließlich zur statistischen Aggregation (Erkennung von Betriebssystem und Browser) verarbeitet.

## Hinweis für Webseitenbetreiber:
Trotz der IP-Anonymisierung empfehle ich, in der Datenschutzerklärung deiner Website darauf hinzuweisen, dass zur Reichweitenmessung und Systemanalyse anonymisierte Aufrufdaten (Zeitstempel, Referer, Browser-Typ, gehashter IP-Wert) verarbeitet und nach einer festgelegten Dauer überschrieben bzw. gelöscht werden.

## Lizenz
Dieses Projekt steht unter der MIT-Lizenz. Du kannst den Code frei nutzen, anpassen und in eigenen Projekten (sowohl privat als auch kommerziell) verwenden.
