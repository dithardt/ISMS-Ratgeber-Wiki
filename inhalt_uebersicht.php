<?php
/**
 * Wiki-Seite "Inhalt-Übersicht"
 *
 * Funktionen:
 * - Liest alle Inhaltsseiten aus mehreren Namensräumen per MediaWiki-API
 * - Ermittelt Titel, Länge, letzte Änderung, letzten Bearbeitenden
 * - Optional: kurzer Textauszug über extracts
 * - Erstellt/aktualisiert die Wiki-Seite "Inhalt-Übersicht"
 *
 * Voraussetzungen:
 * - BotPassword oder API-fähiger Account mit Bearbeitungsrechten
 * - cURL in PHP aktiviert
 *
 * Nutzung:
 * 1. Konfiguration unten anpassen
 * 2. CLI: php mediawiki_inhalt_uebersicht.php
 *
 * Sicherheit:
 * - Zugangsdaten nicht im Webroot ablegen
 * - Script bevorzugt per CLI ausführen
 */

// =========================
// Konfiguration
// =========================
$apiEndpoint = 'https://wiki.isms-ratgeber.info/api.php';
$articleBase = 'https://wiki.isms-ratgeber.info/wiki/';
$targetPageTitle = 'Inhalt-Übersicht';
$username = 'Dirk@inhaltsuebersicht';
$botPassword = 't72c5ne88fpfs6jgnu75ktn6mj219lc2';
$summary = 'Automatisch erzeugte Inhaltsübersicht aktualisiert';

$namespaces = [
    0,
    3000,
    3010,
    3020,
    3030,
    3040,
];

$withExtracts = false;
$extractLength = 180;
$excludeRedirects = true;
$markBotEdit = true;
$minorEdit = false;
$dryRun = false;

$namespaceLabels = [
    0 => 'Hauptnamensraum',
    3000 => 'Grundschutz',
    3010 => 'Datenschutz',
    3020 => 'Notfallmanagement',
    3030 => 'KI',
    3040 => 'KMU',
];

function shorten(?string $text, int $maxLen = 180): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    if (mb_strlen($text) <= $maxLen) {
        return $text;
    }

    return mb_substr($text, 0, $maxLen - 1) . '…';
}

function wikiEscape(string $text): string
{
    $text = str_replace(["\r", "\n"], ' ', $text);
    return str_replace('|', '{{!}}', $text);
}

function buildArticleUrl(string $articleBase, string $title): string
{
    return $articleBase . str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', $title)));
}

function getDisplayTitle(string $fullTitle): string
{
    $parts = explode(':', $fullTitle, 2);
    return count($parts) === 2 ? $parts[1] : $fullTitle;
}

function httpRequest(string $url, ?array $postFields = null, array &$cookieJar = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('cURL konnte nicht initialisiert werden.');
    }

    $headers = [
        'User-Agent: ISMS-Ratgeber-Inhalt-Uebersicht/1.0',
        'Accept: application/json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
    ]);

    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }

    if (!empty($cookieJar)) {
        $cookieHeader = [];
        foreach ($cookieJar as $name => $value) {
            $cookieHeader[] = $name . '=' . $value;
        }
        curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $cookieHeader));
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL-Fehler: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerText = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    if (preg_match_all('/^Set-Cookie:\s*([^=\s]+)=([^;]*)/mi', $headerText, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $cookieJar[$match[1]] = $match[2];
        }
    }

    if ($status >= 400) {
        throw new RuntimeException("HTTP-Fehler {$status}: {$body}");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Ungültige JSON-Antwort: ' . $body);
    }

    return $data;
}

function apiGet(array &$cookieJar, string $apiEndpoint, array $params): array
{
    $url = $apiEndpoint . '?' . http_build_query($params);
    return httpRequest($url, null, $cookieJar);
}

function apiPost(array &$cookieJar, string $apiEndpoint, array $params): array
{
    return httpRequest($apiEndpoint, $params, $cookieJar);
}

function getLoginToken(array &$cookieJar, string $apiEndpoint): string
{
    $data = apiGet($cookieJar, $apiEndpoint, [
        'action' => 'query',
        'meta' => 'tokens',
        'type' => 'login',
        'format' => 'json',
    ]);

    $token = $data['query']['tokens']['logintoken'] ?? null;
    if (!$token) {
        throw new RuntimeException('Login-Token konnte nicht abgerufen werden.');
    }

    return $token;
}

function login(array &$cookieJar, string $apiEndpoint, string $username, string $botPassword): void
{
    $loginToken = getLoginToken($cookieJar, $apiEndpoint);

    $data = apiPost($cookieJar, $apiEndpoint, [
        'action' => 'login',
        'lgname' => $username,
        'lgpassword' => $botPassword,
        'lgtoken' => $loginToken,
        'format' => 'json',
    ]);

    $result = $data['login']['result'] ?? '';
    if ($result !== 'Success') {
        throw new RuntimeException('Login fehlgeschlagen: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}

function getCsrfToken(array &$cookieJar, string $apiEndpoint): string
{
    $data = apiGet($cookieJar, $apiEndpoint, [
        'action' => 'query',
        'meta' => 'tokens',
        'format' => 'json',
    ]);

    $token = $data['query']['tokens']['csrftoken'] ?? null;
    if (!$token) {
        throw new RuntimeException('CSRF-Token konnte nicht abgerufen werden.');
    }

    return $token;
}

function fetchPagesForNamespace(
    string $apiEndpoint,
    string $articleBase,
    int $namespace,
    bool $withExtracts,
    int $extractLength,
    bool $excludeRedirects
): array {
    $cookieJar = [];
    $titles = [];
    $apContinue = null;

    do {
        $params = [
            'action' => 'query',
            'format' => 'json',
            'formatversion' => '2',
            'list' => 'allpages',
            'apnamespace' => $namespace,
            'aplimit' => 'max',
        ];

        if ($excludeRedirects) {
            $params['apfilterredir'] = 'nonredirects';
        }

        if ($apContinue !== null) {
            $params['apcontinue'] = $apContinue;
        }

        $data = apiGet($cookieJar, $apiEndpoint, $params);

        if (!empty($data['query']['allpages'])) {
            foreach ($data['query']['allpages'] as $page) {
                if (!empty($page['title'])) {
                    $titles[] = $page['title'];
                }
            }
        }

        $apContinue = $data['continue']['apcontinue'] ?? null;
    } while ($apContinue !== null);

    if (empty($titles)) {
        return [];
    }

    $pages = [];
    $chunks = array_chunk($titles, 50);

    foreach ($chunks as $chunk) {
        $params = [
            'action' => 'query',
            'format' => 'json',
            'formatversion' => '2',
            'prop' => 'info|revisions|description|pageprops',
            'titles' => implode('|', $chunk),
            'inprop' => 'url',
            'rvprop' => 'timestamp|user',
        ];

        if ($withExtracts) {
            $params['prop'] .= '|extracts';
            $params['exintro'] = 1;
            $params['explaintext'] = 1;
            $params['exchars'] = $extractLength;
        }

        $data = apiGet($cookieJar, $apiEndpoint, $params);

        if (empty($data['query']['pages'])) {
            continue;
        }

        foreach ($data['query']['pages'] as $page) {
            if (!empty($page['missing']) || !empty($page['invalid'])) {
                continue;
            }

            $title = $page['title'] ?? '';
            $len = $page['length'] ?? 0;

            $lastChanged = '';
            $author = '';
            if (!empty($page['revisions'][0])) {
                $lastChanged = $page['revisions'][0]['timestamp'] ?? '';
                $author = $page['revisions'][0]['user'] ?? '';
            }

            $summary = '';
            if (isset($page['description']) && trim((string) $page['description']) !== '') {
                $summary = trim((string) $page['description']);
            } elseif (isset($page['pageprops']['shortdesc']) && trim((string) $page['pageprops']['shortdesc']) !== '') {
                $summary = trim((string) $page['pageprops']['shortdesc']);
            } elseif ($withExtracts && isset($page['extract']) && trim((string) $page['extract']) !== '') {
                $summary = trim((string) $page['extract']);
            }

            $summary = shorten($summary, $extractLength);

            $pages[] = [
                'title' => $title,
                'display_title' => getDisplayTitle($title),
                'url' => $page['fullurl'] ?? buildArticleUrl($articleBase, $title),
                'length' => (int) $len,
                'last_changed' => $lastChanged,
                'author' => $author,
                'summary' => $summary,
                'ns' => $namespace,
            ];
        }
    }

    usort($pages, static fn(array $a, array $b): int => strcmp($a['display_title'], $b['display_title']));
    return $pages;
}

function fetchPages(
    string $apiEndpoint,
    string $articleBase,
    array $namespaces,
    bool $withExtracts,
    int $extractLength,
    bool $excludeRedirects
): array {
    $result = [];

    foreach ($namespaces as $ns) {
        $result[(int) $ns] = fetchPagesForNamespace(
            $apiEndpoint,
            $articleBase,
            (int) $ns,
            $withExtracts,
            $extractLength,
            $excludeRedirects
        );
    }

    return $result;
}

function buildWikiText(array $pagesByNamespace, array $namespaceLabels): string
{
    $total = 0;
    foreach ($pagesByNamespace as $pages) {
        $total += count($pages);
    }

    $out = [];
    $out[] = '__NOINDEX__';
    $out[] = '__NOEDITSECTION__';
    $out[] = '{{SHORTDESC:Diese Seite wird täglich automatisch per API-Skript erzeugt und listet alle Inhaltsseiten des Wiki nach Namensräumen auf.}}';
    $out[] = ';Stand';
    $out[] = ':' . gmdate('Y-m-d H:i') . ' (UTC)';
    $out[] = '';
    $out[] = ';Anzahl Seiten gesamt';
    $out[] = ':' . (string) $total;
    $out[] = '';

    foreach ($pagesByNamespace as $ns => $pages) {
        $label = $namespaceLabels[$ns] ?? ('Namensraum ' . $ns);

        $out[] = '== ' . wikiEscape($label) . ' ==';
        $out[] = '';
        $out[] = ';Anzahl Seiten';
        $out[] = ':' . (string) count($pages);
        $out[] = '';
        $out[] = '{| class="wikitable sortable"';
        $out[] = '! Seite !! Länge !! Letzte Änderung !! Letzter Autor !! Kurzbeschreibung';

        foreach ($pages as $p) {
            $date = $p['last_changed'] !== ''
                ? gmdate('Y-m-d H:i:s', strtotime($p['last_changed'])) . ' UTC'
                : '';

            $out[] = '|-';
            $out[] = '| [[' . $p['title'] . '|' . wikiEscape($p['display_title']) . ']]'
                . ' || ' . (string) $p['length']
                . ' || ' . wikiEscape($date)
                . ' || ' . wikiEscape((string) $p['author'])
                . ' || ' . wikiEscape((string) $p['summary']);
        }

        $out[] = '|}';
        $out[] = '';
    }

    return implode("\n", $out) . "\n";
}

function saveWikiPage(array &$cookieJar, string $apiEndpoint, string $title, string $text, string $summary, bool $markBotEdit, bool $minorEdit): array
{
    $csrfToken = getCsrfToken($cookieJar, $apiEndpoint);

    $params = [
        'action' => 'edit',
        'title' => $title,
        'text' => $text,
        'summary' => $summary,
        'token' => $csrfToken,
        'format' => 'json',
    ];

    if ($markBotEdit) {
        $params['bot'] = 1;
    }

    if ($minorEdit) {
        $params['minor'] = 1;
    } else {
        $params['notminor'] = 1;
    }

    $data = apiPost($cookieJar, $apiEndpoint, $params);
    if (($data['edit']['result'] ?? '') !== 'Success') {
        throw new RuntimeException('Seite konnte nicht gespeichert werden: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    return $data;
}

try {
    echo "Lese Seiten über API...\n";
    $pagesByNamespace = fetchPages(
        $apiEndpoint,
        $articleBase,
        $namespaces,
        $withExtracts,
        $extractLength,
        $excludeRedirects
    );

    $total = 0;
    foreach ($pagesByNamespace as $ns => $pages) {
        echo 'Namensraum ' . $ns . ': ' . count($pages) . " Seiten\n";
        $total += count($pages);
    }

    echo 'Seiten gesamt: ' . $total . "\n";

    $wikiText = buildWikiText($pagesByNamespace, $namespaceLabels);

    if ($dryRun) {
        echo "\n--- DRY RUN: erzeugter Wikitext ---\n\n";
        echo $wikiText;
        exit(0);
    }

    if ($username === 'DEIN_BENUTZERNAME' || $botPassword === 'DEIN_BOTPASSWORD') {
        throw new RuntimeException('Bitte Benutzername und BotPassword in der Konfiguration setzen.');
    }

    $cookieJar = [];
    echo "Login an MediaWiki API...\n";
    login($cookieJar, $apiEndpoint, $username, $botPassword);

    echo 'Speichere Seite "' . $targetPageTitle . '" ...' . "\n";
    $result = saveWikiPage($cookieJar, $apiEndpoint, $targetPageTitle, $wikiText, $summary, $markBotEdit, $minorEdit);

    echo "Erfolgreich gespeichert.\n";
    echo 'Seiten-ID: ' . ($result['edit']['pageid'] ?? 'n/a') . "\n";
    echo 'Titel: ' . ($result['edit']['title'] ?? $targetPageTitle) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FEHLER: ' . $e->getMessage() . "\n");
    exit(1);
}
