## Für WordPress

### 1. Haupt-Plugindatei: `wp-lightweight-counter.php`

Diese Datei ist der Einstiegspunkt für WordPress. Sie kümmert sich um die Aktivierung (Erstellen der custom DB-Tabellen), die Initialisierung der Klassen und das Einbinden des Frontend-Skripts.

```php
<?php
/**
 * Plugin Name:       WP Lightweight Visitor Counter
 * Description:       Ein ultra-leichtgewichtiges, DSGVO-konformes Analytics-Plugin ohne externe Abhängigkeiten.
 * Version:           1.0.0
 * Author:            Dein Name
 * Text Domain:       wp-lightweight-counter
 */

if (!defined('ABSPATH')) {
    exit; // Direktaufruf verhindern
}

define('WPLC_PATH', plugin_dir_path(__FILE__));
define('WPLC_URL', plugin_dir_url(__FILE__));

require_once WPLC_PATH . 'includes/class-tracker.php';
require_once WPLC_PATH . 'includes/class-admin.php';

// ------------------------------------------------------------------
// Aktivierungshook: Datenbank-Tabellen anlegen
// ------------------------------------------------------------------
register_activation_hook(__FILE__, 'wplc_activate_plugin');

function wplc_activate_plugin() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Tabelle für aggregierte Statistiken (Tage, Wochen, Systeme etc.)
    $table_archives = $wpdb->prefix . 'wplc_archives';
    // Tabelle für 24h IP-Sperre
    $table_ips = $wpdb->prefix . 'wplc_ips';
    // Tabelle für Logs
    $table_logs = $wpdb->prefix . 'wplc_logs';

    $sql = "CREATE TABLE $table_archives (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        period_type varchar(20) NOT NULL,
        period_key varchar(50) NOT NULL,
        visits bigint(20) DEFAULT 1,
        PRIMARY KEY  (id),
        UNIQUE KEY type_key (period_type, period_key)
    ) $charset_collate;

    CREATE TABLE $table_ips (
        ip_hash varchar(64) NOT NULL,
        last_visit int(11) NOT NULL,
        PRIMARY KEY  (ip_hash)
    ) $charset_collate;

    CREATE TABLE $table_logs (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        referer varchar(255) DEFAULT '',
        user_agent varchar(255) DEFAULT '',
        visited_at int(11) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// ------------------------------------------------------------------
// Core & Frontend Initialisierung
// ------------------------------------------------------------------
add_action('plugins_loaded', function() {
    $tracker = new WPLC_Tracker();
    $tracker->init();

    if (is_admin()) {
        $admin = new WPLC_Admin();
        $admin->init();
    }
});

// Frontend JavaScript einbinden
add_action('wp_enqueue_scripts', function() {
    // Nur im Frontend abfeuern
    if (is_admin()) return;

    wp_register_script(
        'wplc-tracker',
        WPLC_URL . 'assets/js/tracker.js',
        [],
        '1.0.0',
        true
    );

    // REST-API Endpoint URL an das JS übergeben
    wp_localize_script('wplc-tracker', 'wplcVars', [
        'endpoint' => esc_url_raw(rest_url('wplc/v1/track'))
    ]);

    wp_enqueue_script('wplc-tracker');
});

```
### 2. Die Tracking-Logik & REST-API: `includes/class-tracker.php`

Hier zieht deine bestehende Logik ein. Wir nutzen die offizielle WordPress REST-API (`register_rest_route`), damit das JavaScript das Tracking blitzschnell und ohne Overhead aufrufen kann.

```php
<?php

if (!defined('ABSPATH')) exit;

class WPLC_Tracker {

    public function init() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function register_rest_routes() {
        register_rest_route('wplc/v1', '/track', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_track_request'],
            'permission_callback' => '__return_true', // Öffentlicher Endpoint
        ]);
    }

    public function handle_track_request(WP_REST_Request $request) {
        global $wpdb;

        $ip_raw     = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ip_hash    = hash('sha256', $ip_raw);
        $referer    = substr($_SERVER['HTTP_REFERER'] ?? 'Direct / Unknown', 0, 255);
        $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Agent', 0, 255);
        $now        = time();

        $table_ips      = $wpdb->prefix . 'wplc_ips';
        $table_archives = $wpdb->prefix . 'wplc_archives';
        $table_logs     = $wpdb->prefix . 'wplc_logs';

        // 24h Sperre prüfen
        $last_visit = $wpdb->get_var($wpdb->prepare("SELECT last_visit FROM $table_ips WHERE ip_hash = %s", $ip_hash));

        $is_new_visit = false;

        if ($last_visit === null || ($now - (int)$last_visit) >= 86400) {
            $is_new_visit = true;

            // IP-Timestamp aktualisieren
            $wpdb->replace($table_ips, [
                'ip_hash' => $ip_hash,
                'last_visit' => $now
            ], ['%s', '%d']);

            // User-Agent parsen
            $ua_parsed = $this->parse_user_agent($user_agent);

            $periods = [
                'total'   => 'total',
                'day'     => date('Y-m-d', $now),
                'week'    => date('Y-\WW', $now),
                'month'   => date('Y-m', $now),
                'year'    => date('Y', $now),
                'system'  => $ua_parsed['os'],
                'browser' => $ua_parsed['browser']
            ];

            // Aggregierte Werte in die DB schreiben (Inkrementieren)
            foreach ($periods as $type => $key) {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table_archives (period_type, period_key, visits) 
                     VALUES (%s, %s, 1) 
                     ON DUPLICATE KEY UPDATE visits = visits + 1",
                    $type, $key
                ));
            }

            // Log-Eintrag schreiben
            $wpdb->insert($table_logs, [
                'referer'    => $referer,
                'user_agent' => $user_agent,
                'visited_at' => $now
            ], ['%s', '%s', '%d']);
        }

        // Gesamtzahl abfragen
        $total_visits = (int)$wpdb->get_var("SELECT visits FROM $table_archives WHERE period_type = 'total' AND period_key = 'total'");

        return new WP_REST_Response([
            'status'       => 'success',
            'is_new_visit' => $is_new_visit,
            'total_visits' => $total_visits
        ], 200);
    }

    private function parse_user_agent($ua) {
        $os = 'Unbekannt';
        $browser = 'Unbekannt';

        if (preg_match('/android/i', $ua))              $os = 'Android';
        elseif (preg_match('/iphone|ipad|ipod/i', $ua)) $os = 'iOS';
        elseif (preg_match('/win/i', $ua))              $os = 'Windows';
        elseif (preg_match('/mac/i', $ua))              $os = 'macOS';
        elseif (preg_match('/linux/i', $ua))            $os = 'Linux';

        if (preg_match('/edg/i', $ua))                  $browser = 'MS Edge';
        elseif (preg_match('/opr|opera/i', $ua))        $browser = 'Opera';
        elseif (preg_match('/chrome|crios/i', $ua))     $browser = 'Chrome / Chromium';
        elseif (preg_match('/firefox|fxios/i', $ua))    $browser = 'Firefox';
        elseif (preg_match('/safari/i', $ua))           $browser = 'Safari';

        return ['os' => $os, 'browser' => $browser];
    }
}

```
### 3. Das Admin-Dashboard: `includes/class-admin.php`

Rendert ein sauberes Dashboard direkt im WordPress-Backend unter einem eigenen Menüpunkt.

```php
<?php

if (!defined('ABSPATH')) exit;

class WPLC_Admin {

    public function init() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Visitor Analytics',
            'Visitor Counter',
            'manage_options',
            'wplc-dashboard',
            [$this, 'render_dashboard'],
            'dashicons-chart-bar',
            80
        );
    }

    public function render_dashboard() {
        global $wpdb;

        $table_archives = $wpdb->prefix . 'wplc_archives';
        $table_logs     = $wpdb->prefix . 'wplc_logs';

        $total_visits = (int)$wpdb->get_var("SELECT visits FROM $table_archives WHERE period_type = 'total' AND period_key = 'total'");

        // Daten holen
        $days     = $wpdb->get_results("SELECT period_key, visits FROM $table_archives WHERE period_type = 'day' ORDER BY period_key DESC LIMIT 30", ARRAY_A);
        $systems  = $wpdb->get_results("SELECT period_key, visits FROM $table_archives WHERE period_type = 'system' ORDER BY visits DESC", ARRAY_A);
        $browsers = $wpdb->get_results("SELECT period_key, visits FROM $table_archives WHERE period_type = 'browser' ORDER BY visits DESC", ARRAY_A);
        $logs     = $wpdb->get_results("SELECT * FROM $table_logs ORDER BY id DESC LIMIT 50", ARRAY_A);

        ?>
        <div class="wrap">
            <h1>Visitor Counter Analytics</h1>
            <hr>
            <div style="background: #fff; border: 1px solid #ccc; padding: 15px; display: inline-block; margin-bottom: 20px; border-radius: 4px;">
                <h2 style="margin:0; font-size: 14px; color: #666;">Gesamtaufrufe</h2>
                <span style="font-size: 28px; font-weight: bold; color: #2271b1;"><?= number_format($total_visits) ?></span>
            </div>

            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <!-- Tage -->
                <div style="flex: 1; min-width: 300px; background: #fff; padding: 15px; border: 1px solid #ccc;">
                    <h3>Letzte 30 Tage</h3>
                    <table class="widefat fixed striped">
                        <thead><tr><th>Datum</th><th>Aufrufe</th></tr></thead>
                        <tbody>
                            <?php foreach ($days as $d): ?>
                                <tr><td><?= esc_html($d['period_key']) ?></td><td><strong><?= number_format($d['visits']) ?></strong></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Systeme & Browser -->
                <div style="flex: 1; min-width: 300px; display: flex; flex-direction: column; gap: 20px;">
                    <div style="background: #fff; padding: 15px; border: 1px solid #ccc;">
                        <h3>Betriebssysteme</h3>
                        <table class="widefat fixed striped">
                            <thead><tr><th>System</th><th>Aufrufe</th></tr></thead>
                            <tbody>
                                <?php foreach ($systems as $s): ?>
                                    <tr><td><?= esc_html($s['period_key']) ?></td><td><strong><?= number_format($s['visits']) ?></strong></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="background: #fff; padding: 15px; border: 1px solid #ccc;">
                        <h3>Browser</h3>
                        <table class="widefat fixed striped">
                            <thead><tr><th>Browser</th><th>Aufrufe</th></tr></thead>
                            <tbody>
                                <?php foreach ($browsers as $b): ?>
                                    <tr><td><?= esc_html($b['period_key']) ?></td><td><strong><?= number_format($b['visits']) ?></strong></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Logs -->
            <div style="background: #fff; padding: 15px; border: 1px solid #ccc; margin-top: 20px;">
                <h3>Letzte Logs</h3>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 160px;">Zeitpunkt</th>
                            <th>Referer</th>
                            <th>User Agent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td><?= date('d.m.Y H:i:s', $l['visited_at']) ?></td>
                                <td style="word-break: break-all;"><?= esc_html($l['referer']) ?></td>
                                <td style="word-break: break-all;"><?= esc_html($l['user_agent']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}

```

### 4. Das Frontend JavaScript: `assets/js/tracker.js`

Ein winziges Skript, das via `navigator.sendBeacon` völlig lautlos im Hintergrund trackt. Leg diese Datei im Unterordner `assets/js/` an:

```javascript
window.addEventListener('load', function() {
    if (typeof wplcVars !== 'undefined' && wplcVars.endpoint) {
        // Asynchrones, nicht-blockierendes Tracking via sendBeacon
        navigator.sendBeacon(wplcVars.endpoint);
    }
});

```

### Was zeichnet diese WordPress-Lösung aus?

1. **Zero Impact auf Seitenladezeit:** Es bremst WP null aus, weil das JS erst nach dem `load`-Event der Seite per `sendBeacon` im Hintergrund funkt.
2. **24h-Sperre & DSGVO:** IPs werden als SHA-256 Hash verarbeitet und nicht im Klartext gespeichert.
3. **Kompatibel mit Caching-Plugins:** Egal ob WP Rocket, LiteSpeed oder Nginx FastCGI Cache – der JS-Call feuert dynamisch an die REST-API.
