<?php
/**
 * CMCD Event Logger
 * =================
 *
 * A single-file PHP endpoint that
 *   - receives CMCD (Common Media Client Data, CTA-5004-B) reports sent by
 *     media players (Event Mode HTTP POST body, plus query-argument and header
 *     transmission modes),
 *   - checks each report against the CTA-5004-B key rules and records any
 *     warnings alongside it,
 *   - stores every report in an SQLite database (requires the pdo_sqlite
 *     PHP extension),
 *   - deletes reports older than a configurable retention period
 *     (default 6 months), and
 *   - serves a web page (HTTP GET) to browse, filter and export stored events.
 *
 * Accepted report formats
 * -----------------------
 *   POST, Content-Type application/cmcd (CTA-5004-B §3.3; text/cmcd from
 *       CTA-5004-A, text/plain or no type are also accepted):
 *       one CMCD record per LF-separated line, each line being a Structured
 *       Field Dictionary (RFC 8941), e.g.
 *           cid="content-id-123",e=ps,sid="session-id-123",sta=r,ts=1764269150889,v=2
 *   POST with CMCD-Request / CMCD-Object / CMCD-Status / CMCD-Session headers.
 *   GET/POST with ?CMCD=<url-encoded payload> query argument (ignored when
 *       CMCD headers are present, per §6 item 8).
 *   Legacy CMCD v1 JSON (object or array of objects) and form-encoded "CMCD"
 *       fields are also accepted.
 *
 * Successful reports are answered with "204 No Content" (§5 item 11).
 * Records whose version 'v' exceeds CMCD_MAX_VERSION are stored with their
 * raw text and an error note, but their keys are not interpreted (§6 item 13).
 *
 * Viewer
 * ------
 *   GET cmcd-logger.php                 -> HTML page of stored events
 *   GET cmcd-logger.php?format=json     -> JSON export (same filters as the page)
 *   GET cmcd-logger.php?format=csv      -> CSV export
 *   GET cmcd-logger.php?format=jsonl    -> raw stored records
 *
 * Configuration
 * -------------
 *   Edit the defaults below, or create "cmcd-logger.config.php" next to this
 *   script returning an array of overrides, or set the CMCD_* environment
 *   variables noted against each option.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

$CONFIG = [
    // Directory holding the SQLite database (cmcd-events.sqlite plus its WAL
    // files). Must be writable by the web server; keep it outside the web root
    // if you can. (env: CMCD_DATA_DIR)
    'data_dir'          => getenv('CMCD_DATA_DIR') ?: __DIR__ . '/cmcd-data',

    // Database file name inside data_dir. (env: CMCD_DB_FILE)
    'db_file'           => getenv('CMCD_DB_FILE') ?: 'cmcd-events.sqlite',

    // How long to keep events: an ISO 8601 duration ("P6M", "P90D", "P1Y")
    // or a whole number of days. (env: CMCD_RETENTION)
    'retention'         => getenv('CMCD_RETENTION') ?: 'P6M',

    // How often (seconds) to sweep expired data files.
    'purge_interval'    => 3600,

    // Request limits.
    'max_body_bytes'    => 1024 * 1024,
    'max_payloads'      => 5000,

    // Value for Access-Control-Allow-Origin on report responses ('' disables CORS).
    // (env: CMCD_CORS_ORIGIN)
    'cors_allow_origin' => getenv('CMCD_CORS_ORIGIN') !== false ? (string) getenv('CMCD_CORS_ORIGIN') : '*',

    // Record the reporting client's IP address and User-Agent.
    'store_client_info' => true,

    // Reverse proxies / load balancers in front of this script, as IPs or CIDR
    // ranges (e.g. ['127.0.0.1', '10.0.0.0/8']). When a request arrives from
    // one of these, the client IP is taken from X-Forwarded-For instead of
    // REMOTE_ADDR. Leave empty if clients connect directly.
    // (env: CMCD_TRUSTED_PROXIES, comma-separated)
    'trusted_proxies'   => array_filter(array_map('trim', explode(',', (string) getenv('CMCD_TRUSTED_PROXIES')))),

    // Optional HTTP Basic credentials protecting the viewer (reports are never
    // authenticated). Leave user empty to disable. (env: CMCD_VIEW_USER / CMCD_VIEW_PASS)
    'viewer_user'       => getenv('CMCD_VIEW_USER') ?: '',
    'viewer_pass'       => getenv('CMCD_VIEW_PASS') ?: '',

    // Viewer settings.
    'page_size'         => 100,
    'max_export_rows'   => 100000,
    'display_timezone'  => 'UTC',
];

$localConfig = __DIR__ . '/cmcd-logger.config.php';
if (is_file($localConfig)) {
    $overrides = require $localConfig;
    if (is_array($overrides)) {
        $CONFIG = array_replace($CONFIG, $overrides);
    }
}

// Highest CMCD version this logger understands.
const CMCD_MAX_VERSION = 2;

// CTA-5004-B Table 1: tokens of the "e" (event) key.
const CMCD_EVENT_LABELS = [
    'abs' => 'Ad break start',
    'abe' => 'Ad break end',
    'as'  => 'Ad start',
    'ae'  => 'Ad end',
    'b'   => 'Backgrounded change',
    'bc'  => 'Bitrate change',
    'c'   => 'Content ID change',
    'ce'  => 'Custom event',
    'e'   => 'Error',
    'h'   => 'Hostname change',
    'm'   => 'Mute',
    'um'  => 'Unmute',
    'pc'  => 'Player collapse',
    'pe'  => 'Player expand',
    'pr'  => 'Playback rate change',
    'ps'  => 'Play state change',
    'rr'  => 'Response received',
    'sk'  => 'Skip ad',
    't'   => 'Time interval',
];

// CTA-5004-B Table 1: tokens of the "ot" (object type) key.
const CMCD_OBJECT_TYPES = ['m', 'a', 'v', 'av', 'i', 'c', 'tt', 'k', 'o'];

// Keys that MUST only be reported on 'rr' (response received) events.
const CMCD_RR_ONLY_KEYS = ['cmsdd', 'cmsds', 'rc', 'smrt', 'ttfb', 'ttfbb', 'ttlb'];

// CTA-5004-B Table 1: tokens of the "sta" (player state) key.
const CMCD_STATE_LABELS = [
    's' => 'starting',
    'p' => 'playing',
    'k' => 'seeking',
    'r' => 'rebuffering',
    'a' => 'paused',
    'w' => 'waiting',
    'e' => 'ended',
    'f' => 'fatal error',
    'q' => 'quit',
    'd' => 'preloading',
];

// CTA-5004-B Table 1 reserved keys (tooltips in the viewer).
const CMCD_KEY_INFO = [
    'ab'    => 'Aggregate encoded bitrate (kbps)',
    'bg'    => 'Backgrounded',
    'bl'    => 'Buffer length (ms)',
    'br'    => 'Encoded bitrate (kbps)',
    'bs'    => 'Buffer starvation',
    'bsa'   => 'Buffer starvation absolute (count)',
    'bsd'   => 'Buffer starvation duration (ms)',
    'bsda'  => 'Buffer starvation duration absolute (ms)',
    'cen'   => 'Custom event name',
    'cid'   => 'Content ID',
    'cmsdd' => 'CMSD-Dynamic header (Base64)',
    'cmsds' => 'CMSD-Static header (Base64)',
    'cs'    => 'Content signature',
    'd'     => 'Object duration (ms)',
    'dfa'   => 'Dropped frames absolute',
    'dl'    => 'Deadline (ms)',
    'e'     => 'Event',
    'ec'    => 'Player error code(s)',
    'h'     => 'Hostname',
    'lab'   => 'Lowest aggregated encoded bitrate (kbps)',
    'lb'    => 'Lowest encoded bitrate (kbps)',
    'ltc'   => 'Live stream latency (ms)',
    'msd'   => 'Media start delay (ms)',
    'mtp'   => 'Measured throughput (kbps)',
    'nor'   => 'Next object request(s)',
    'nr'    => 'Non rendered',
    'nrr'   => 'Next range request (CMCD v1 only)',
    'ot'    => 'Object type',
    'pb'    => 'Playhead bitrate (kbps)',
    'pr'    => 'Playback rate',
    'pt'    => 'Playhead time (ms)',
    'rc'    => 'Response code',
    'rtp'   => 'Requested maximum throughput (kbps)',
    'sf'    => 'Streaming format',
    'sid'   => 'Session ID',
    'smrt'  => 'SMRT-Data header (Base64)',
    'sn'    => 'Sequence number',
    'st'    => 'Stream type',
    'sta'   => 'State',
    'su'    => 'Startup',
    'tab'   => 'Top aggregated encoded bitrate (kbps)',
    'tb'    => 'Top encoded bitrate (kbps)',
    'tbl'   => 'Target buffer length (ms)',
    'tpb'   => 'Top playable bitrate (kbps)',
    'ts'    => 'Timestamp (ms since epoch)',
    'ttfb'  => 'Time to first byte (ms)',
    'ttfbb' => 'Time to first body byte (ms)',
    'ttlb'  => 'Time to last byte (ms)',
    'url'   => 'Request URL',
    'v'     => 'CMCD version',
];

// ---------------------------------------------------------------------------
// Bootstrap & routing
// ---------------------------------------------------------------------------

date_default_timezone_set('UTC');

try {
    $store = new EventStore($CONFIG['data_dir'], parse_retention($CONFIG['retention']), $CONFIG['db_file']);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CMCD logger configuration error: ' . $e->getMessage();
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

switch (true) {
    case $method === 'OPTIONS':
        send_cors_headers($CONFIG, true);
        http_response_code(204);
        break;

    case $method === 'POST':
    case ($method === 'GET' || $method === 'HEAD') && isset($_GET['CMCD']):
        handle_report($CONFIG, $store);
        break;

    case $method === 'GET' || $method === 'HEAD':
        $store->maybePurge((int) $CONFIG['purge_interval']);
        require_viewer_auth($CONFIG);
        handle_viewer($CONFIG, $store);
        break;

    default:
        header('Allow: GET, HEAD, POST, OPTIONS');
        http_response_code(405);
}
exit;

// ---------------------------------------------------------------------------
// Report ingestion
// ---------------------------------------------------------------------------

function handle_report(array $cfg, EventStore $store): void
{
    send_cors_headers($cfg, false);

    // Collect [source, payload] pairs; payload is a CMCD string or a decoded JSON object.
    $payloads = [];

    $headerParts = [];
    foreach (['REQUEST', 'OBJECT', 'STATUS', 'SESSION'] as $h) {
        $v = $_SERVER['HTTP_CMCD_' . $h] ?? '';
        if (trim($v) !== '') {
            $headerParts[] = trim($v);
        }
    }
    if ($headerParts) {
        $payloads[] = ['header', implode(',', $headerParts)];
    } elseif (isset($_GET['CMCD']) && is_string($_GET['CMCD'])) {
        // §6 item 8: if any CMCD headers are present, the query argument is ignored.
        $payloads[] = ['query', $_GET['CMCD']];
    }

    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    $body = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $max = (int) $cfg['max_body_bytes'];
        $body = (string) file_get_contents('php://input', false, null, 0, $max + 1);
        if (strlen($body) > $max) {
            plain_response(413, 'Report body too large');
            return;
        }
    }

    if (trim($body) !== '') {
        $trimmed = ltrim($body);
        if (str_contains($contentType, 'json') || $trimmed[0] === '{' || $trimmed[0] === '[') {
            $json = json_decode($body, true);
            if (!is_array($json)) {
                $payloads[] = ['body', $body]; // stored with a parse error
            } elseif (array_is_list($json)) {
                foreach ($json as $item) {
                    $payloads[] = ['json', $item];
                }
            } else {
                $payloads[] = ['json', $json];
            }
        } elseif ($contentType === 'application/x-www-form-urlencoded' && isset($_POST['CMCD'])) {
            foreach ((array) $_POST['CMCD'] as $p) {
                foreach (preg_split('/\r\n|\n|\r/', (string) $p) as $line) {
                    $payloads[] = ['form', $line];
                }
            }
        } else {
            foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
                $payloads[] = ['body', $line];
            }
        }
    }

    $now = (int) floor(microtime(true) * 1000);
    $records = [];
    foreach ($payloads as [$src, $payload]) {
        if (is_string($payload)) {
            $payload = trim($payload);
            if ($payload === '') {
                continue;
            }
            if (stripos($payload, 'CMCD=') === 0) {
                $payload = rawurldecode(substr($payload, 5));
            }
        }
        $rec = build_record($payload, $src, $now, $contentType, $cfg);
        if ($rec !== null) {
            $records[] = $rec;
        }
        if (count($records) >= (int) $cfg['max_payloads']) {
            break;
        }
    }

    if (!$records) {
        plain_response(400, 'No CMCD payload found in request');
        return;
    }

    try {
        $store->append($records, $now);
    } catch (Throwable $e) {
        error_log('CMCD logger: ' . $e->getMessage());
        plain_response(500, 'Unable to store report');
        return;
    }

    $store->maybePurge((int) $cfg['purge_interval']);
    header('X-CMCD-Events-Stored: ' . count($records));
    http_response_code(204);
}

function build_record(mixed $payload, string $src, int $now, string $contentType, array $cfg): ?array
{
    $rec = [
        'id'  => bin2hex(random_bytes(8)),
        'rx'  => $now,
        'src' => $src,
    ];
    if ($cfg['store_client_info']) {
        $rec['ip'] = client_ip((array) $cfg['trusted_proxies']);
        $rec['ua'] = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
    }
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        $rec['origin'] = substr($_SERVER['HTTP_ORIGIN'], 0, 256);
    }
    if ($contentType !== '') {
        $rec['ct'] = $contentType;
    }

    if (is_array($payload)) {
        $rec['data'] = $payload;
        $rec['raw']  = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } elseif (is_string($payload)) {
        $rec['raw'] = substr($payload, 0, 65536);
        try {
            $rec['data'] = sf_parse_dictionary($payload);
        } catch (InvalidArgumentException $e) {
            $rec['err']  = $e->getMessage();
            $rec['data'] = lenient_parse($payload);
        }
    } else {
        return null;
    }

    // §6 item 13: ignore the whole data set if the version is newer than we understand.
    $ver = cmcd_value($rec, 'v');
    if (is_int($ver) && $ver > CMCD_MAX_VERSION) {
        $rec['err']  = "Unsupported CMCD version $ver (max " . CMCD_MAX_VERSION . '); keys not interpreted';
        $rec['data'] = [];
        return $rec;
    }

    $warn = validate_cmcd($rec['data'], $src);
    if ($warn) {
        $rec['warn'] = $warn;
    }
    return $rec;
}

/**
 * The reporting client's IP address. If the direct peer is a trusted proxy,
 * walks X-Forwarded-For from the right and returns the first untrusted hop
 * (the left-most entries can be forged by the client).
 */
function client_ip(array $trustedProxies): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!$trustedProxies || !ip_in_ranges($ip, $trustedProxies)) {
        return $ip;
    }
    $hops = array_filter(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
    foreach (array_reverse($hops) as $hop) {
        if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
            break; // malformed chain: stop at the last trustworthy address
        }
        $ip = $hop;
        if (!ip_in_ranges($hop, $trustedProxies)) {
            break;
        }
    }
    return $ip;
}

/** True if $ip equals one of $ranges or falls inside one of its CIDR blocks (IPv4 or IPv6). */
function ip_in_ranges(string $ip, array $ranges): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($ranges as $range) {
        [$net, $bits] = array_pad(explode('/', (string) $range, 2), 2, null);
        $netBin = @inet_pton(trim($net));
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            continue;
        }
        $bits = $bits === null ? strlen($bin) * 8 : max(0, min((int) $bits, strlen($bin) * 8));
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            continue;
        }
        $rem = $bits % 8;
        if ($rem === 0 || ((ord($bin[$bytes]) ^ ord($netBin[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0) {
            return true;
        }
    }
    return false;
}

/** Conformance checks against CTA-5004-B; returns human-readable warnings. */
function validate_cmcd(array $d, string $src): array
{
    $w = [];
    $val = static fn(string $k) => cmcd_value(['data' => $d], $k);
    $has = static fn(string $k): bool => array_key_exists($k, $d);
    $e = $val('e');
    $isBody = in_array($src, ['body', 'form', 'json'], true);

    if ($e !== null) {
        if (!$isBody) {
            $w[] = "'e' must only be used in Event mode (POST body)";
        }
        if (!is_string($e) || !isset(CMCD_EVENT_LABELS[$e])) {
            $w[] = "Unknown event type e=" . fmt_cmcd_value($e);
        }
        if (!$has('ts')) {
            $w[] = "Event report is missing required 'ts'";
        }
        if (!$has('v')) {
            $w[] = "Missing 'v' (treated as CMCD v1, which has no Event mode)";
        }
        if ($e === 'ps' && !$has('sta')) {
            $w[] = "e=ps must be accompanied by 'sta'";
        }
        if ($e === 'e' && !$has('ec')) {
            $w[] = "e=e must be accompanied by 'ec'";
        }
        if ($e === 'ce' && !$has('cen')) {
            $w[] = "e=ce must be accompanied by 'cen'";
        }
        if ($e !== 'ce' && $has('cen')) {
            $w[] = "'cen' must only be sent with e=ce";
        }
        if ($e === 'rr' && !$has('url')) {
            $w[] = "e=rr must be accompanied by 'url'";
        }
        if ($e !== 'rr') {
            $bad = array_values(array_filter(CMCD_RR_ONLY_KEYS, $has));
            if ($bad) {
                $w[] = 'Keys only allowed on e=rr: ' . implode(', ', $bad);
            }
        }
    } elseif ($isBody) {
        $w[] = "Event report is missing required 'e'";
    }

    if ($has('sta') && !isset(CMCD_STATE_LABELS[(string) $val('sta')])) {
        $w[] = 'Unknown state sta=' . fmt_cmcd_value($val('sta'));
    }
    if ($has('ot') && !in_array($val('ot'), CMCD_OBJECT_TYPES, true)) {
        $w[] = 'Unknown object type ot=' . fmt_cmcd_value($val('ot'));
    }
    foreach (['ts', 'sn', 'rc', 'd', 'dl', 'ltc', 'msd', 'pt', 'rtp', 'dfa', 'ttfb', 'ttfbb', 'ttlb'] as $k) {
        if ($has($k) && !is_int($val($k))) {
            $w[] = "'$k' must be an integer";
        }
    }
    foreach (['sid', 'cid', 'url', 'h', 'cen', 'cs'] as $k) {
        if ($has($k) && !is_string($val($k))) {
            $w[] = "'$k' must be a string";
        }
    }
    if ($val('v') === 2) {
        foreach (['ec', 'nor', 'bl', 'br', 'mtp', 'tb'] as $k) {
            if ($has($k) && !is_array($d[$k])) {
                $w[] = "'$k' must use inner-list syntax in v2, e.g. $k=(...)";
            }
        }
    }
    return $w;
}

// ---------------------------------------------------------------------------
// Structured Field Dictionary parser (RFC 8941 / RFC 9651), as used by CMCD
// ---------------------------------------------------------------------------

/**
 * Parses a CMCD payload. Values map to PHP types:
 *   integer/decimal -> int/float, string/token -> string, boolean -> bool,
 *   inner list -> list, byte sequence -> ":base64:" string, date -> int.
 * Items carrying parameters become ['value' => v, 'params' => [...]].
 */
function sf_parse_dictionary(string $s): array
{
    $out = [];
    $i = 0;
    $n = strlen($s);
    sf_skip($s, $i, ' ');
    while ($i < $n) {
        $key = sf_parse_key($s, $i);
        if ($i < $n && $s[$i] === '=') {
            $i++;
            $value = ($i < $n && $s[$i] === '(') ? sf_parse_inner_list($s, $i) : sf_parse_bare_item($s, $i);
        } else {
            $value = true;
        }
        $params = sf_parse_params($s, $i);
        $out[$key] = $params ? ['value' => $value, 'params' => $params] : $value;

        sf_skip($s, $i, " \t");
        if ($i >= $n) {
            break;
        }
        if ($s[$i] !== ',') {
            throw new InvalidArgumentException("Expected ',' at offset $i");
        }
        $i++;
        sf_skip($s, $i, " \t");
        if ($i >= $n) {
            throw new InvalidArgumentException('Trailing comma');
        }
    }
    return $out;
}

function sf_skip(string $s, int &$i, string $chars): void
{
    $i += strspn($s, $chars, $i);
}

function sf_parse_key(string $s, int &$i): string
{
    // Lenient: allows upper case so that slightly non-conformant players still log.
    if (!preg_match('/\G[A-Za-z*][A-Za-z0-9_\-.*]*/', $s, $m, 0, $i)) {
        throw new InvalidArgumentException("Invalid key at offset $i");
    }
    $i += strlen($m[0]);
    return $m[0];
}

function sf_parse_params(string $s, int &$i): array
{
    $params = [];
    $n = strlen($s);
    while ($i < $n && $s[$i] === ';') {
        $i++;
        sf_skip($s, $i, ' ');
        $k = sf_parse_key($s, $i);
        $v = true;
        if ($i < $n && $s[$i] === '=') {
            $i++;
            $v = sf_parse_bare_item($s, $i);
        }
        $params[$k] = $v;
    }
    return $params;
}

function sf_parse_inner_list(string $s, int &$i): array
{
    $i++; // '('
    $items = [];
    $n = strlen($s);
    while ($i < $n) {
        sf_skip($s, $i, ' ');
        if ($i < $n && $s[$i] === ')') {
            $i++;
            return $items;
        }
        $item = sf_parse_bare_item($s, $i);
        $params = sf_parse_params($s, $i);
        $items[] = $params ? ['value' => $item, 'params' => $params] : $item;
        if ($i < $n && $s[$i] !== ' ' && $s[$i] !== ')') {
            throw new InvalidArgumentException("Malformed inner list at offset $i");
        }
    }
    throw new InvalidArgumentException('Unterminated inner list');
}

function sf_parse_bare_item(string $s, int &$i): mixed
{
    $n = strlen($s);
    if ($i >= $n) {
        throw new InvalidArgumentException('Missing value');
    }
    $c = $s[$i];

    if ($c === '"') {
        $i++;
        $str = '';
        while ($i < $n) {
            $ch = $s[$i++];
            if ($ch === '\\') {
                if ($i >= $n) {
                    break;
                }
                $str .= $s[$i++];
            } elseif ($ch === '"') {
                return $str;
            } else {
                $str .= $ch;
            }
        }
        throw new InvalidArgumentException('Unterminated string');
    }
    if ($c === '-' || ctype_digit($c)) {
        if (!preg_match('/\G-?\d+(\.\d+)?/', $s, $m, 0, $i)) {
            throw new InvalidArgumentException("Invalid number at offset $i");
        }
        $i += strlen($m[0]);
        return isset($m[1]) ? (float) $m[0] : (int) $m[0];
    }
    if ($c === '?') {
        $b = $s[$i + 1] ?? '';
        if ($b !== '0' && $b !== '1') {
            throw new InvalidArgumentException("Invalid boolean at offset $i");
        }
        $i += 2;
        return $b === '1';
    }
    if ($c === ':') {
        $end = strpos($s, ':', $i + 1);
        if ($end === false) {
            throw new InvalidArgumentException('Unterminated byte sequence');
        }
        $val = substr($s, $i, $end - $i + 1);
        $i = $end + 1;
        return $val;
    }
    if ($c === '@') {
        $i++;
        $v = sf_parse_bare_item($s, $i);
        if (!is_int($v)) {
            throw new InvalidArgumentException('Invalid date');
        }
        return $v;
    }
    if (ctype_alpha($c) || $c === '*') {
        preg_match('/\G[A-Za-z*][!#$%&\'*+\-.^_`|~0-9A-Za-z:\/]*/', $s, $m, 0, $i);
        $i += strlen($m[0]);
        return $m[0];
    }
    throw new InvalidArgumentException("Unexpected character '$c' at offset $i");
}

/** Best-effort key=value split used when a payload is not valid Structured Field syntax. */
function lenient_parse(string $s): array
{
    $out = [];
    foreach (str_getcsv($s, ',', '"', '\\') as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $part, 2), 2, null);
        $out[trim($k)] = $v === null ? true : (is_numeric($v) ? $v + 0 : trim($v, '"'));
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Storage: SQLite (pdo_sqlite) — one row per CMCD record
// ---------------------------------------------------------------------------

final class EventStore
{
    private const SCHEMA_VERSION = 2; // 2: index on ip
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    private PDO $db;
    private string $path;

    public function __construct(string $dir, private DateInterval $retention, string $file = 'cmcd-events.sqlite')
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('The pdo_sqlite PHP extension is required: enable "extension=pdo_sqlite" in php.ini');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create data directory ' . $dir);
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
            @file_put_contents($dir . '/index.html', '');
        }

        $this->path = $dir . '/' . $file;
        $isNew = !is_file($this->path);
        $this->db = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA busy_timeout = 5000');
        if ($isNew) {
            $this->db->exec('PRAGMA auto_vacuum = INCREMENTAL'); // must precede table creation
        }
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA synchronous = NORMAL');
        $this->migrate();
    }

    private function migrate(): void
    {
        $ver = (int) $this->db->query('PRAGMA user_version')->fetchColumn();
        if ($ver >= self::SCHEMA_VERSION) {
            return;
        }
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS events (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                rx     INTEGER NOT NULL,   -- server receive time, ms since epoch (UTC)
                src    TEXT    NOT NULL,   -- body | header | query | json | form
                ip     TEXT,
                ua     TEXT,
                origin TEXT,
                ct     TEXT,               -- request content type
                e      TEXT,               -- CMCD keys promoted to columns for filtering
                sid    TEXT,
                cid    TEXT,
                sn     INTEGER,
                ts     INTEGER,
                sta    TEXT,
                v      INTEGER,
                raw    TEXT,               -- payload as received
                data   TEXT    NOT NULL,   -- parsed keys, JSON object
                err    TEXT,               -- parse / version error
                warn   TEXT                -- conformance warnings, JSON array
            );
            CREATE INDEX IF NOT EXISTS ix_events_rx  ON events (rx);
            CREATE INDEX IF NOT EXISTS ix_events_sid ON events (sid, rx);
            CREATE INDEX IF NOT EXISTS ix_events_cid ON events (cid, rx);
            CREATE INDEX IF NOT EXISTS ix_events_e   ON events (e, rx);
            CREATE INDEX IF NOT EXISTS ix_events_ip  ON events (ip, rx);
            CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT);
            SQL);
        $this->db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    /** Oldest instant (ms since epoch) that is still within retention. */
    public function cutoffMs(): int
    {
        $cut = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->sub($this->retention);
        return (int) $cut->format('U') * 1000;
    }

    public function append(array $records, int $nowMs): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO events (rx, src, ip, ua, origin, ct, e, sid, cid, sn, ts, sta, v, raw, data, err, warn)
             VALUES (:rx, :src, :ip, :ua, :origin, :ct, :e, :sid, :cid, :sn, :ts, :sta, :v, :raw, :data, :err, :warn)'
        );
        $str = static fn($v): ?string => is_string($v) || is_int($v) || is_float($v) ? (string) $v : null;
        $int = static fn($v): ?int => is_int($v) ? $v : null;

        $this->db->beginTransaction();
        try {
            foreach ($records as $r) {
                $data = json_encode($r['data'] ?? [], self::JSON_FLAGS);
                if ($data === false) {
                    throw new RuntimeException('JSON encode failed: ' . json_last_error_msg());
                }
                $stmt->execute([
                    ':rx'     => $r['rx'] ?? $nowMs,
                    ':src'    => $r['src'] ?? '',
                    ':ip'     => $r['ip'] ?? null,
                    ':ua'     => $r['ua'] ?? null,
                    ':origin' => $r['origin'] ?? null,
                    ':ct'     => $r['ct'] ?? null,
                    ':e'      => $str(cmcd_value($r, 'e')),
                    ':sid'    => $str(cmcd_value($r, 'sid')),
                    ':cid'    => $str(cmcd_value($r, 'cid')),
                    ':sn'     => $int(cmcd_value($r, 'sn')),
                    ':ts'     => $int(cmcd_value($r, 'ts')),
                    ':sta'    => $str(cmcd_value($r, 'sta')),
                    ':v'      => $int(cmcd_value($r, 'v')),
                    ':raw'    => $r['raw'] ?? null,
                    ':data'   => $data,
                    ':err'    => $r['err'] ?? null,
                    ':warn'   => empty($r['warn']) ? null : json_encode($r['warn'], self::JSON_FLAGS),
                ]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Deletes every record older than the retention cutoff; returns the number removed. */
    public function purge(): int
    {
        $stmt = $this->db->prepare('DELETE FROM events WHERE rx < :cutoff');
        $stmt->execute([':cutoff' => $this->cutoffMs()]);
        $n = $stmt->rowCount();
        if ($n > 0) {
            $this->db->exec('PRAGMA incremental_vacuum');
        }
        return $n;
    }

    public function maybePurge(int $interval): void
    {
        $last = (int) $this->db->query("SELECT v FROM meta WHERE k = 'last_purge'")->fetchColumn();
        if (time() - $last < $interval) {
            return;
        }
        $this->db->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('last_purge', :t)")->execute([':t' => time()]);
        $this->purge();
    }

    /** @return array<string,int> most recently active client IPs => event count */
    public function recentIps(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT ip, COUNT(*) AS n FROM events WHERE ip IS NOT NULL AND ip <> \'\' AND rx >= :cutoff
             GROUP BY ip ORDER BY MAX(rx) DESC LIMIT :lim'
        );
        $stmt->bindValue(':cutoff', $this->cutoffMs(), PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_column($stmt->fetchAll(), 'n', 'ip');
    }

    /** @return array{count:int, oldest:?int, newest:?int, bytes:int} */
    public function stats(): array
    {
        $row = $this->db->query('SELECT COUNT(*) AS n, MIN(rx) AS lo, MAX(rx) AS hi FROM events')->fetch();
        $bytes = 0;
        foreach (['', '-wal'] as $suffix) {
            $bytes += (int) @filesize($this->path . $suffix);
        }
        return [
            'count'  => (int) $row['n'],
            'oldest' => $row['lo'] !== null ? (int) $row['lo'] : null,
            'newest' => $row['hi'] !== null ? (int) $row['hi'] : null,
            'bytes'  => $bytes,
        ];
    }

    /**
     * Builds the WHERE clause for viewer filters.
     * @param array{from:string,to:string,sid:string,cid:string,e:string,q:string,issues:string} $f
     */
    private function where(array $f): array
    {
        $w = ['rx >= :cutoff'];
        $p = [':cutoff' => $this->cutoffMs()];
        if ($f['from'] !== '') {
            $w[] = 'rx >= :from';
            $p[':from'] = strtotime($f['from'] . ' 00:00:00 UTC') * 1000;
        }
        if ($f['to'] !== '') {
            $w[] = 'rx < :to';
            $p[':to'] = (strtotime($f['to'] . ' 00:00:00 UTC') + 86400) * 1000;
        }
        if ($f['ip'] !== '') {
            if (str_ends_with($f['ip'], '*')) {          // prefix match, e.g. 192.168.1.*
                $w[] = "ip LIKE :ip ESCAPE '\\'";
                $p[':ip'] = addcslashes(rtrim($f['ip'], '*'), '%_\\') . '%';
            } else {
                $w[] = 'ip = :ip';
                $p[':ip'] = $f['ip'];
            }
        }
        foreach (['sid', 'cid'] as $k) {
            if ($f[$k] !== '') {
                $w[] = "$k = :$k";
                $p[":$k"] = $f[$k];
            }
        }
        if ($f['e'] === '-') {
            $w[] = 'e IS NULL';
        } elseif ($f['e'] !== '') {
            $w[] = 'e = :e';
            $p[':e'] = $f['e'];
        }
        if ($f['issues'] !== '') {
            $w[] = '(err IS NOT NULL OR warn IS NOT NULL)';
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $w[] = "(raw LIKE :q1 ESCAPE '\\' OR data LIKE :q2 ESCAPE '\\' OR ua LIKE :q3 ESCAPE '\\' OR ip LIKE :q4 ESCAPE '\\')";
            $p += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
        }
        return [implode(' AND ', $w), $p];
    }

    /** @return array{rows: array, more: bool, total: int} newest first */
    public function query(array $f, int $offset, int $limit): array
    {
        [$where, $p] = $this->where($f);
        $count = $this->db->prepare("SELECT COUNT(*) FROM events WHERE $where");
        $count->execute($p);
        $rows = iterator_to_array($this->iterate($f, $limit + 1, $offset), false);
        $more = count($rows) > $limit;
        return ['rows' => array_slice($rows, 0, $limit), 'more' => $more, 'total' => (int) $count->fetchColumn()];
    }

    /** Streams matching records (newest first) without loading them all into memory. */
    public function iterate(array $f, int $limit, int $offset = 0): Generator
    {
        [$where, $p] = $this->where($f);
        $stmt = $this->db->prepare("SELECT * FROM events WHERE $where ORDER BY rx DESC, id DESC LIMIT :lim OFFSET :off");
        foreach ($p as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            yield self::toRecord($row);
        }
    }

    /** Converts a DB row back to the record shape used by the viewer and exports. */
    private static function toRecord(array $row): array
    {
        $r = ['id' => (int) $row['id'], 'rx' => (int) $row['rx'], 'src' => $row['src']];
        foreach (['ip', 'ua', 'origin', 'ct', 'raw', 'err'] as $k) {
            if ($row[$k] !== null) {
                $r[$k] = $row[$k];
            }
        }
        $r['data'] = json_decode($row['data'], true) ?? [];
        if ($row['warn'] !== null) {
            $r['warn'] = json_decode($row['warn'], true) ?? [];
        }
        return $r;
    }
}

function parse_retention(string|int $r): DateInterval
{
    $r = trim((string) $r);
    if (ctype_digit($r)) {
        return new DateInterval('P' . (int) $r . 'D');
    }
    try {
        return new DateInterval(strtoupper($r));
    } catch (Exception) {
        throw new InvalidArgumentException("Invalid retention value '$r' (use e.g. P6M, P90D or 180)");
    }
}

// ---------------------------------------------------------------------------
// HTTP helpers
// ---------------------------------------------------------------------------

function send_cors_headers(array $cfg, bool $preflight): void
{
    $origin = (string) $cfg['cors_allow_origin'];
    if ($origin === '') {
        return;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    if ($origin !== '*') {
        header('Vary: Origin');
    }
    if ($preflight) {
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        // §6 item 11: always allow the four CMCD headers, plus whatever else was asked for.
        $req = preg_replace('/[^A-Za-z0-9_\-, ]/', '', $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, CMCD-Request, CMCD-Object, CMCD-Status, CMCD-Session'
            . ($req !== '' ? ', ' . $req : ''));
        header('Access-Control-Max-Age: 86400');
    }
}

function plain_response(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg, "\n";
}

function require_viewer_auth(array $cfg): void
{
    if ($cfg['viewer_user'] === '') {
        return;
    }
    $u = $_SERVER['PHP_AUTH_USER'] ?? '';
    $p = $_SERVER['PHP_AUTH_PW'] ?? '';
    if (!hash_equals((string) $cfg['viewer_user'], $u) || !hash_equals((string) $cfg['viewer_pass'], $p)) {
        header('WWW-Authenticate: Basic realm="CMCD event logger", charset="UTF-8"');
        plain_response(401, 'Authentication required');
        exit;
    }
}

// ---------------------------------------------------------------------------
// Viewer
// ---------------------------------------------------------------------------

function h(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Returns the plain value of a CMCD key (unwrapping parameterised items). */
function cmcd_value(array $rec, string $key): mixed
{
    $v = $rec['data'][$key] ?? null;
    return (is_array($v) && array_key_exists('value', $v) && array_key_exists('params', $v)) ? $v['value'] : $v;
}

function fmt_cmcd_value(mixed $v): string
{
    if ($v === true) {
        return '?1';
    }
    if ($v === false) {
        return '?0';
    }
    if (is_array($v)) {
        if (array_key_exists('value', $v) && array_key_exists('params', $v)) {
            $s = fmt_cmcd_value($v['value']);
            foreach ($v['params'] as $pk => $pv) {
                $s .= ';' . $pk . ($pv === true ? '' : '=' . fmt_cmcd_value($pv));
            }
            return $s;
        }
        if (array_is_list($v)) {
            return '(' . implode(' ', array_map('fmt_cmcd_value', $v)) . ')';
        }
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return (string) $v;
}

function fmt_ms(mixed $ms, DateTimeZone $tz): string
{
    if (!is_int($ms) && !(is_float($ms))) {
        return '';
    }
    $sec = intdiv((int) $ms, 1000);
    $d = (new DateTimeImmutable('@' . $sec))->setTimezone($tz);
    return $d->format('Y-m-d H:i:s') . sprintf('.%03d', ((int) $ms) % 1000);
}

function handle_viewer(array $cfg, EventStore $store): void
{
    $tz = new DateTimeZone($cfg['display_timezone']);
    $g = static fn(string $k): string => trim(is_string($_GET[$k] ?? null) ? $_GET[$k] : '');
    $validDay = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

    $today   = gmdate('Y-m-d');
    $minDay  = gmdate('Y-m-d', intdiv($store->cutoffMs(), 1000));
    $f = [
        'from' => $validDay($g('from')) ? $g('from') : $minDay,
        'to'   => $validDay($g('to')) ? $g('to') : $today,
        'sid'  => $g('sid'),
        'cid'  => $g('cid'),
        'e'    => $g('e'),
        'ip'   => $g('ip'),
        'q'    => $g('q'),
        'issues' => $g('issues') === '1' ? '1' : '',
    ];
    $format  = strtolower($g('format')) ?: 'html';
    $page    = max(1, (int) ($g('page') ?: 1));
    $refresh = max(0, min(3600, (int) $g('refresh')));

    // ---- Exports (streamed) --------------------------------------------------
    if (in_array($format, ['json', 'csv', 'jsonl'], true)) {
        $rows = $store->iterate($f, (int) $cfg['max_export_rows']);
        $name = 'cmcd-events-' . gmdate('Ymd-His');
        $jf = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header("Content-Disposition: inline; filename=\"$name.json\"");
            echo '[';
            $first = true;
            foreach ($rows as $r) {
                echo $first ? "\n" : ",\n", json_encode($r, $jf);
                $first = false;
            }
            echo "\n]\n";
        } elseif ($format === 'jsonl') {
            header('Content-Type: application/x-ndjson; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"$name.jsonl\"");
            foreach ($rows as $r) {
                echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
            }
        } else {
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"$name.csv\"");
            $out = fopen('php://output', 'w');
            $safe = static fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
            fputcsv($out, ['received', 'e', 'sid', 'cid', 'sn', 'ts', 'sta', 'src', 'ip', 'user_agent', 'data_json', 'raw', 'error', 'warnings'], ',', '"', '\\');
            foreach ($rows as $r) {
                fputcsv($out, array_map($safe, [
                    fmt_ms($r['rx'] ?? null, $tz),
                    fmt_cmcd_value(cmcd_value($r, 'e') ?? ''),
                    fmt_cmcd_value(cmcd_value($r, 'sid') ?? ''),
                    fmt_cmcd_value(cmcd_value($r, 'cid') ?? ''),
                    fmt_cmcd_value(cmcd_value($r, 'sn') ?? ''),
                    fmt_ms(cmcd_value($r, 'ts'), $tz),
                    fmt_cmcd_value(cmcd_value($r, 'sta') ?? ''),
                    $r['src'] ?? '',
                    $r['ip'] ?? '',
                    $r['ua'] ?? '',
                    json_encode($r['data'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $r['raw'] ?? '',
                    $r['err'] ?? '',
                    implode('; ', $r['warn'] ?? []),
                ]), ',', '"', '\\');
            }
            fclose($out);
        }
        return;
    }

    // ---- HTML page -----------------------------------------------------------
    $pageSize = (int) $cfg['page_size'];
    $res = $store->query($f, ($page - 1) * $pageSize, $pageSize);
    $stats = $store->stats();

    $self = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
    $link = static function (array $extra) use ($f, $refresh, $self): string {
        $params = array_filter(array_merge($f, ['refresh' => $refresh ?: ''], $extra), static fn($v) => $v !== '' && $v !== null);
        return h($self . ($params ? '?' . http_build_query($params) : ''));
    };

    $retention = $cfg['retention'];
    $reportUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $self;

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($refresh): ?><meta http-equiv="refresh" content="<?= $refresh ?>"><?php endif; ?>
<title>CMCD Event Log</title>
<style>
:root{--bg:#f7f7f8;--panel:#fff;--fg:#1d1f23;--muted:#666b75;--line:#e2e4e8;--accent:#1f6feb;--chip:#eef2f8;--err:#b42318;--errbg:#fdecea;--warn:#8a5a00;--warnbg:#fff4d6;--mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
@media (prefers-color-scheme:dark){:root{--bg:#121417;--panel:#1a1d21;--fg:#e6e8eb;--muted:#9aa1ab;--line:#2c3036;--accent:#58a6ff;--chip:#252a31;--err:#ff8a80;--errbg:#3a1f1f;--warn:#f0c060;--warnbg:#3a3020}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:14px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}
header{padding:16px 20px;border-bottom:1px solid var(--line);background:var(--panel);display:flex;flex-wrap:wrap;gap:8px 24px;align-items:baseline}
header h1{font-size:18px;margin:0}
header .meta{color:var(--muted);font-size:13px}
code{font-family:var(--mono);font-size:12.5px}
main{padding:16px 20px}
form.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:14px}
form.filters label{display:flex;flex-direction:column;font-size:12px;color:var(--muted);gap:3px}
input,select,button{font:inherit;padding:5px 8px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--fg)}
input[type=text]{width:180px}
button{background:var(--accent);color:#fff;border-color:var(--accent);cursor:pointer}
a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}
.bar{display:flex;flex-wrap:wrap;gap:6px 16px;align-items:center;margin-bottom:10px;color:var(--muted);font-size:13px}
.table-wrap{overflow-x:auto;background:var(--panel);border:1px solid var(--line);border-radius:8px}
table{border-collapse:collapse;width:100%}
th,td{text-align:left;padding:6px 10px;border-bottom:1px solid var(--line);vertical-align:top;white-space:nowrap}
th{font-size:12px;color:var(--muted);font-weight:600;position:sticky;top:0;background:var(--panel)}
td.keys{white-space:normal;min-width:280px}
.chip{display:inline-block;background:var(--chip);border-radius:4px;padding:0 5px;margin:1px 2px 1px 0;font-family:var(--mono);font-size:12px}
.chip b{font-weight:600}
.ev{font-family:var(--mono);font-weight:600}
.lbl{color:var(--muted);font-size:12px}
.err{color:var(--err);background:var(--errbg);border-radius:4px;padding:0 5px;font-size:12px}
.warn{color:var(--warn);background:var(--warnbg);border-radius:4px;padding:0 5px;font-size:12px;cursor:help}
.issues{margin:6px 0 0;padding-left:18px;color:var(--warn);font-size:12px}
form.filters label.check{justify-content:flex-end;color:var(--fg);font-size:13px}
details summary{cursor:pointer;color:var(--accent);font-size:12px}
details pre{white-space:pre-wrap;word-break:break-all;max-width:70vw;font-family:var(--mono);font-size:12px;margin:6px 0 0;background:var(--bg);padding:8px;border-radius:6px}
.empty{padding:28px;text-align:center;color:var(--muted)}
.pager{margin-top:12px;display:flex;gap:16px}
</style>
</head>
<body>
<header>
  <h1>CMCD Event Log</h1>
  <span class="meta">Report endpoint: <code><?= h($reportUrl) ?></code></span>
  <span class="meta">Retention: <code><?= h($retention) ?></code> &middot; <?= h(number_format($stats['count'])) ?> stored event(s), <?= h(number_format($stats['bytes'] / 1048576, 2)) ?> MiB SQLite<?php if ($stats['oldest'] !== null): ?>, oldest <?= h(fmt_ms($stats['oldest'], $tz)) ?><?php endif; ?>
    &middot; times in <?= h($cfg['display_timezone']) ?></span>
</header>
<main>
<form class="filters" method="get">
  <label>From (UTC day)<input type="date" name="from" value="<?= h($f['from']) ?>" min="<?= h($minDay) ?>"></label>
  <label>To (UTC day)<input type="date" name="to" value="<?= h($f['to']) ?>"></label>
  <label>Event (e)
    <select name="e">
      <option value="">any</option>
      <?php foreach (CMCD_EVENT_LABELS as $code => $label): ?>
        <option value="<?= h($code) ?>"<?= $f['e'] === $code ? ' selected' : '' ?>><?= h("$code – $label") ?></option>
      <?php endforeach; ?>
      <option value="-"<?= $f['e'] === '-' ? ' selected' : '' ?>>(no e key)</option>
    </select>
  </label>
  <label>Session ID (sid)<input type="text" name="sid" value="<?= h($f['sid']) ?>"></label>
  <label>Content ID (cid)<input type="text" name="cid" value="<?= h($f['cid']) ?>"></label>
  <label title="Exact address, or a prefix ending in * (e.g. 192.168.1.*)">Client IP<input type="text" name="ip" list="recent-ips" placeholder="exact or prefix*" value="<?= h($f['ip']) ?>"></label>
  <datalist id="recent-ips">
    <?php foreach ($store->recentIps() as $rip => $n): ?><option value="<?= h($rip) ?>"><?= h(number_format($n)) ?> event(s)</option><?php endforeach; ?>
  </datalist>
  <label>Text search<input type="text" name="q" value="<?= h($f['q']) ?>"></label>
  <label class="check"><span><input type="checkbox" name="issues" value="1"<?= $f['issues'] ? ' checked' : '' ?>> Only reports with issues</span></label>
  <label>Auto-refresh
    <select name="refresh">
      <?php foreach ([0 => 'off', 5 => '5 s', 15 => '15 s', 60 => '60 s'] as $sec => $label): ?>
        <option value="<?= $sec ?: '' ?>"<?= $refresh === $sec ? ' selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button type="submit">Apply</button>
  <a href="<?= h($self) ?>">Reset</a>
</form>

<div class="bar">
  <span>Page <?= $page ?> &middot; showing <?= count($res['rows']) ?> of <?= h(number_format($res['total'])) ?> matching event(s), newest first</span>
  <span>Export: <a href="<?= $link(['format' => 'json']) ?>">JSON</a> · <a href="<?= $link(['format' => 'csv']) ?>">CSV</a> · <a href="<?= $link(['format' => 'jsonl']) ?>">JSONL</a></span>
</div>

<div class="table-wrap">
<?php if (!$res['rows']): ?>
  <div class="empty">No events match these filters.</div>
<?php else: ?>
<table>
  <thead><tr>
    <th>Received</th><th>Event (e)</th><th>Session (sid)</th><th>Content (cid)</th><th>sn</th><th>Client ts</th><th>State (sta)</th><th>Other keys</th><th>Client IP</th><th>Source</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($res['rows'] as $r):
      $e   = cmcd_value($r, 'e');
      $sid = cmcd_value($r, 'sid');
      $cid = cmcd_value($r, 'cid');
      $sta = cmcd_value($r, 'sta');
      $ts  = cmcd_value($r, 'ts');
      $shown = ['e', 'sid', 'cid', 'sn', 'ts', 'sta'];
  ?>
    <tr>
      <td><code><?= h(fmt_ms($r['rx'] ?? null, $tz)) ?></code></td>
      <td><?php if ($e !== null): ?><span class="ev"><?= h(fmt_cmcd_value($e)) ?></span>
          <span class="lbl"><?= h(CMCD_EVENT_LABELS[(string) $e] ?? '') ?></span><?php endif; ?>
          <?php if (!empty($r['err'])): ?><br><span class="err" title="<?= h($r['err']) ?>">error</span><?php endif; ?>
          <?php if (!empty($r['warn'])): ?><br><span class="warn" title="<?= h(implode("\n", $r['warn'])) ?>"><?= count($r['warn']) ?> warning<?= count($r['warn']) > 1 ? 's' : '' ?></span><?php endif; ?></td>
      <td><?php if ($sid !== null): ?><a href="<?= $link(['sid' => (string) $sid, 'page' => '']) ?>" title="Show this session"><code><?= h(fmt_cmcd_value($sid)) ?></code></a><?php endif; ?></td>
      <td><?php if ($cid !== null): ?><a href="<?= $link(['cid' => (string) $cid, 'page' => '']) ?>"><code><?= h(fmt_cmcd_value($cid)) ?></code></a><?php endif; ?></td>
      <td><code><?= h(fmt_cmcd_value(cmcd_value($r, 'sn') ?? '')) ?></code></td>
      <td><code title="<?= h(fmt_cmcd_value($ts ?? '')) ?>"><?= h(fmt_ms($ts, $tz)) ?></code></td>
      <td><?php if ($sta !== null): ?><code><?= h(fmt_cmcd_value($sta)) ?></code>
          <span class="lbl"><?= h(CMCD_STATE_LABELS[(string) $sta] ?? '') ?></span><?php endif; ?></td>
      <td class="keys"><?php foreach (($r['data'] ?? []) as $k => $v):
            if (in_array((string) $k, $shown, true)) { continue; } ?>
          <span class="chip" title="<?= h(CMCD_KEY_INFO[(string) $k] ?? '') ?>"><b><?= h($k) ?></b><?= $v === true ? '' : '=' . h(fmt_cmcd_value($v)) ?></span>
        <?php endforeach; ?></td>
      <td><?php if (!empty($r['ip'])): ?><a href="<?= $link(['ip' => $r['ip'], 'page' => '']) ?>" title="Show reports from this IP"><code><?= h($r['ip']) ?></code></a><?php endif; ?></td>
      <td><span class="lbl"><?= h($r['src'] ?? '') ?></span></td>
      <td><details><summary>details</summary>
        <?php if (!empty($r['err']) || !empty($r['warn'])): ?><ul class="issues">
          <?php if (!empty($r['err'])): ?><li><?= h($r['err']) ?></li><?php endif; ?>
          <?php foreach ($r['warn'] ?? [] as $wm): ?><li><?= h($wm) ?></li><?php endforeach; ?>
        </ul><?php endif; ?><pre><?= h(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) ?></pre></details></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>

<div class="pager">
  <?php if ($page > 1): ?><a href="<?= $link(['page' => $page - 1 > 1 ? $page - 1 : '']) ?>">&larr; Newer</a><?php endif; ?>
  <?php if ($res['more']): ?><a href="<?= $link(['page' => $page + 1]) ?>">Older &rarr;</a><?php endif; ?>
</div>
</main>
</body>
</html>
<?php
}
