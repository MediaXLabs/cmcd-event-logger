# CMCD Event Logger

`cmcd-logger.php` is a single PHP script that receives CMCD (Common Media Client Data) reports from media players. CMCD is defined in **CTA-5004-B**. The script:

- accepts reports sent by **Event Mode** HTTP POST, and also by the **query-argument** (`?CMCD=`) and **header** (`CMCD-Request/Object/Status/Session`) transmission modes
- stores every report in a daily log file, with the time it arrived, the client IP address and User-Agent, the raw text and the parsed keys
- checks each report against the CTA-5004-B rules and stores any warnings with it
- deletes stored reports once they are older than a configurable retention period (**default: 6 months**)
- serves a web page that lists stored events, with filters, auto-refresh and JSON, CSV or JSONL export

There is no database and no extra PHP extension to install.

---

## Requirements

- PHP **8.1 or later**, with only the extensions that are always built in (`json`, `pcre`, `date`)
- A web server that can run PHP, such as Apache with mod_php or PHP-FPM, or nginx with PHP-FPM. PHP's built-in server is fine for local testing.
- The web server user needs write access to the data directory
- HTTPS is recommended (§6 item 14 of the spec)

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
| `data_dir` | `CMCD_DATA_DIR` | `./cmcd-data` | Where daily `events-YYYY-MM-DD.jsonl` files are written |
| `retention` | `CMCD_RETENTION` | `P6M` | How long reports are kept. Use an ISO 8601 duration (`P6M`, `P90D`, `P1Y`) or a number of days (`180`) |
| `purge_interval` | – | `3600` | Minimum number of seconds between sweeps for expired files |
| `max_body_bytes` | – | `1048576` | Largest POST body accepted (larger bodies get `413`) |
| `max_payloads` | – | `5000` | Most records stored from a single POST |
| `cors_allow_origin` | `CMCD_CORS_ORIGIN` | `*` | Value of `Access-Control-Allow-Origin` (`''` turns CORS headers off) |
| `store_client_info` | – | `true` | Whether to record the client IP address and User-Agent |
| `viewer_user` / `viewer_pass` | `CMCD_VIEW_USER` / `CMCD_VIEW_PASS` | empty | If set, the viewer requires HTTP Basic auth. Report submission is never authenticated. |
| `page_size` | – | `100` | Events shown per page in the viewer |
| `max_export_rows` | – | `100000` | Most rows in one export |
| `display_timezone` | – | `UTC` | Time zone used to display times in the viewer and in CSV exports |

### Retention

Reports are stored in one file per UTC day. A purge runs at most once per `purge_interval`, triggered by a POST or a viewer request, and deletes any whole-day file that is entirely older than the retention cutoff. The viewer and exports also hide any record older than the cutoff, so what you see always matches the retention setting.

You don't need a cron job. On a server that receives little traffic you can force a sweep at a set time by requesting the viewer URL.

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
| `500` | The data directory could not be written |

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
  - free-text search
  - "only reports with issues"
- Click a `sid` or `cid` value to filter on it. Filtering on one `sid` shows that player session's timeline.
- **Details** expands a row to show any warnings and the full stored record: the raw payload, the parsed keys, the client IP address and the User-Agent.
- **Auto-refresh** reloads the page every 5, 15 or 60 seconds while you watch a test.
- **Exports** use the current filters:
  - `?format=json`
  - `?format=csv`
  - `?format=jsonl`

Example: `cmcd-logger.php?sid=session-id-123&e=ps&format=csv`

## Storage format

`data_dir/events-YYYY-MM-DD.jsonl` contains one JSON object per line:

```json
{"id":"eda8b620a946aa07","rx":1794915715774,"src":"body","ip":"203.0.113.7","ua":"…",
 "ct":"application/cmcd","raw":"e=ps,sid=\"s1\",sta=p,ts=1764269150889,v=2",
 "data":{"e":"ps","sid":"s1","sta":"p","ts":1764269150889,"v":2}}
```

| Field | Content |
|---|---|
| `rx` | Time the server received the report (ms since epoch, UTC) |
| `src` | `body`, `header`, `query`, `json` or `form` |
| `err` | Present only if the report has a parse or version error |
| `warn` | Present only if the report has conformance warnings |

The files are plain text, so you can also process them with `jq`, `grep` and similar tools.

## Security notes

- Anyone who can reach the URL can submit reports, because players can't authenticate. Limit access at the network or proxy level if needed, and rely on `max_body_bytes` and `max_payloads` to cap request size.
- Protect the viewer with `viewer_user` and `viewer_pass`, or with your web server's own auth.
- The stored IP addresses and User-Agents may be personal data. Set `store_client_info` to `false` if you don't need them, and choose a retention period that matches your data-protection policy.

## License

BSD 3-Clause, see [LICENSE](LICENSE).
