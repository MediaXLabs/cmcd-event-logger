# CMCD Event Logger

`cmcd-logger.php` is a single PHP script that receives CMCD (Common Media Client Data) reports from media players. CMCD is defined in **CTA-5004-B**. The script:

- accepts reports sent by **Event Mode** HTTP POST, and also by the **query-argument** (`?CMCD=`) and **header** (`CMCD-Request/Object/Status/Session`) transmission modes
- stores every report in an **SQLite** database, with the time it arrived, the client IP address and User-Agent, the raw text and the parsed keys
- checks each report against the CTA-5004-B rules and stores any warnings with it
- deletes stored reports once they are older than a configurable retention period (**default: 6 months**)
- serves a web page that lists stored events, with filters, auto-refresh and JSON, CSV or JSONL export

The database is a single file that the script creates the first time it runs. You don't need a database server.

---

## Prerequisites

| Requirement | Details |
|---|---|
| **PHP 8.1 or later** | The script uses PHP 8.1 features such as `array_is_list`. Check your version with `php -v`. |
| **`pdo_sqlite` extension** | Used to store events. It needs SQLite 3.7 or later; PHP's bundled copy is fine. The other extensions it uses (`json`, `pcre`, `date`) are always built in. |
| **A web server running PHP** | For example Apache (mod_php or PHP-FPM) or nginx with PHP-FPM. PHP's built-in server (`php -S`) is fine for local testing. |
| **A writable data directory on a local disk** | The web server user must be able to create files in the directory as well as write the database file. In WAL mode SQLite keeps `-wal` and `-shm` files next to the database. Don't put the database on a network share (SMB/NFS), because SQLite's file locking isn't reliable there. |
| **HTTPS** (recommended) | Recommended by CTA-5004-B §6 item 14. It's also needed if the players run on HTTPS pages, because browsers block mixed content. |

### Enabling `pdo_sqlite`

Check whether it's already loaded:

```sh
php -m | grep -i pdo_sqlite      # Windows PowerShell: php -m | Select-String pdo_sqlite
```

If nothing is printed, enable it for your platform:

- **Windows** (php.net zip builds): in `php.ini`, uncomment `extension=pdo_sqlite` and make sure `extension_dir` points at PHP's `ext` folder. Run `php --ini` to see which `php.ini` is loaded.
- **Debian / Ubuntu**: `sudo apt install php-sqlite3`
- **RHEL / Rocky / Fedora**: `sudo dnf install php-pdo`
- **Alpine**: `apk add php83-pdo_sqlite` (use the package name for your PHP version)
- **macOS (Homebrew)**: `brew install php`, which includes `pdo_sqlite`
- **Docker `php:*` official images**: already included

The command-line PHP and the web server's PHP (PHP-FPM, mod_php) often load **different `php.ini` files**. After enabling the extension, restart PHP-FPM or Apache. To confirm the web server has it, open the viewer page: if `pdo_sqlite` is missing, the page reports *"The pdo_sqlite PHP extension is required"*.

## Installation

1. Copy `cmcd-logger.php` into a directory that your web server publishes, for example `/var/www/html/cmcd/`.
2. Choose where the event data will be stored. By default it goes to `cmcd-data/`, next to the script. It is safer to put it **outside the web root**. Copy `cmcd-logger.config.example.php` to `cmcd-logger.config.php` and edit it:

   ```php
   // cmcd-logger.config.php (place next to cmcd-logger.php)
   <?php
   return [
       'data_dir'  => '/var/lib/cmcd-logger',
       'retention' => 'P6M',
   ];
   ```

3. Give the web server user write access to that directory:

   ```sh
   sudo mkdir -p /var/lib/cmcd-logger
   sudo chown www-data:www-data /var/lib/cmcd-logger
   ```

4. If the data directory has to stay inside the web root:
   - **Apache**: no extra step is needed. The script writes a `.htaccess` file into the directory that denies all access.
   - **nginx**: block the directory yourself:

     ```nginx
     location ^~ /cmcd/cmcd-data/ { deny all; }
     ```

5. Open `https://your-host/cmcd/cmcd-logger.php` in a browser. The page shows the report endpoint URL and an empty event list.

### Quick local test

```sh
php -S 0.0.0.0:8080
# Viewer:          http://localhost:8080/cmcd-logger.php
# Report endpoint: http://localhost:8080/cmcd-logger.php
```

## Configuration

You can set options in three ways. Later ones override earlier ones:

1. The defaults in the `$CONFIG` array at the top of `cmcd-logger.php`
2. Environment variables (shown in the table)
3. A `cmcd-logger.config.php` file next to the script that returns an array of overrides

| Option | Env variable | Default | Meaning |
|---|---|---|---|
| `data_dir` | `CMCD_DATA_DIR` | `./cmcd-data` | Directory that holds the SQLite database. It must be writable and on a local disk. |
| `db_file` | `CMCD_DB_FILE` | `cmcd-events.sqlite` | Database file name inside `data_dir` |
| `retention` | `CMCD_RETENTION` | `P6M` | How long reports are kept. Use an ISO 8601 duration (`P6M`, `P90D`, `P1Y`) or a number of days (`180`) |
| `purge_interval` | – | `3600` | Minimum number of seconds between deletions of expired reports |
| `max_body_bytes` | – | `1048576` | Largest POST body accepted (larger bodies get `413`) |
| `max_payloads` | – | `5000` | Most records stored from a single POST |
| `cors_allow_origin` | `CMCD_CORS_ORIGIN` | `*` | Value of `Access-Control-Allow-Origin` (`''` turns CORS headers off) |
| `store_client_info` | – | `true` | Whether to record the client IP address and User-Agent |
| `trusted_proxies` | `CMCD_TRUSTED_PROXIES` (comma-separated) | empty | Reverse proxies or load balancers in front of the script, as IPs or CIDR ranges (e.g. `127.0.0.1, 10.0.0.0/8`). See [Client IP addresses](#client-ip-addresses). |
| `viewer_user` / `viewer_pass` | `CMCD_VIEW_USER` / `CMCD_VIEW_PASS` | empty | If set, the viewer requires HTTP Basic auth. Report submission is never authenticated. |
| `page_size` | – | `100` | Events shown per page in the viewer |
| `max_export_rows` | – | `100000` | Most rows in one export |
| `display_timezone` | – | `UTC` | Time zone used to display times in the viewer and in CSV exports |

### Retention

A purge runs at most once per `purge_interval`, triggered by a POST or a viewer request. It deletes every report received before *now − retention*. The database uses incremental auto-vacuum, so space freed by deleted reports is given back to the file system. The viewer and exports also hide any record older than the cutoff, so what you see always matches the retention setting, even between purges.

You don't need a cron job. On a server that receives little traffic you can force a sweep at a set time by requesting the viewer URL.

### Client IP addresses

Every stored report records the IP address of the player that sent it (`ip` column, indexed). You can filter on it in the viewer.

- **Players connect directly** (no proxy): the address is the TCP peer address (`REMOTE_ADDR`), and no configuration is needed.
- **Behind a reverse proxy, load balancer or CDN**: `REMOTE_ADDR` is the proxy's address, so every report would appear to come from the proxy. List the proxy addresses in `trusted_proxies`. When a request comes from one of them, the client address is read from `X-Forwarded-For`. The header is read from right to left, skipping trusted proxies, and the first untrusted address is used. Entries further to the left are ignored, because the client can forge them.
- Requests from addresses that aren't trusted proxies **ignore** `X-Forwarded-For`, so a player can't fake its address.

```php
'trusted_proxies' => ['127.0.0.1', '::1', '10.0.0.0/8'],   // e.g. nginx on the same host + an internal LB
```

For nginx in front of PHP-FPM, make sure the proxy sets the header: `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;`

Set `store_client_info` to `false` to stop recording IP addresses and User-Agents altogether.

## Sending reports

Point the player's CMCD Event Mode target, or a request-mode beacon, at the script URL.

### Event Mode / batch (CTA-5004-B §3.3)

```http
POST /cmcd/cmcd-logger.php
Content-Type: application/cmcd

cid="content-id-123",e=ps,sid="session-id-123",sta=r,ts=1764269150889,v=2
cid="content-id-123",e=t,sid="session-id-123",sn=2,sta=p,ts=1764269180889,v=2
```

Each LF-separated line is stored as its own record. Spaces at the start or end of a line are ignored. `text/cmcd` (the CTA-5004-A content type), `text/plain` and a missing content type are also accepted.

### Header mode

```http
POST /cmcd/cmcd-logger.php
CMCD-Request: bl=(2000),sta=p
CMCD-Session: cid="content-id-123",sid="session-id-123",v=2
```

The four headers are combined into one record. Per §6 item 8, any `CMCD=` query argument on the same request is ignored when CMCD headers are present.

### Query-argument mode

```
GET /cmcd/cmcd-logger.php?CMCD=cid%3D%22content-id-123%22%2Csid%3D%22session-id-123%22%2Cv%3D2
```

A GET that carries `CMCD=` is logged as a report. A GET without it shows the viewer.

### Legacy

CMCD v1 JSON is also accepted: either an object of keys, or an array of objects or of CMCD strings, sent with `Content-Type: application/json`. A form-encoded `CMCD` field is accepted too.

### Responses

| Status | Meaning |
|---|---|
| `204 No Content` | Stored (§5 item 11). The `X-CMCD-Events-Stored` header gives the number of records stored. |
| `400` | The request contained no CMCD payload |
| `413` | The body was larger than `max_body_bytes` |
| `500` | The report could not be written to the database, or `pdo_sqlite` is missing or the data directory isn't writable |

`OPTIONS` preflight requests are answered with `204`. The answer always allows `CMCD-Request`, `CMCD-Object`, `CMCD-Status`, `CMCD-Session`, `Content-Type` and `Authorization` (§6 item 11).

### curl examples

```sh
URL=http://localhost:8080/cmcd-logger.php

# Event mode batch
curl -X POST -H 'Content-Type: application/cmcd' --data-binary $'e=ps,sid="s1",sta=p,ts=1764269150889,v=2\ne=t,sid="s1",ts=1764269180889,v=2' "$URL"

# Header mode
curl -X POST -H 'CMCD-Session: cid="c1",sid="s1",v=2' -H 'CMCD-Request: bl=(2000),sta=p' "$URL"
```

## How reports are processed

- Each payload is parsed as an RFC 8941 Structured Field Dictionary:
  - integers and decimals become numbers
  - strings and tokens become strings
  - a key with no value becomes `true`; `?0` becomes `false`
  - inner lists become arrays
  - an item with parameters, such as `br=(3000;v 164;a)`, is stored as `{"value":3000,"params":{"v":true}}`
- **Parse failures**: the record is still stored, with its raw text, an error message and a best-effort key/value split, so a broken player still shows up in the log.
- **Version check (§6 item 13)**: if `v` is greater than 2, the record is stored with its raw text and the error *"Unsupported CMCD version"*. Its keys are not interpreted.
- **Conformance warnings** are stored with each record and shown in the viewer. The checks include:
  - an Event report without `e`, `ts` or `v`
  - an unknown `e`, `sta` or `ot` token
  - `e=ps` without `sta`, `e=e` without `ec`, `e=ce` without `cen`, or `e=rr` without `url`
  - keys that are only allowed on `rr` events (`rc`, `ttfb`, `ttfbb`, `ttlb`, `cmsdd`, `cmsds`, `smrt`) appearing on other events
  - `cen` sent with an event other than `ce`
  - keys with the wrong type, for example `sn` or `ts` not an integer
  - v2 keys sent without the required inner-list syntax (`ec`, `nor`, `bl`, `br`, `mtp`, `tb`)

## Viewing events

Open the script URL in a browser:

- **Filters**:
  - UTC date range
  - event type (`e`)
  - session ID (`sid`)
  - content ID (`cid`)
  - client IP address: an exact address, or a prefix ending in `*` such as `192.168.1.*`. The box suggests the most recently active client IPs, with an event count for each.
  - free-text search
  - "only reports with issues"
- Click a `sid`, `cid` or client IP value to filter on it. Filtering on one `sid` shows that player session's timeline. Filtering on an IP shows everything one device or test rig has sent.
- **Details** expands a row to show any warnings and the full stored record: the raw payload, the parsed keys, the client IP address and the User-Agent.
- **Auto-refresh** reloads the page every 5, 15 or 60 seconds while you watch a test.
- **Exports** use the current filters:
  - `?format=json`
  - `?format=csv`
  - `?format=jsonl`

Examples:
- `cmcd-logger.php?sid=session-id-123&e=ps&format=csv`
- `cmcd-logger.php?ip=192.168.1.*&issues=1`

## Storage format

Events are stored in `data_dir/cmcd-events.sqlite`, in the table `events`, one row per CMCD record. The script creates the schema itself the first time it runs. The schema version is kept in `PRAGMA user_version`.

| Column | Content |
|---|---|
| `id` | Auto-incrementing row ID |
| `rx` | Time the server received the report (ms since epoch, UTC). Indexed. |
| `src` | `body`, `header`, `query`, `json` or `form` |
| `ip` | IP address of the reporting client. Comes from `X-Forwarded-For` when the request arrives through a trusted proxy. Indexed. |
| `ua`, `origin`, `ct` | User-Agent, `Origin` header and request content type |
| `e`, `sid`, `cid`, `sn`, `ts`, `sta`, `v` | Copies of these CMCD keys, kept as separate columns so the viewer can filter on them. `sid`, `cid` and `e` are indexed. |
| `raw` | The payload as received |
| `data` | All parsed keys, as a JSON object |
| `err` | Parse or version error (NULL if none) |
| `warn` | Conformance warnings as a JSON array (NULL if none) |

You can query the database directly with the `sqlite3` command-line tool or any SQLite client. For example:

```sh
sqlite3 cmcd-data/cmcd-events.sqlite \
  "SELECT datetime(rx/1000,'unixepoch') AS received, e, sta, json_extract(data,'$.bl') AS bl
     FROM events WHERE sid = 'session-id-123' ORDER BY rx;"
```

Exports (`?format=json` / `jsonl`) produce one object per record with the fields `id`, `rx`, `src`, `ip`, `ua`, `origin`, `ct`, `raw`, `data`, `err` and `warn`.

To back up a running database, use `sqlite3 cmcd-events.sqlite ".backup backup.sqlite"` instead of copying the file, because a plain copy can miss changes still held in the `-wal` file.

> Versions before the SQLite change stored events in daily `events-YYYY-MM-DD.jsonl` files. Those files are not imported automatically.

## Security notes

- Anyone who can reach the URL can submit reports, because players can't authenticate. Limit access at the network or proxy level if needed, and rely on `max_body_bytes` and `max_payloads` to cap request size.
- Protect the viewer with `viewer_user` and `viewer_pass`, or with your web server's own auth.
- The stored IP addresses and User-Agents may be personal data. Set `store_client_info` to `false` if you don't need them, and choose a retention period that matches your data-protection policy.

## Credits

Written with [Claude Code](https://claude.com/claude-code), Anthropic's AI coding assistant (Claude Opus 5.5), working with Paul Higgs. The parsing, validation rules and event/key tables were checked against the published CTA-5004-B specification.

## License

BSD 3-Clause, see [LICENSE](LICENSE).
