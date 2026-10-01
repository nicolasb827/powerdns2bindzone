#!/bin/env php56
<?php

/**
 * @copyright 2026 Nicolas Belan <nicolas.belan@gmail.com>
 * @author Nicolas Belan <nicolas.belan@gmail.com>
 * @license BSD (2 clause) <http://www.opensource.org/licenses/BSD-2-Clause>
 */

$pdns_db = [];
$zone_ns = [];
$zone_adm = '';
$zone_adm_replaced = '';
$knotc = 'knotc';
$keymgr = 'keymgr';
$kzonecheck = 'kzonecheck';

// add following code to gmysql.conf and customize values
/*
$pdns_db = [ 'host' => 'localhost', 'user' => 'pdns_user', 'pass' => 'pdns_pass', 'name' => 'pdns_db_name' ];
$zone_ns = [ 'ns1.example.com', 'ns2.example.com' ];
$zone_adm = 'support.example.com';
$knotc = '/usr/sbin/knotc';

Ensure you are using config DB:
# /etc/systemd/system/knot.service.d/override.conf
[Service]
ExecStart=
ExecStart=/usr/sbin/knotd -m "$KNOT_CONF_MAX_SIZE" -C /var/lib/knot/confdb

if zones are still empty on restart:
knotc conf-begin
for z in $(kjournalprint -z /var/lib/knot/journal | grep -vx 'catz\.'); do knotc conf-set "zone[$z]"; done
knotc conf-commit

*/

include_once __DIR__ . '/gmysql.conf';

$knot_shell = fopen(__DIR__ . '/knot.sh', 'w');
if (false === $knot_shell) die ('Unable to open knot.sh');
$knotc_export = tempnam(sys_get_temp_dir(), 'knotc');
fprintf($knot_shell, "#! /usr/bin/env bash" . PHP_EOL);
fprintf($knot_shell, "if [ ! -d /var/lib/knot/zones ]; then mkdir -p /var/lib/knot/zones; chown knot:knot /var/lib/knot/zones; fi" . PHP_EOL);
fprintf($knot_shell, "%s conf-export %s" . PHP_EOL, $knotc, $knotc_export);
fprintf($knot_shell, "echo 'Configuration saved into %s'" . PHP_EOL . PHP_EOL, $knotc_export);
fprintf($knot_shell, "systemctl stop knot; rm -f /var/lib/knot/*.zone; systemctl start knot; " . PHP_EOL . PHP_EOL, $knotc_export);
fprintf($knot_shell, "set -e" . PHP_EOL);
fprintf($knot_shell, "%s conf-import --force /etc/knot/knot.conf" . PHP_EOL, $knotc);
fprintf($knot_shell, "%s conf-begin" . PHP_EOL, $knotc);
fprintf($knot_shell, "%s conf-unset 'zone'" . PHP_EOL, $knotc);
fprintf($knot_shell, "%s conf-set zone.domain catz" . PHP_EOL, $knotc);
fprintf($knot_shell, "%s conf-set zone.template catalog" . PHP_EOL, $knotc);
fprintf($knot_shell, "%s conf-commit" . PHP_EOL . PHP_EOL, $knotc);

fprintf($knot_shell, "trap '%s zone-abort --; %s conf-abort' EXIT" . PHP_EOL, $knotc, $knotc);

fprintf($knot_shell, "%s zone-begin catz" . PHP_EOL, $knotc);
{
    fprintf($knot_shell, '%s zone-set %s. @ IN SOA %s. %s. $(date +"%s") 7200 3600 604800 43200' . PHP_EOL, $knotc, 'catz', $zone_ns[0], $zone_adm);
    foreach ($zone_ns as $ns) {
        fprintf($knot_shell, "%s zone-set %s. @ IN NS %s." . PHP_EOL, $knotc, 'catz', $ns);
    }
}
fprintf($knot_shell, "%s zone-commit catz" . PHP_EOL, $knotc);


$record_template = [
        'A' => [],
        'CNAME' => [],
        'NS' => [],
        'MX' => [],
        'TXT' => [],
        'AAAA' => [],
    // 'CAA' => [],
        'DNSKEY' => [],
    // 'DS' => [], <<== set by knot
        'SOA' => [],
        'SPF' => [],
        'SRV' => [],
        'SSHFP' => [],
        'PTR' => []
];

$zones = [];
$db = new mysqli($pdns_db['host'], $pdns_db['user'], $pdns_db['pass'], $pdns_db['name']);
/** table domains is created like
 * CREATE TABLE `domains` (
 * `id` int(11) NOT NULL AUTO_INCREMENT,
 * `name` varchar(255) NOT NULL,
 * `master` varchar(128) DEFAULT NULL,
 * `last_check` int(11) DEFAULT NULL,
 * `type` varchar(6) NOT NULL,
 * `notified_serial` int(11) DEFAULT NULL,
 * `account` varchar(40) DEFAULT NULL,
 * `userid` int(11) NOT NULL,
 * PRIMARY KEY (`id`),
 * UNIQUE KEY `name_index` (`name`)
 * ) ENGINE=InnoDB AUTO_INCREMENT=417 DEFAULT CHARSET=latin1
 */
if ($domains_result = $db->query('SELECT * FROM domains')) {
    while ($domain = $domains_result->fetch_assoc()) {
        $domain_name = strtolower($domain['name']);
        echo "checking $domain_name: " . 'SELECT * FROM records WHERE domain_id=' . $domain['id'] . PHP_EOL;
        if ($records_result = $db->query('SELECT * FROM records WHERE domain_id=' . $domain['id'] . ' AND TYPE IS NOT NULL')) {
            $zone_records = $record_template;
            while ($record = $records_result->fetch_assoc()) {
                // remove '.domain.tld'
                $record['name'] = strtolower(trim(str_replace('.' . $domain_name, null, $record['name'])));
                if (empty($record['name'])) {
                    $record['name'] = '@';
                }
                if ($domain_name == $record['name']) {
                    $record['name'] = '@';
                }
                switch ($record['type']) {
                    case 'A':
                    case 'AAAA':
                    case 'CAA':
                    case 'DNSKEY':
                    case 'DS':
                    case 'SSHFP':
                    case 'CNAME':
                    case 'NS':
                    case 'SPF':
                    case 'SRV':
                    case 'PTR':
                    case 'TXT':
                    case 'MX':
                    case 'SOA':
                        $zone_records[$record['type']][] = $record;
                        break;
                    default:
                        if (empty($record['type'])) {
                            print_r($record);
                        }
                        echo($record['type'] . ' is not supported.' . PHP_EOL);
                }
            }
            $records_result->free();
            $zones[$domain_name] = $zone_records;
        }
    }
    $domains_result->free();
}
$db->close();

if (is_array($zones) && count($zones) > 0) {

    foreach ($zones as $domain_name => $zone_records) {
        fprintf($knot_shell, "%s conf-begin" . PHP_EOL, $knotc);
        fprintf($knot_shell, "%s conf-set zone.domain %s" . PHP_EOL, $knotc, $domain_name);
        fprintf($knot_shell, "%s conf-set zone.template default" . PHP_EOL, $knotc);
        // fprintf($knot_shell, "%s conf-set 'zone[%s.]'" . PHP_EOL, $knotc, $domain_name);
        fprintf($knot_shell, "%s conf-commit" . PHP_EOL, $knotc);
        fprintf($knot_shell, 'SOA=$(date +"%s")' . PHP_EOL, '%s');
        fprintf($knot_shell, "set +e; %s zone-read %s. @ SOA 2>&1 > /dev/null" . PHP_EOL, $knotc, $domain_name);
        fprintf($knot_shell, 'if [ $? -eq 0 ]; then' . PHP_EOL);
        fprintf($knot_shell, '    %s %s generate algorithm=ECDSAP256SHA256 ksk=true zsk=true' . PHP_EOL, $keymgr, $domain_name);
        fprintf($knot_shell, "    SOA=\$(%s zone-read %s. @ SOA | awk ' { print $7; } ')" . PHP_EOL, $knotc, $domain_name);
        fprintf($knot_shell, "    SOA=\$((1+\$SOA))" . PHP_EOL, $knotc, $domain_name);
        fprintf($knot_shell, 'fi' . PHP_EOL);
        fprintf($knot_shell, "set -e; %s zone-begin %s." . PHP_EOL, $knotc, $domain_name);
        $indent = '             ';
        $lines = array();
        $lines_ns = array();
        $lines_soa = ['$TTL 3600', null, '@ IN SOA ' . $zone_ns[0] . '. ' . $zone_adm . '. (', $indent . time(), $indent . '7200', $indent . '3600', $indent . '604800', $indent . '43200 )', null];
        foreach ($zone_ns as $ns) {
            $lines_ns[] = '@ IN NS ' . $ns . '.';
        }

        $knot_lines = array();

        // handle first SOA and NS record
        fprintf($knot_shell, "%s zone-set %s. @ IN SOA %s. %s. \$SOA 7200 3600 604800 43200" . PHP_EOL, $knotc, $domain_name,
                $zone_ns[0], $zone_adm);

        foreach ($lines_ns as $dns) {
            fprintf($knot_shell, "%s zone-set %s. %s" . PHP_EOL, $knotc, $domain_name, $dns);
        }

        fprintf($knot_shell, PHP_EOL);
        foreach (array_keys($record_template) as $record_type) {
            $append = '';
            switch ($record_type) {
                case 'CNAME':
                case 'PTR':
                    $append = '.';
                case 'SPF':
                case 'TXT':
                case 'A':
                case 'AAAA':
                    if (array_key_exists($record_type, $zone_records)) {
                        foreach ($zone_records[$record_type] as $entry) {
                            $entry['content'] = preg_replace('/\.\.$/', '.', trim($entry['content']) . $append);
                            $entry['content'] = preg_replace(array('/^"/', '/"$/'), array('"\\"', '\\""'), trim($entry['content']));
                            fprintf($knot_shell, '%s zone-set %s. %s' . PHP_EOL, $knotc, $domain_name,
                                    join(' ', array($entry['name'], $entry['ttl'], $record_type, $entry['content']))
                            );
                        }
                    }
                    break;
                case 'SRV':
                case 'MX':
                    $append = '.';
                case 'CAA':
                case 'DS':
                case 'SSHFP':
                    if (array_key_exists($record_type, $zone_records))
                        foreach ($zone_records[$record_type] as $entry) {
                            $entry['content'] = preg_replace('/\.\.$/', '.', trim($entry['content']) . $append);
                            $entry['content'] = preg_replace(array('/^"/', '/"$/'), array('"\\"', '\\""'), trim($entry['content']));
                            fprintf($knot_shell, '%s zone-set %s. %s' . PHP_EOL, $knotc, $domain_name,
                                    join(' ', array($entry['name'], $entry['ttl'], $record_type, $entry['prio'], $entry['content']))
                            );
                        }
                    break;
                case 'NS':
                    // keep only subdomains
                    if (array_key_exists($record_type, $zone_records))
                        foreach ($zone_records[$record_type] as $entry) {
                            if ('@' !== $entry['name']) {
                                $entry['content'] = preg_replace('/\.\.$/', '.', trim($entry['content']) . '.');
                                $entry['content'] = preg_replace(array('/^"/', '/"$/'), array('"\\"', '\\""'), trim($entry['content']));
                                fprintf($knot_shell, '%s zone-set %s. %s' . PHP_EOL, $knotc, $domain_name,
                                        join(' ', array($entry['name'], $entry['ttl'], $record_type, $entry['content']))
                                );
                            }
                        }
                    break;
                case 'SOA':
                case 'DNSKEY':
                    break;
                default:
                    echo "missing type $record_type" . PHP_EOL;
                    break;
            }
        }
        fprintf($knot_shell, "%s zone-commit %s." . PHP_EOL . PHP_EOL, $knotc, $domain_name);
        fprintf($knot_shell, 'CNT=$(%s %s list | wc -l)' . PHP_EOL, $keymgr, $domain_name);
        # fprintf($knot_shell, "%s %s list  | awk ' { print $1; } ' | while read K; do keymgr %s delete \$K; done" . PHP_EOL, $keymgr, $domain_name, $domain_name);
        fprintf($knot_shell, 'if [ $CNT -le 0 ]; then KEY=$(%s %s generate algorithm=ECDSAP256SHA256 ksk=true zsk=true); fi' . PHP_EOL, $keymgr, $domain_name);
        // fprintf($knot_shell, "%s %s generate algorithm=ED25519 ksk=true zsk=true" . PHP_EOL, $keymgr, $domain_name);
        fprintf($knot_shell, "%s zone-sign %s." . PHP_EOL, $knotc, $domain_name);
        fprintf($knot_shell, "%s zone-flush %s." . PHP_EOL, $knotc, $domain_name);
        fprintf($knot_shell, "%s zone-status %s." . PHP_EOL, $knotc, $domain_name);
        // fprintf($knot_shell, "%s zone-read %s." . PHP_EOL, $knotc, $domain_name);
        fprintf($knot_shell, "%s zone-read %s. > /var/lib/knot/zones/%s.zone" . PHP_EOL, $knotc, $domain_name, $domain_name);
        echo "written " . $domain_name . PHP_EOL;
    }
    fprintf($knot_shell, "trap 'true' EXIT" . PHP_EOL, $knotc, $knotc);
    //fprintf($knot_shell, "%s zone-backup +backupdir /tmp" . PHP_EOL, $knotc);

}

?>
