# AGENTS.md

## What this is

Two standalone PHP CLI scripts that migrate DNS zones from a PowerDNS MySQL backend to zone files:

- `powerdns2bindzone.php` — writes BIND-style zones to `./bind/<zone>.zone`
- `powerdns2knot.php` — writes Knot-style zones to `./knot/<zone>.zone`

This is a migration utility, not a backup tool: it regenerates SOA/serials and uses the configured name servers, so output is not byte-identical to the source data.

## Running

```sh
php powerdns2bindzone.php   # or: php56 powerdns2knot.php on rhel compatible servers
```

- Requires PHP 5.6+ with the `mysqli` extension. No framework, composer, build step, tests, or CI.
- The shebang `#!/bin/env php56` is non-standard and usually does not exist — invoke via `php` explicitly.
- powerdns2bindzone.php is not used here, we keep it for memory

## Configuration

All configuration lives in `gmysql.conf` (DB credentials, `$zone_ns`, `$zone_adm`), which both scripts `include_once`. It is gitignored and contains real credentials — never commit it. Copy the commented template at the top of either script to create it.

## Editing the scripts

- The two scripts are near-identical forks. Bug fixes and formatting changes generally need to be mirrored in both.
- Known intentional differences: `powerdns2bindzone.php` filters `disabled = 0` records and selects `id,name` from `domains`; `powerdns2knot.php` selects all records and `SELECT * FROM domains`. Don't "fix" these asymmetries without intent.
- Assumes the stock PowerDNS MySQL schema: `domains(id, name)` and `records(domain_id, ttl, name, content, prio, type, disabled)`.
- Handled record types: A, AAAA, CAA, CNAME, DNSKEY, DS, MX, NS, SOA, SPF, SRV, SSHFP, TXT. Anything else in `records` is silently dropped. (README also lists NULL, but the code does not handle it.)
- Output quirks: SOA serial is `time()` at generation; default TTL 3600; all names are lowercased; FQDNs get a trailing dot.

## Verification

There is no test suite. To verify changes, run the script against a PowerDNS MySQL database and inspect the generated zone files in knot.sh.
