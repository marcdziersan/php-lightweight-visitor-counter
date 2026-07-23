<?php
// Fehlerberichterstattung für Bild-Ausgabe unterdrücken
error_reporting(0);

// ==========================================
// KONFIGURATION
// ==========================================
// Optionen: 'sqlite', 'json', 'xml', 'mysqli'
$db_type   = 'sqlite'; 

// Pfade für dateibasierte Datenspeicher
$data_dir  = __DIR__ . '/counter_data';
$db_file   = $data_dir . '/counter.db';     // SQLite
$json_file = $data_dir . '/counter.json';   // JSON
$xml_file  = $data_dir . '/counter.xml';    // XML

// MySQLi Zugangsdaten (nur falls $db_type = 'mysqli')
$db_host   = 'localhost';
$db_user   = 'dein_user';
$db_pass   = 'dein_passwort';
$db_name   = 'deine_datenbank';

// ==========================================
// HILFSFUNKTIONEN
// ==========================================
function renderErrorImage(string $msg, int $httpCode = 500): void {
    http_response_code($httpCode);
    header('Content-Type: image/png');
    header('Cache-Control: no-cache, must-revalidate');

    $width  = max(imagefontwidth(3) * strlen($msg) + 16, 110);
    $height = imagefontheight(3) + 12;
    
    $img   = imagecreatetruecolor($width, $height);
    $bg    = imagecolorallocate($img, 180, 40, 40);
    $text  = imagecolorallocate($img, 255, 255, 255);

    imagefill($img, 0, 0, $bg);
    imagestring($img, 3, 8, 6, $msg, $text);
    
    imagepng($img);
    imagedestroy($img);
    exit;
}

function parseUserAgent(string $ua): array {
    $os = 'Unbekannt';
    $browser = 'Unbekannt';

    // 1. Betriebssystem
    if (preg_match('/android/i', $ua))              $os = 'Android';
    elseif (preg_match('/iphone|ipad|ipod/i', $ua)) $os = 'iOS';
    elseif (preg_match('/win/i', $ua))              $os = 'Windows';
    elseif (preg_match('/mac/i', $ua))              $os = 'macOS';
    elseif (preg_match('/linux/i', $ua))            $os = 'Linux';

    // 2. Browser (Reihenfolge ist wichtig wegen Vendor-Strings!)
    if (preg_match('/edg/i', $ua))                  $browser = 'MS Edge';
    elseif (preg_match('/opr|opera/i', $ua))        $browser = 'Opera';
    elseif (preg_match('/chrome|crios/i', $ua))     $browser = 'Chrome / Chromium';
    elseif (preg_match('/firefox|fxios/i', $ua))    $browser = 'Firefox';
    elseif (preg_match('/safari/i', $ua))           $browser = 'Safari';

    return ['os' => $os, 'browser' => $browser];
}

// Ordner für lokale Dateien anlegen
if (in_array($db_type, ['sqlite', 'json', 'xml']) && !is_dir($data_dir)) {
    if (!@mkdir($data_dir, 0755, true)) {
        renderErrorImage('500 Dir Create Error', 500);
    }
}

// ==========================================
// DATEN ERFASSEN & INITIALISIEREN
// ==========================================
$ip_raw     = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$ip_hash    = hash('sha256', $ip_raw);
$referer    = substr($_SERVER['HTTP_REFERER'] ?? 'Direct / Unknown', 0, 255);
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Agent', 0, 255);
$now        = time();

$ua_parsed  = parseUserAgent($user_agent);

$day_key   = date('Y-m-d', $now);
$week_key  = date('Y-\WW', $now);
$month_key = date('Y-m', $now);
$year_key  = date('Y', $now);

$total_visits = 0;
$is_new_visit = false;
$stats_data   = [
    'total'   => 0, 
    'days'    => [], 
    'weeks'   => [], 
    'months'  => [], 
    'years'   => [], 
    'systems' => [], 
    'browsers'=> [], 
    'logs'    => []
];

try {
    // ------------------------------------------
    // 1. SQLITE
    // ------------------------------------------
    if ($db_type === 'sqlite') {
        if (!extension_loaded('pdo_sqlite')) renderErrorImage('500 SQLite Missing', 500);

        $pdo = new PDO('sqlite:' . $db_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE IF NOT EXISTS counter_stats (id INTEGER PRIMARY KEY, total_visits INTEGER DEFAULT 0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS counter_ips (ip_hash TEXT PRIMARY KEY, last_visit INTEGER)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS counter_archives (period_type TEXT, period_key TEXT, visits INTEGER DEFAULT 0, PRIMARY KEY(period_type, period_key))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS counter_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, ip_hash TEXT, referer TEXT, user_agent TEXT, visited_at INTEGER)");

        $pdo->exec("INSERT OR IGNORE INTO counter_stats (id, total_visits) VALUES (1, 0)");

        $stmt = $pdo->prepare("SELECT last_visit FROM counter_ips WHERE ip_hash = ?");
        $stmt->execute([$ip_hash]);
        $last_visit = $stmt->fetchColumn();

        if ($last_visit === false || ($now - $last_visit) >= 86400) {
            $is_new_visit = true;
            if ($last_visit === false) {
                $pdo->prepare("INSERT INTO counter_ips (ip_hash, last_visit) VALUES (?, ?)")->execute([$ip_hash, $now]);
            } else {
                $pdo->prepare("UPDATE counter_ips SET last_visit = ? WHERE ip_hash = ?")->execute([$now, $ip_hash]);
            }

            $pdo->exec("UPDATE counter_stats SET total_visits = total_visits + 1 WHERE id = 1");

            // Archive Inkrementieren
            $periods = [
                'day'     => $day_key, 
                'week'    => $week_key, 
                'month'   => $month_key, 
                'year'    => $year_key,
                'system'  => $ua_parsed['os'],
                'browser' => $ua_parsed['browser']
            ];
            $archStmt = $pdo->prepare("INSERT INTO counter_archives (period_type, period_key, visits) VALUES (?, ?, 1) ON CONFLICT(period_type, period_key) DO UPDATE SET visits = visits + 1");
            foreach ($periods as $type => $key) {
                $archStmt->execute([$type, $key]);
            }

            $log = $pdo->prepare("INSERT INTO counter_logs (ip_hash, referer, user_agent, visited_at) VALUES (?, ?, ?, ?)");
            $log->execute([$ip_hash, $referer, $user_agent, $now]);
        }

        $total_visits = (int)$pdo->query("SELECT total_visits FROM counter_stats WHERE id = 1")->fetchColumn();

        if (isset($_GET['analyse'])) {
            $stats_data['total'] = $total_visits;
            $rows = $pdo->query("SELECT period_type, period_key, visits FROM counter_archives ORDER BY visits DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $type = $r['period_type'];
                if ($type === 'system') {
                    $stats_data['systems'][$r['period_key']] = (int)$r['visits'];
                } elseif ($type === 'browser') {
                    $stats_data['browsers'][$r['period_key']] = (int)$r['visits'];
                } else {
                    $stats_data[$type . 's'][$r['period_key']] = (int)$r['visits'];
                }
            }
            $stats_data['logs'] = $pdo->query("SELECT * FROM counter_logs ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        }

    // ------------------------------------------
    // 2. MYSQLI
    // ------------------------------------------
    } elseif ($db_type === 'mysqli') {
        if (!extension_loaded('mysqli')) renderErrorImage('500 MySQLi Missing', 500);

        $mysqli = @new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($mysqli->connect_error) renderErrorImage('500 DB Conn Error', 500);

        $mysqli->query("CREATE TABLE IF NOT EXISTS counter_stats (id INT PRIMARY KEY, total_visits INT DEFAULT 0)");
        $mysqli->query("CREATE TABLE IF NOT EXISTS counter_ips (ip_hash VARCHAR(64) PRIMARY KEY, last_visit INT)");
        $mysqli->query("CREATE TABLE IF NOT EXISTS counter_archives (period_type VARCHAR(10), period_key VARCHAR(50), visits INT DEFAULT 0, PRIMARY KEY(period_type, period_key))");
        $mysqli->query("CREATE TABLE IF NOT EXISTS counter_logs (id INT AUTO_INCREMENT PRIMARY KEY, ip_hash VARCHAR(64), referer VARCHAR(255), user_agent VARCHAR(255), visited_at INT)");

        $mysqli->query("INSERT IGNORE INTO counter_stats (id, total_visits) VALUES (1, 0)");

        $stmt = $mysqli->prepare("SELECT last_visit FROM counter_ips WHERE ip_hash = ?");
        $stmt->bind_param("s", $ip_hash);
        $stmt->execute();
        $res = $stmt->get_result();
        $last_visit = $res->num_rows > 0 ? $res->fetch_assoc()['last_visit'] : false;

        if ($last_visit === false || ($now - $last_visit) >= 86400) {
            $is_new_visit = true;
            if ($last_visit === false) {
                $ins = $mysqli->prepare("INSERT INTO counter_ips (ip_hash, last_visit) VALUES (?, ?)");
                $ins->bind_param("si", $ip_hash, $now);
                $ins->execute();
            } else {
                $upd = $mysqli->prepare("UPDATE counter_ips SET last_visit = ? WHERE ip_hash = ?");
                $upd->bind_param("is", $now, $ip_hash);
                $upd->execute();
            }

            $mysqli->query("UPDATE counter_stats SET total_visits = total_visits + 1 WHERE id = 1");

            $periods = [
                'day'     => $day_key, 
                'week'    => $week_key, 
                'month'   => $month_key, 
                'year'    => $year_key,
                'system'  => $ua_parsed['os'],
                'browser' => $ua_parsed['browser']
            ];
            $archStmt = $mysqli->prepare("INSERT INTO counter_archives (period_type, period_key, visits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE visits = visits + 1");
            foreach ($periods as $type => $key) {
                $archStmt->bind_param("ss", $type, $key);
                $archStmt->execute();
            }

            $log = $mysqli->prepare("INSERT INTO counter_logs (ip_hash, referer, user_agent, visited_at) VALUES (?, ?, ?, ?)");
            $log->bind_param("sssi", $ip_hash, $referer, $user_agent, $now);
            $log->execute();
        }

        $res_count = $mysqli->query("SELECT total_visits FROM counter_stats WHERE id = 1");
        $total_visits = (int)$res_count->fetch_assoc()['total_visits'];

        if (isset($_GET['analyse'])) {
            $stats_data['total'] = $total_visits;
            $res = $mysqli->query("SELECT period_type, period_key, visits FROM counter_archives ORDER BY visits DESC");
            while ($r = $res->fetch_assoc()) {
                $type = $r['period_type'];
                if ($type === 'system') {
                    $stats_data['systems'][$r['period_key']] = (int)$r['visits'];
                } elseif ($type === 'browser') {
                    $stats_data['browsers'][$r['period_key']] = (int)$r['visits'];
                } else {
                    $stats_data[$type . 's'][$r['period_key']] = (int)$r['visits'];
                }
            }
            $res_logs = $mysqli->query("SELECT * FROM counter_logs ORDER BY id DESC LIMIT 50");
            while ($l = $res_logs->fetch_assoc()) {
                $stats_data['logs'][] = $l;
            }
        }
        $mysqli->close();

    // ------------------------------------------
    // 3. JSON DATENHALTUNG
    // ------------------------------------------
    } elseif ($db_type === 'json') {
        $data = [
            'total_visits' => 0, 
            'ips' => [], 
            'archives' => ['days' => [], 'weeks' => [], 'months' => [], 'years' => [], 'systems' => [], 'browsers' => []], 
            'logs' => []
        ];

        if (file_exists($json_file)) {
            $parsed = json_decode(file_get_contents($json_file), true);
            if (is_array($parsed)) $data = array_merge_recursive($data, $parsed);
        }

        $last_visit = $data['ips'][$ip_hash] ?? null;

        if ($last_visit === null || ($now - $last_visit) >= 86400) {
            $is_new_visit = true;
            $data['ips'][$ip_hash] = $now;
            $data['total_visits']++;

            $data['archives']['days'][$day_key]                  = ($data['archives']['days'][$day_key] ?? 0) + 1;
            $data['archives']['weeks'][$week_key]                = ($data['archives']['weeks'][$week_key] ?? 0) + 1;
            $data['archives']['months'][$month_key]              = ($data['archives']['months'][$month_key] ?? 0) + 1;
            $data['archives']['years'][$year_key]                = ($data['archives']['years'][$year_key] ?? 0) + 1;
            $data['archives']['systems'][$ua_parsed['os']]       = ($data['archives']['systems'][$ua_parsed['os']] ?? 0) + 1;
            $data['archives']['browsers'][$ua_parsed['browser']] = ($data['archives']['browsers'][$ua_parsed['browser']] ?? 0) + 1;

            array_unshift($data['logs'], [
                'ip_hash' => $ip_hash, 'referer' => $referer, 'user_agent' => $user_agent, 'visited_at' => $now
            ]);
            $data['logs'] = array_slice($data['logs'], 0, 100);

            file_put_contents($json_file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        }

        $total_visits = (int)$data['total_visits'];
        if (isset($_GET['analyse'])) {
            $stats_data['total']    = $total_visits;
            $stats_data['days']     = array_reverse($data['archives']['days'] ?? []);
            $stats_data['weeks']    = array_reverse($data['archives']['weeks'] ?? []);
            $stats_data['months']   = array_reverse($data['archives']['months'] ?? []);
            $stats_data['years']    = array_reverse($data['archives']['years'] ?? []);
            $stats_data['systems']  = $data['archives']['systems'] ?? [];
            $stats_data['browsers'] = $data['archives']['browsers'] ?? [];
            $stats_data['logs']     = $data['logs'] ?? [];
            
            arsort($stats_data['systems']);
            arsort($stats_data['browsers']);
        }

    // ------------------------------------------
    // 4. XML DATENHALTUNG
    // ------------------------------------------
    } elseif ($db_type === 'xml') {
        if (!extension_loaded('simplexml')) renderErrorImage('500 SimpleXML Missing', 500);

        if (file_exists($xml_file)) $xml = @simplexml_load_file($xml_file);

        if (empty($xml)) {
            $xml = new SimpleXMLElement('<counter></counter>');
            $xml->addChild('total_visits', '0');
            $xml->addChild('ips');
            $xml->addChild('archives');
            $xml->archives->addChild('days');
            $xml->archives->addChild('weeks');
            $xml->archives->addChild('months');
            $xml->archives->addChild('years');
            $xml->archives->addChild('systems');
            $xml->archives->addChild('browsers');
            $xml->addChild('logs');
        }

        $last_visit = null;
        $ip_node    = null;

        foreach ($xml->ips->ip as $item) {
            if ((string)$item['hash'] === $ip_hash) {
                $last_visit = (int)$item['last_visit'];
                $ip_node = $item;
                break;
            }
        }

        if ($last_visit === null || ($now - $last_visit) >= 86400) {
            $is_new_visit = true;
            if ($last_visit === null) {
                $new_ip = $xml->ips->addChild('ip');
                $new_ip->addAttribute('hash', $ip_hash);
                $new_ip->addAttribute('last_visit', (string)$now);
            } else {
                $ip_node['last_visit'] = (string)$now;
            }

            $xml->total_visits = (int)$xml->total_visits + 1;

            $incXmlArch = function($parent, $key) {
                if (!$parent) return;
                foreach ($parent->entry as $e) {
                    if ((string)$e['key'] === $key) {
                        $e['visits'] = (int)$e['visits'] + 1;
                        return;
                    }
                }
                $n = $parent->addChild('entry');
                $n->addAttribute('key', $key);
                $n->addAttribute('visits', '1');
            };

            $incXmlArch($xml->archives->days, $day_key);
            $incXmlArch($xml->archives->weeks, $week_key);
            $incXmlArch($xml->archives->months, $month_key);
            $incXmlArch($xml->archives->years, $year_key);
            $incXmlArch($xml->archives->systems, $ua_parsed['os']);
            $incXmlArch($xml->archives->browsers, $ua_parsed['browser']);

            $log = $xml->logs->addChild('log');
            $log->addChild('ip_hash', $ip_hash);
            $log->addChild('referer', htmlspecialchars($referer));
            $log->addChild('user_agent', htmlspecialchars($user_agent));
            $log->addChild('visited_at', (string)$now);

            $dom = new DOMDocument('1.0');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            $dom->loadXML($xml->asXML());
            file_put_contents($xml_file, $dom->saveXML(), LOCK_EX);
        }

        $total_visits = (int)$xml->total_visits;

        if (isset($_GET['analyse'])) {
            $stats_data['total'] = $total_visits;
            $getXmlArch = function($parent) {
                $arr = [];
                if ($parent) {
                    foreach ($parent->entry as $e) {
                        $arr[(string)$e['key']] = (int)$e['visits'];
                    }
                }
                return $arr;
            };
            $stats_data['days']     = array_reverse($getXmlArch($xml->archives->days));
            $stats_data['weeks']    = array_reverse($getXmlArch($xml->archives->weeks));
            $stats_data['months']   = array_reverse($getXmlArch($xml->archives->months));
            $stats_data['years']    = array_reverse($getXmlArch($xml->archives->years));
            $stats_data['systems']  = $getXmlArch($xml->archives->systems);
            $stats_data['browsers'] = $getXmlArch($xml->archives->browsers);

            arsort($stats_data['systems']);
            arsort($stats_data['browsers']);

            if ($xml->logs) {
                foreach ($xml->logs->log as $l) {
                    array_unshift($stats_data['logs'], [
                        'ip_hash' => (string)$l->ip_hash,
                        'referer' => (string)$l->referer,
                        'user_agent' => (string)$l->user_agent,
                        'visited_at' => (int)$l->visited_at
                    ]);
                }
            }
        }
    } else {
        renderErrorImage('400 Invalid DB Type', 400);
    }

} catch (Exception $e) {
    if (!isset($_GET['analyse'])) renderErrorImage('500 Exception Raised', 500);
    else die("Fehler beim Laden der Daten: " . htmlspecialchars($e->getMessage()));
}

// ==========================================
// RENDER: DASHBOARD (?analyse), JSON-API (?json) ODER BILD
// ==========================================

// Cross-Origin Requests erlauben (falls JS von anderer Domain/Subdomain aufruft)
header('Access-Control-Allow-Origin: *');

// Option A: Dashboard-Aufruf
if (isset($_GET['analyse'])) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Counter Statistik & Analyse</title>
        <style>
            :root { --bg: #121214; --card: #1e1e24; --accent: #00ff88; --text: #e0e0e0; --border: #33333d; }
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }
            .container { max-width: 900px; margin: 0 auto; }
            h1 { font-size: 24px; color: #fff; margin-bottom: 20px; }
            .badge { background: #2a2a35; border: 1px solid var(--border); border-radius: 8px; padding: 15px 20px; display: inline-block; margin-bottom: 20px; }
            .badge span { color: var(--accent); font-weight: bold; font-size: 20px; }
            
            /* Tabs Header */
            .tabs { display: flex; border-bottom: 2px solid var(--border); gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
            .tab-btn { background: none; border: none; color: #888; font-size: 16px; padding: 10px 20px; cursor: pointer; transition: 0.2s; border-bottom: 2px solid transparent; margin-bottom: -2px; }
            .tab-btn:hover { color: #fff; }
            .tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); font-weight: bold; }
            
            /* Tabs Content */
            .tab-content { display: none; background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; }
            .tab-content.active { display: block; }
            
            table { width: 100%; border-collapse: collapse; text-align: left; }
            th, td { padding: 10px; border-bottom: 1px solid var(--border); font-size: 14px; }
            th { color: #aaa; text-transform: uppercase; font-size: 12px; }
            tr:hover { background: rgba(255,255,255,0.02); }
            td.full-text {
                word-break: break-word;
                white-space: normal;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Visitor Counter Analyse</h1>
            <div class="badge">Gesamtaufrufe: <span><?= number_format($stats_data['total']) ?></span></div>

            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('tab-day', event)">Tage</button>
                <button class="tab-btn" onclick="switchTab('tab-week', event)">Wochen (KW)</button>
                <button class="tab-btn" onclick="switchTab('tab-month', event)">Monate</button>
                <button class="tab-btn" onclick="switchTab('tab-year', event)">Jahre</button>
                <button class="tab-btn" onclick="switchTab('tab-systems', event)">Systeme</button>
                <button class="tab-btn" onclick="switchTab('tab-browsers', event)">Browser</button>
                <button class="tab-btn" onclick="switchTab('tab-logs', event)">Letzte Logs</button>
            </div>

            <?php
            $renderTable = function($arr, $keyLabel) {
                if (empty($arr)) { echo "<p>Keine Einträge vorhanden.</p>"; return; }
                echo "<table><thead><tr><th>{$keyLabel}</th><th>Aufrufe</th></tr></thead><tbody>";
                foreach ($arr as $key => $count) {
                    echo "<tr><td>" . htmlspecialchars($key) . "</td><td><strong>" . number_format($count) . "</strong></td></tr>";
                }
                echo "</tbody></table>";
            };
            ?>

            <div id="tab-day" class="tab-content active"><?php $renderTable($stats_data['days'], 'Datum (Y-m-d)'); ?></div>
            <div id="tab-week" class="tab-content"><?php $renderTable($stats_data['weeks'], 'Kalenderwoche (Y-KW)'); ?></div>
            <div id="tab-month" class="tab-content"><?php $renderTable($stats_data['months'], 'Monat (Y-m)'); ?></div>
            <div id="tab-year" class="tab-content"><?php $renderTable($stats_data['years'], 'Jahr (Y)'); ?></div>
            <div id="tab-systems" class="tab-content"><?php $renderTable($stats_data['systems'], 'Betriebssystem'); ?></div>
            <div id="tab-browsers" class="tab-content"><?php $renderTable($stats_data['browsers'], 'Browser'); ?></div>

            <div id="tab-logs" class="tab-content">
                <?php if (empty($stats_data['logs'])): ?>
                    <p>Keine Logs vorhanden.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 180px;">Zeitpunkt</th>
                                <th>Referer</th>
                                <th>User Agent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats_data['logs'] as $log): ?>
                                <tr>
                                    <td style="white-space: nowrap;"><?= date('d.m.Y H:i:s', $log['visited_at']) ?></td>
                                    <td class="full-text"><?= htmlspecialchars($log['referer']) ?></td>
                                    <td class="full-text"><?= htmlspecialchars($log['user_agent']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <script>
            function switchTab(tabId, evt) {
                document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
                document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
                
                document.getElementById(tabId).classList.add('active');
                if (evt && evt.currentTarget) {
                    evt.currentTarget.classList.add('active');
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Option B: Anfrage per JavaScript / API (?json)
if (isset($_GET['json'])) {
    http_response_code($is_new_visit ? 201 : 200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    echo json_encode([
        'status'       => 'success',
        'is_new_visit' => $is_new_visit,
        'total_visits' => $total_visits
    ]);
    exit;
}

// Option C: Standard Bild-Generierung (GD Lib)
if ($is_new_visit) {
    http_response_code(201);
} else {
    http_response_code(200);
}

header('Content-Type: image/png');
header('Cache-Control: no-cache, must-revalidate');

$text     = sprintf('%06d', $total_visits);
$fontSize = 5;
$width    = imagefontwidth($fontSize) * strlen($text) + 16;
$height   = imagefontheight($fontSize) + 10;

$img = imagecreatetruecolor($width, $height);

$bgColor     = imagecolorallocate($img, 30, 30, 35);
$textColor   = imagecolorallocate($img, 0, 255, 136);
$borderColor = imagecolorallocate($img, 60, 60, 70);

imagefill($img, 0, 0, $bgColor);
imagerectangle($img, 0, 0, $width - 1, $height - 1, $borderColor);

$x = ($width - (imagefontwidth($fontSize) * strlen($text))) / 2;
$y = ($height - imagefontheight($fontSize)) / 2;

imagestring($img, $fontSize, (int)$x, (int)$y, $text, $textColor);

imagepng($img);
imagedestroy($img);
