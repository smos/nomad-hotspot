<?php

include("functions.php");

// Some settings
$looptimer = 1;

$changes = array();
$state = array();
$state['config'] = array();
$state['config']['port'] = 8000;
$state['if'] = array();
$state['proc'] = array();
$state['time'] = time();
// Assume we start with no working internet
$state['internet']['dns'] = null;
$state['internet']['captive'] = null;
$state['internet']['ping'] = false;
// Config files we know about
$cfgmap = array(
			"dnsmasq.conf" => "/etc/dnsmasq.conf",
			"dhcpcd.conf" => "/etc/dhcpcd.conf",
			"hostapd.conf" => "/etc/hostapd/hostapd.conf",
			"client.ovpn" => "/etc/openvpn/client.conf",
			"client.ovpn.login" => "/etc/openvpn/client.ovpn.login",
			"wpa_supplicant.conf" => "/etc/wpa_supplicant/wpa_supplicant.conf",
			"sysctl-routed-ap.conf" => "/etc/sysctl.d/sysctl-routed-ap.conf",
			);
// Processes we know about
$procmap = array(
			"dnsmasq.conf" => "dnsmasq",
			"dhcpcd.conf" => "dhcpcd",
			"hostapd.conf" => "hostapd",
			"client.ovpn" => "openvpn",			
			"wpa_supplicant.conf" => "wpa_supplicant",
			"webserver" => "php",
			);
// Where the configs live
$cfgdir = "conf";
// Where the web files live
$webdir = "web";

// Let's just start with seeing which interfaces work
$iflist = interface_status();
// Find the AP interface
$localif = if_address($iflist, "wlan0");
$address = $localif[0];
start_webserver($address, $state['config']['port'], $webdir);

echo "Starting up, entering loop\n";
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

	$state['time'] = time();
	write_shm($shm_id, $state);	
	sleep ($looptimer);
}
