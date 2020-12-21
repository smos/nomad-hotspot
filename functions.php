<?php

// Shared memory for exchanging between proc and webserver
$shm_size = 32768;
$shm_id = create_shm($shm_size);
// You can list and delete these with ipcs and ipcrm -m 0

// Start PHP builtin webserver for the local interface on port 8000
function start_webserver($address, $port, $dir){
	// Start in a detached screen session
	echo "Starting webserver on adress {$address} and port {$port} in dir {$dir}\n";
	$cmd = "screen -d -m -S nomad-webserver php -S $address:$port -t $dir";
	exec($cmd, $out, $ret);
	if($ret > 0)
		echo "Failed to start webserver process in screen\n";

}

// Create 32kb shared memory block with system id of 0xff3
function create_shm($shm_size) {
	$shm_id = shmop_open(0xff3, "c", 0644, $shm_size);
	if (!$shm_id) {
		echo "Couldn't create shared memory segment\n";
	}
	return $shm_id;
}

// Lets write a test string into shared memory
function write_shm($shm_id, $state) {
	$shm_bytes_written = shmop_write($shm_id, serialize($state), 0);
	if ($shm_bytes_written != strlen(serialize($state))) {
		echo "Couldn't write the entire length of data to shm\n";
		return false;
	}
	return true;
}

function read_shm($shm_id, $shm_size) {
	// Now lets read the string back
	$state = unserialize(shmop_read($shm_id, 0, $shm_size));
	if (!is_array($state)) {
		echo "Couldn't read serialized array from shared memory block\n";
		return false;
	}
	return $state;
}
// Working DNS check
function working_dns($dns) {
	$gdns = check_gdns_rec();
	if(($gdns === true) && ($dnsok != "OK")){
		echo "Looks like we have a sane DNS for dns.google\n";
		return "OK";
	}
	if(($gdns === false) && ($dnsok != "NOK")) {
		echo "Looks like we can not resolve Public DNS yet, reload DNSmasq\n";
		service_restart("dnsmasq.conf");
		return "NOK";
	}
}	

// Working DNS check
function working_msftconnect($captive) {
	$msftconnect = check_msft_connect();
	//print_r($msftconnect);
	if(($msftconnect == "OK") && ($captive != "OK")){
		echo "Captive Portal check succeeded, looks like we have working Internet\n";
	}
	if(($msftconnect == "DNSERR") && ($captive != "DNSERR")) {
		echo "Looks like DNS doesn't work properly yet\n";
	}
	if(($msftconnect == "PORTAL") && ($captive != "PORTAL")) {
		echo "Looks like we we are stuck behind a portal, someone needs to log in\n";
	}
	return $msftconnect;
}

function check_msft_connect() {
	// Catch timeouts?
	// Create a stream
	$opts = array(
	  'http'=>array(
		'method'=>"GET",
		'timeout'=>2,
		'header'=>"Accept-language: en\r\n" .
				  "Cookie: foo=bar\r\n"
	  )
	);
	$context = stream_context_create($opts);

	$url = "http://www.msftconnecttest.com/connecttest.txt";
	$string = "Microsoft Connect Test";
	// check DNS
	$cmd = "host -W 1 www.msftconnecttest.com";
	exec($cmd, $out, $ret);
	if ($ret > 0)
		return "DNSERR";
	
	$test = file_get_contents($url, false, $context, 0, 64);
	
	if($test == trim($string))
		return "OK";

	if($test != trim($string))
		return "PORTAL";
	
}

function check_procs($procmap) {
	$proccount = array();
	foreach ($procmap as $file => $procname) {
		switch($file) {
			case "wpa_supplicant.conf":
			case "dnsmasq.conf":
			case "hostapd.conf":
			case "webserver":
			case "dhcpcd.conf":
				$proccount[$procname] = check_proc($file);
				if($proccount[$procname] == 0)
					echo "We are missing a process called {$procname}\n";
				break;
			default:
				echo "What is this mythical process for file '{$file}' of which you speak?\n";
				break;
		}
	}
	return $proccount;
	
}

// Check process name
function check_proc($file) {
	global $procmap;
	
	if (!isset($procmap[$file]))
		return false;
	
	$cmd = "ps auxww| awk '/{$procmap[$file]}/ {print $1}'";
	exec($cmd, $out, $ret);
	if($ret > 0)
		echo "Failed to check process for {$file} to {$procmap[$file]}\n";

	return count($out);
	
}

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

// Return interface addresses
function if_address($iflist, $ifname) {
	// Does this interface even exist?
	if(!isset($iflist[$ifname]))
		return false;

	$add = array();
	
	//print_r($iflist[$ifname]);
	foreach($iflist[$ifname]['addr_info'] as $index => $address) {
		if($address['scope'] != "global")
			continue;
		
		$add[] = $address['local'];
		
	}
	return $add;
}


// Return interface prefixlen
function if_prefix($iflist, $ifname) {
	// Does this interface even exist?
	if(!isset($iflist[$ifname]))
		return false;

	$add = array();
	
	//print_r($iflist[$ifname]);
	foreach($iflist[$ifname]['addr_info'] as $index => $address) {
		if($address['scope'] != "global")
			continue;
		
		$add[] = $address['local'] ."/". $address['prefixlen'];
		
	}
	return $add;
}

// Check if the Google DNS is reachable
function check_gdns_rec(){
	// What should be returned for dns.google
	/*
	dns.google has address 8.8.4.4
	dns.google has address 8.8.8.8
	dns.google has IPv6 address 2001:4860:4860::8888
	dns.google has IPv6 address 2001:4860:4860::8844
	*/
	$list = array(
		"8.8.8.8" => true,
		"8.8.4.4" => true,
		"2001:4860:4860::8888" => true,
		"2001:4860:4860::8844" => true,
		);
	$f = 0;
	
	$cmd = "host -W 1 dns.google";
	exec($cmd, $out, $ret);
	foreach($out as $line){
		$dns = explode(" ", $line);
		$add = end($dns);
		if(isset($list[$add]))
			$f++;
	}
	if(count($list) == $f)
		return true;
	return false;
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
				break;
		}
	}
}

function restart_service($file) {
	echo "Restart service for config file '{$file}'\n";
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

	echo "Copy config file '{$file}' to '{$cfgmap[$file]}'\n";
	$cmd = "sudo cp -a {$cfgdir}/{$file} {$cfgmap[$file]}";
	exec($cmd, $out, $ret);
	if($ret > 0)
		echo "Failed to copy config {$file} to {$cfgmap[$file]}\n";
}
