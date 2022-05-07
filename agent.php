<?php

include("functions.php");

// Some settings
$looptimer = 3;

$changes = array();
$state = array();
$state['config'] = array();
$state['config']['port'] = 8000;
$state['if'] = array();
$state['stats'] = array();
$state['proc'] = array();
$state['time'] = time();
// Assume we start with no working internet
$state['internet']['dns'] = null;
$state['internet']['captive'] = null;
$state['internet']['ping'] = false;
$state['clients'] = array();
// Where the configs live
$basedir = "/home/{$_SERVER['LOGNAME']}/nomad-hotspot";
$cfgdir = "conf";
// Where the web files live
$webdir = $basedir ."/". "web";

// print_r($_SERVER);

// If we have a screen we dim the brightness
if(is_executable("/usr/local/bin/pwm"))
	exec("sudo pwm 19 1000000 135000");

chdir($basedir);

// Let's just start with seeing which interfaces work
$iflist = interface_status();
// Find the AP interface
$localif = if_address($iflist, "wlan0");
$w = 0;
while (!isset($localif[0])) {
	$iflist = interface_status();
	$localif = if_address($iflist, "wlan0");
	sleep(3);
	if($w > 10)
		break;
	$w++;
}
$address = $localif[0];
echo "Found wlan0 address {$address} after $w tries\n";
start_webserver($address, $state['config']['port'], $webdir);

$i = 0;
$p = 0;
echo "Starting up, entering loop\n";
// Initial load of firewall rules
echo "Loading firewall rules\n";
restart_service("iptables.v4");
restart_service("iptables.v6");

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
	$state['internet']['captive'] = working_msftconnect($state['internet']['captive']);

	// Store latency
	if($p > 59 ) {
		$state['internet']['ping'] = ping();
		$p = 0;
	}
	// store leases
	$state['leases']= parse_dnsmasq_leases();

	$state['time'] = time();
	write_shm($shm_id, $state);
	sleep ($looptimer);
	$i++;
	$p++;
}
