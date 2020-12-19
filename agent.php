<?php

include("functions.php");

// Some settings
$looptimer = 1;

echo "Starting up, entering loop\n";
$status = array();
$changes = array();

while (true) {
	// Let's just start with seeing which interfaces work
	$iflist = interface_status();
	foreach ($iflist as $ifname => $iface) {
		// Skip Loopback
		if($ifname == "lo")
			continue;

		// $changes[$ifname] = false;
		if(!isset($status[$ifname])) {
			// New interface!
			echo "Adding interface {$ifname}, status {$iface['operstate']}\n";
			$status[$ifname] = $iflist[$ifname];
		} else {
			// We already have this interface, check if it changed
			if((if_state($status, $ifname) != if_state($iflist, $ifname))) {
				echo "{$ifname} moved from '". if_state($status, $ifname) ."' to '". if_state($iflist, $ifname) ."'\n";
				$changes[$ifname] = true;
			} else {
				$changes[$ifname] = false;
			}

			// Proceed to reload configuration files required based on this new information
			$chglist = compare_cfg_files($cfgdir);
			process_cfg_changes($chglist);

			// If we acted on the changes, save current state 
			$status[$ifname] = $iflist[$ifname];
		}
	}
	sleep ($looptimer);
}
