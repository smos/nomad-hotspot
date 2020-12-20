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
$state['dnsok'] = false;
// Config files we know about
$cfgmap = array(
			"dnsmasq.conf" => "/etc/dnsmasq.conf",
			"dhcpcd.conf" => "/etc/dhcpcd.conf",
			"hostapd.conf" => "/etc/hostapd/hostapd.conf",
			"wpa_supplicant.conf" => "/etc/wpa_supplicant/wpa_supplicant.conf",
			"sysctl-routed-ap.conf" => "/etc/sysctl.d/sysctl-routed-ap.conf",
			);
// Processes we know about
$procmap = array(
			"dnsmasq.conf" => "dnsmasq",
			"dhcpcd.conf" => "dhcpcd",
			"hostapd.conf" => "hostapd",
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
		// $changes[$ifname] = false;
		if(!isset($state['if'][$ifname])) {
			// New interface!
			echo "Found interface {$ifname}, status {$iface['operstate']}, addresses ". implode(',', if_prefix($iflist, $ifname)) ."\n";
			$state['if'][$ifname] = $iflist[$ifname];
		} else {
			// We already have this interface, check if it changed
			if((if_state($state['if'], $ifname) != if_state($iflist, $ifname))) {
				echo "{$ifname} moved from '". if_state($state['if'], $ifname) ."' to '". if_state($iflist, $ifname) ." with addresses ". implode(',', if_address($iflist, $ifname)) .".'\n";
				$changes[$ifname] = true;
			} else {
				$changes[$ifname] = false;
			}

			// Check if the local configuration files match the system, update where neccesary, and restart services where needed.
			$chglist = compare_cfg_files($cfgdir);
			process_cfg_changes($chglist);

			// Check if we have all processes
			$state['proc'] = check_procs($procmap);

			// save current interface state to the state array. 
			$state['if'][$ifname] = $iflist[$ifname];
		}
	}
	// Check if we have a Sane DNS configuration
	$state['dnsok'] = working_dns($state['dnsok']);


	write_shm($shm_id, $state);	
	sleep ($looptimer);
}
