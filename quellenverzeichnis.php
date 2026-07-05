<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

const RAW_PAGE_URL = 'https://wiki.isms-ratgeber.info/wiki/';
const API_URL      = 'https://wiki.isms-ratgeber.info/api.php';

const SOURCE_PAGE = 'Quellenverzeichnis';
const TARGET_PAGE = 'Vorlage:Quelle';

/*
 * Hier lokal Deine BotPassword-Zugangsdaten eintragen.
 * Format fÃ¼r den Benutzernamen bei BotPasswords: Hauptkonto@Botname
 */
const BOT_USERNAME = 'Dirk@inhaltsuebersicht';
const BOT_PASSWORD = 't72c5ne88fpfs6jgnu75ktn6mj219lc2';

function httpGet(string $url, array $params = [], string $cookieFile = ''): array {
    $ch = curl_init();
    if (!empty($params)) {
        $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($params);
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'QuellenSyncBot/1.1'
    ]);

    if ($cookieFile !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException('GET fehlgeschlagen: ' . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new RuntimeException("GET HTTP-Fehler $httpCode fÃ¼r $url");
    }

    return ['body' => $response, 'code' => $httpCode];
}

function httpPost(string $url, array $postFields, string $cookieFile): array {
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'QuellenSyncBot/1.1',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException('POST fehlgeschlagen: ' . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new RuntimeException("POST HTTP-Fehler $httpCode fÃ¼r $url");
    }

    return ['body' => $response, 'code' => $httpCode];
}

function apiGet(array $params, string $cookieFile = ''): array {
    $result = httpGet(API_URL, $params, $cookieFile);
    $data = json_decode($result['body'], true);

    if (!is_array($data)) {
        throw new RuntimeException('UngÃ¼ltige JSON-Antwort bei API GET');
    }

    return $data;
}

function apiPost(array $params, string $cookieFile): array {
    $result = httpPost(API_URL, $params, $cookieFile);
    $data = json_decode($result['body'], true);

    if (!is_array($data)) {
        throw new RuntimeException('UngÃ¼ltige JSON-Antwort bei API POST');
    }

    return $data;
}

function getPageWikitext(string $title): string {
    $result = httpGet(RAW_PAGE_URL . rawurlencode($title), [
        'action' => 'raw'
    ]);

    return $result['body'];
}

function normalizeCell(string $text): string {
    $text = preg_replace('/<!--.*?-->/s', '', $text);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\n{2,}/u', "\n", $text);
    return trim($text);
}

function extractFirstWikiTable(string $wikitext): string {
    $start = strpos($wikitext, '{|');
    if ($start === false) {
        throw new RuntimeException('Keine Wiki-Tabelle gefunden.');
    }

    $end = strpos($wikitext, '|}', $start);
    if ($end === false) {
        throw new RuntimeException('Tabellenende |} nicht gefunden.');
    }

    return substr($wikitext, $start, $end - $start + 2);
}

function splitRowCells(string $rowText, bool $isHeaderRow): array {
    $rowText = str_replace(["\r\n", "\r"], "\n", $rowText);
    $lines = preg_split('/\n/', $rowText);

    $cells = [];
    $current = '';

    foreach ($lines as $line) {
        $raw = rtrim($line);
        if ($raw === '') {
            if ($current !== '') {
                $current .= "\n";
            }
            continue;
        }

        $trimmed = ltrim($raw);

        if ($isHeaderRow) {
            if (strpos($trimmed, '!') === 0) {
                $content = ltrim(substr($trimmed, 1));

                if ($current !== '') {
                    $cells[] = normalizeCell($current);
                    $current = '';
                }

                $parts = preg_split('/!!/', $content);
                foreach ($parts as $index => $part) {
                    $part = normalizeCell($part);
                    if ($index === 0) {
                        $current = $part;
                    } else {
                        $cells[] = normalizeCell($current);
                        $current = $part;
                    }
                }
                continue;
            }
        } else {
            if (strpos($trimmed, '|') === 0 && strpos($trimmed, '|-') !== 0 && strpos($trimmed, '|}') !== 0 && strpos($trimmed, '|+') !== 0) {
                $content = ltrim(substr($trimmed, 1));

                if ($current !== '') {
                    $cells[] = normalizeCell($current);
                    $current = '';
                }

                $parts = preg_split('/\|\|/', $content);
                foreach ($parts as $index => $part) {
                    $part = normalizeCell($part);
                    if ($index === 0) {
                        $current = $part;
                    } else {
                        $cells[] = normalizeCell($current);
                        $current = $part;
                    }
                }
                continue;
            }
        }

        if ($current === '') {
            $current = normalizeCell($trimmed);
        } else {
            $current .= "\n" . normalizeCell($trimmed);
        }
    }

    if ($current !== '') {
        $cells[] = normalizeCell($current);
    }

    $cells = array_values(array_map('normalizeCell', $cells));
    return $cells;
}

function parseFirstWikiTable(string $wikitext): array {
    $table = extractFirstWikiTable($wikitext);
    $lines = preg_split('/\R/', str_replace(["\r\n", "\r"], "\n", $table));

    $headers = [];
    $rows = [];

    $currentRowLines = [];
    $currentRowIsHeader = false;

    $flushCurrentRow = function () use (&$currentRowLines, &$currentRowIsHeader, &$headers, &$rows): void {
        if (empty($currentRowLines)) {
            return;
        }

        $rowText = implode("\n", $currentRowLines);
        $cells = splitRowCells($rowText, $currentRowIsHeader);

        if ($currentRowIsHeader) {
            if (!empty($cells)) {
                $headers = $cells;
            }
        } else {
            if (!empty($headers) && !empty($cells)) {
                while (count($cells) < count($headers)) {
                    $cells[] = '';
                }

                $assoc = array_combine($headers, array_slice($cells, 0, count($headers)));
                if ($assoc !== false) {
                    $isCompletelyEmpty = true;
                    foreach ($assoc as $value) {
                        if (trim((string)$value) !== '') {
                            $isCompletelyEmpty = false;
                            break;
                        }
                    }

                    if (!$isCompletelyEmpty) {
                        $rows[] = $assoc;
                    }
                }
            }
        }

        $currentRowLines = [];
        $currentRowIsHeader = false;
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || strpos($trimmed, '{|') === 0 || strpos($trimmed, '|+') === 0) {
            continue;
        }

        if ($trimmed === '|-' || $trimmed === '|}') {
            $flushCurrentRow();
            continue;
        }

        if (strpos($trimmed, '!') === 0) {
            if (!$currentRowIsHeader && !empty($currentRowLines)) {
                $flushCurrentRow();
            }
            $currentRowIsHeader = true;
            $currentRowLines[] = $line;
            continue;
        }

        if (strpos($trimmed, '|') === 0) {
            if ($currentRowIsHeader) {
                $flushCurrentRow();
            }
            $currentRowLines[] = $line;
            continue;
        }

        if (!empty($currentRowLines)) {
            $currentRowLines[] = $line;
        }
    }

    $flushCurrentRow();

    if (empty($headers)) {
        throw new RuntimeException('Tabellenkopf nicht gefunden.');
    }

    return $rows;
}

function buildTemplateSource(array $rows): string {
    $lines = [];
    $lines[] = '<noinclude>Automatisch erzeugt aus [[Quellenverzeichnis]]. Ã„nderungen bitte dort vornehmen.</noinclude>';
    $lines[] = '{{#switch: {{{1|}}}';

    $seen = [];

    foreach ($rows as $row) {
        $ref   = isset($row['Referenz']) ? trim((string)$row['Referenz']) : '';
        $title = isset($row['Titel']) ? trim((string)$row['Titel']) : '';
        $url   = isset($row['URL']) ? trim((string)$row['URL']) : '';

        if ($ref === '' || $title === '' || $url === '') {
            continue;
        }

        if (isset($seen[$ref])) {
            continue;
        }
        $seen[$ref] = true;

        $lines[] = ' | ' . $ref . ' = [' . $url . ' ' . $title . ']';
    }

    $lines[] = ' | #default = <strong class="error">Unbekannte Quelle: {{{1|}}}</strong>';
    $lines[] = '}}';

    return implode("\n", $lines);
}

function login(string $username, string $password, string $cookieFile): void {
    $tokenResult = apiGet([
        'action' => 'query',
        'meta'   => 'tokens',
        'type'   => 'login',
        'format' => 'json'
    ], $cookieFile);

    $loginToken = $tokenResult['query']['tokens']['logintoken'] ?? null;
    if (!$loginToken) {
        throw new RuntimeException('Login-Token konnte nicht geholt werden.');
    }

    $loginResult = apiPost([
        'action'     => 'login',
        'lgname'     => $username,
        'lgpassword' => $password,
        'lgtoken'    => $loginToken,
        'format'     => 'json'
    ], $cookieFile);

    if (($loginResult['login']['result'] ?? '') !== 'Success') {
        throw new RuntimeException(
            'Login fehlgeschlagen: ' . json_encode($loginResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}

function getCsrfToken(string $cookieFile): string {
    $result = apiGet([
        'action' => 'query',
        'meta'   => 'tokens',
        'format' => 'json'
    ], $cookieFile);

    $token = $result['query']['tokens']['csrftoken'] ?? null;
    if (!$token) {
        throw new RuntimeException('CSRF-Token konnte nicht geholt werden.');
    }

    return $token;
}

function editPage(string $title, string $text, string $summary, string $cookieFile): array {
    $csrfToken = getCsrfToken($cookieFile);

    return apiPost([
        'action'  => 'edit',
        'title'   => $title,
        'text'    => $text,
        'summary' => $summary,
        'token'   => $csrfToken,
        'format'  => 'json'
    ], $cookieFile);
}

function assertApiReachable(): void {
    $result = apiGet([
        'action' => 'query',
        'meta'   => 'siteinfo',
        'siprop' => 'general',
        'format' => 'json'
    ]);

    if (!isset($result['query']['general'])) {
        throw new RuntimeException('API ist erreichbar, aber die Antwort sieht nicht wie eine MediaWiki-API-Antwort aus.');
    }
}

try {
    assertApiReachable();

    $sourceText = getPageWikitext(SOURCE_PAGE);
    $rows = parseFirstWikiTable($sourceText);
    $templateText = buildTemplateSource($rows);

    $currentTargetText = '';
    try {
        $currentTargetText = getPageWikitext(TARGET_PAGE);
    } catch (Throwable $e) {
        $currentTargetText = '';
    }

    if (trim($currentTargetText) === trim($templateText)) {
        echo "Keine Ã„nderung nÃ¶tig.\n";
        exit(0);
    }

    $cookieFile = sys_get_temp_dir() . '/mw_quellen_sync_cookie.txt';

    login(BOT_USERNAME, BOT_PASSWORD, $cookieFile);

    $editResult = editPage(
        TARGET_PAGE,
        $templateText,
        'Automatisch aus [[Quellenverzeichnis]] erzeugt',
        $cookieFile
    );

    if (($editResult['edit']['result'] ?? '') === 'Success') {
        echo "Vorlage erfolgreich aktualisiert.\n";
        exit(0);
    }

    throw new RuntimeException(
        'Bearbeiten fehlgeschlagen: ' . json_encode($editResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

} catch (Throwable $e) {
    echo 'Fehler: ' . $e->getMessage() . "\n";
    exit(1);
}

