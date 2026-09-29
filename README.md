# PowerDNS to ~~Bind~~Knot Zone Generator

## Overview
The PowerDNS to ~~Bind~~Knot Zone Generator is a simple PHP script to generate shell commands about zones from a PowerDNS MySQL backend. It is intended as a utility to make migrating zones from a PowerDNS server to a ~~Bind~~Knot server, not as a straight backup script. The generated zones use configurable name servers and create new SOA and serials, etc.

### Supports the following record types:
* A
* CNAME
* A
* AAAA
* MX
* TXT
* NULL
* PTR
* NS
* SOA
* SPF
* CAA
* DNSKEY
* DS
* SSHFP
* SRV

## Environment
* Linux
* PHP 5.6 +
* PowerDNS (with MySQL backend)

## Notes
* This script is designed to run on the command line.

## Howto
Download powerdns2bindzone.php and edit the configuration section at the beginning of the file.
```php
$pdns_db = [ 'host' => 'localhost', 'user' => 'pdns_user', 'pass' => 'pdns_pass', 'name' => 'pdns_db_name' ];
$zone_ns = [ 'ns1.example.com', 'ns2.example.com' ];
$zone_adm = 'support.example.com';
```
You can specify as many name servers in $zone_ns as you need, as long as the first value is the primary name server.
Run the script. It will create a tmp directory in the location you run it from and save the zone files there.

## Example Generated Zone (fake data)
```shell
knotc zone-begin example.com
knotc zone-set example.com. @ IN SOA ns1.example.net dns.example.com 2021010700 14400 7200 3600000 86400
knotc zone-set example.com. @ IN NS ns1.example.net.
knotc zone-set example.com. @ IN NS ns2.example.net.
knotc zone-set example.com. @ IN NS ns3.example.net.
knotc zone-set example.com. www 3600 A 192.168.1.1
knotc zone-set example.com. @ 3600 A 192.168.1.1
knotc zone-set example.com. _domainkey 3600 TXT "v=DKIM1; t=y; o=~"
knotc zone-set example.com. www 3600 AAAA fc00::1
knotc zone-commit example.com.
```
## License
This project is BSD (2 clause) licensed.
