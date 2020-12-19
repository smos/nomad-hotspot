<?php

// Config files we know about
$cfgmap = array(
			"dnsmasq.conf" => "/etc/dnsmasq.conf",
			"dhcpcd.conf" => "/etc/dhcpcd.conf",
			"hostapd.conf" => "/etc/hostapd/hostapd.conf",
			"wpa_supplicant.conf" => "/etc/wpa_supplicant/wpa_supplicant.conf",
			"sysctl-routed-ap.conf" => "/etc/sysctl.d/sysctl-routed-ap.conf",
			);
$cfgdir = "conf";

// Get all our interface information, index by ifname
function interface_status() {
	$cmd = "ip -j address show ";
	exec($cmd, $out, $ret);
	if($ret > 0)
		return false;

	$iflist = array();
	$ifjson = json_decode($out[0], true);

	foreach($ifjson as $key => $if){
		$iflist[$if['ifname']] = $if;
	}

	return $iflist;
}

// Return interface status
function if_state($iflist, $name){
	// Does this interface even exist?
	if(!isset($iflist[$name]))
		return false;

	return $iflist[$name]['operstate'];
}

// Let's retrieve our list of configuration files and return an array with mtimes
function cfg_list($dir) {
	$files = array_diff(scandir($dir), array('..', '.'));
	//print_r($files);
	foreach($files as $file) {
		$mtimes[$file] = filemtime("{$dir}/{$file}");
	}
	return $mtimes;
}

// Returns list of files that have changed
function compare_cfg_files ($dir) {
		$cfglist = cfg_list($dir);
		$chglist = array();
		global $cfgmap;
		// If the local file is newer then the installed file we need to proces on it.
		foreach ($cfglist as $file => $mtime) {
			if(file_exists($cfgmap[$file])) {
				if($mtime > filemtime($cfgmap[$file]))
					$chglist[$file] = $mtime;
			} else {
				// Doesn't exist yet
				$chglist[$file] = 0;
			}
		}
		return $chglist;
}

function process_cfg_changes($chglist) {
	foreach($chglist as $file => $mtime) {
		switch($file) {
			case "sysctl-routed-ap.conf":
				copy_config($file);
				break;
			case "wpa_supplicant.conf":
			case "dnsmasq.conf":
			case "hostapd.conf":
			case "dhcpcd.conf":
				copy_config($file);
				restart_service($file);
				break;
			default:
				echo "What is this mythical config file '{$file}' of which you speak?\n";
		}
	}
}

function restart_service($file) {
	switch($file) {
			case "dnsmasq.conf":
				$cmd = "sudo service dnsmasq reload";
				exec($cmd, $out, $ret);
				if($ret > 0)
					echo "Failed to restart service for {$file} to {$cfgmap[$file]}\n";
				break;
			case "hostapd.conf":
				$cmd = "sudo service hostapd reload";
				exec($cmd, $out, $ret);
				if($ret > 0)
					echo "Failed to restart service for {$file} to {$cfgmap[$file]}\n";
				break;
			case "dhcpcd.conf":
				$cmd = "sudo service dhcpcd reload";
				exec($cmd, $out, $ret);
				if($ret > 0)
					echo "Failed to restart service for {$file} to {$cfgmap[$file]}\n";
				break;
			case "wpa_supplicant.conf":
				$cmd = "sudo wpa_cli -i wlan1 reconfigure";
				exec($cmd, $out, $ret);
				if($ret > 0)
					echo "Failed to restart service for {$file} to {$cfgmap[$file]}\n";
				break;
			default:
				echo "What is this mythical service file '{$file}' of which you speak?\n";
				break;
	}
}

function copy_config($file) {
	global $cfgmap;
	global $cfgdir;

	$cmd = "sudo cp -a {$cfgdir}/{$file} {$cfgmap[$file]}";
	exec($cmd, $out, $ret);
	if($ret > 0)
		echo "Failed to copy config {$file} to {$cfgmap[$file]}\n";
}
