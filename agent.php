<?php

include("functions.php");
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);
ini_set('log_errors', 1);
ini_set('error_log', 'syslog');

openlog("", LOG_PID, LOG_LOCAL0 );
// Some settings
$looptimer = 3;

$changes = array();
$state = array();
$state['self']['start'] = time(); // Unixtime
$state['self']['itteration'] = 0;
$state['self']['time'] = time();

// Touch wood
shell_exec("touch {$tmpfsurl}");

// Where the configs live
$cfgdir = "conf";
if(strstr($_SERVER['DOCUMENT_ROOT'], "web")) {
        $basedir = str_replace("/web", "", dirname($_SERVER['DOCUMENT_ROOT']));
} else {
        $basedir = "/home/{$_SERVER['LOGNAME']}/nomad-hotspot";
}
$cfgfile = "{$basedir}/{$cfgdir}/config.json";
$state['config'] = read_config($cfgfile);
$state['cfgfile'] = $cfgfile;
// Assume we start with no working internet
$state['internet']['dns'] = null;
$state['internet']['captive'] = null;
$state['internet']['latency'] = array();
$state['leases'] = array();
$state['clients'] = array();
$state['captive'] = array();

$state['log']['agent.php'] = array();
$state['if'] = array();
$state['stats'] = array();
$state['proc'] = array();


// Where the web files live
$webdir = $basedir ."/". "web";

// If we have a screen we dim the brightness
if(is_executable("/usr/local/bin/pwm"))
	exec("sudo pwm 19 1000000 135000");

chdir($basedir);

// Let's just start with seeing which interfaces work
$iflist = interface_status();
// Find the AP interface
$localif = if_address($iflist, fetch_ap_if($state));
$w = 0;
while (!isset($localif[0])) {
	$iflist = interface_status();
	$localif = if_address($iflist, fetch_ap_if($state));
	sleep(3);
	if($w > 10)
		break;
	$w++;
}
$address = $localif[0];
msglog("agent.php", "Found $localif address {$address} after $w tries");
start_webserver($address, $state['config']['port'], $webdir);

$i = 0;
$p = 0;
msglog("agent.php", "Starting up, entering loop");
// Initial load of firewall rules
msglog("agent.php", "Loading firewall rules");
restart_service("iptables.v4");
restart_service("iptables.v6");

exec("echo 600 | sudo tee /proc/sys/net/ipv4/neigh/wlan1/gc_stale_time");
exec("echo 600 | sudo tee /proc/sys/net/ipv4/neigh/wlan1/base_reachable_time");

while (true) {
	foreach ($iflist as $ifname => $iface) {
		// Skip Loopback
		if($ifname == "lo")
			continue;

		$iflist = interface_status();
		$state['if'][$ifname] = process_if_changes($state['if'], $iflist, $ifname);

	}
	// Check if the local configuration files match the system, update where neccesary, and restart services where needed.
	$chglist = compare_cfg_files($cfgdir);
	process_cfg_changes($chglist);

	// Check if we have all processes
	$state['proc'] = check_procs($procmap);

	// Check if we have a Sane DNS configuration
	$state['internet']['dns'] = working_dns($state['internet']['dns']);

	// Check if we can reach msft ncsi
	$state['internet']['captive'] = working_captive($state['internet']['captive']);

	$state['dns'] = parse_dhcp_nameservers($state);

	$state['lldp'] = fetch_lldp_neighbors();

	// Store latency
	if($i % 10 == 0) {
		// alternate between test
		if($p % 2 == 0) {
			$state['internet']['latency']['ping']= check_gw_latency($state);
		} else {
			$state['internet']['latency']['dnsping']= check_dns_latency($state);
		}
		$p++;
	}
	// store leases
	$state['clients']= parse_dnsmasq_leases();

	$state['self']['time'] = time();
	write_tmpfs($tmpfsurl, $state);
	$i++;

	$state['self']['itteration'] = $i;
	sleep ($looptimer);
}
