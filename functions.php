<?php

// Shared memory for exchanging between proc and webserver
$shm_size = 32768;
$shm_id = create_shm($shm_size);
// You can list and delete these with ipcs and ipcrm -m 0
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

// Start PHP builtin webserver for the local interface on port 8000
function start_webserver($address, $port, $dir){
	// Start in a detached screen session
	echo "Starting webserver on adress {$address} and port {$port} in dir {$dir}\n";
	$cmd = "screen -d -m -S nomad-webserver php -S $address:$port -t $dir";
	exec($cmd, $out, $ret);
	if($ret > 0)
		echo "Failed to start webserver process in screen\n";

}

function iw_info($ifstate, $ifname) {
	if(!isset($ifstate[$ifname]))
		return false;
	// Don't scan on our eth0 interface ;)
	if(($ifname == "eth0") || ($ifname == "tun0"))
		return null;

	// List wireless interface statistics
	// iw wlan0 info
	$cmd = "iw {$ifname} info";
	exec($cmd, $out, $ret);
	if($ret > 0)
		echo "Failed to fetch wireless info {$cmd}\n";

	$iw_state = array();
	foreach($out as $line) {
		$line = trim($line);
		$el = explode(" ", $line);
		$key = $el[0];
		array_shift($el);
		$iw_state[$key] = implode(" ", $el);
	}
	//print_r($el);
	return $iw_state;
}

function list_iw_networks($ifstate, $ifname) {
	if(!isset($ifstate[$ifname]))
		return false;
	// Don't scan on our AP interface ;)
	if($ifname == "wlan0")
		return true;
	// Show which network we are connected to
	// sudo iw wlan1 scan
	$cmd = "iw {$ifname} scan";
	exec($cmd, $out, $ret);
	if($ret > 0)
		echo "Failed to list wireless networks\n";
	
	$iw_networks = array();
	foreach($out as $line) {
		$line = trim($line);
		$el = explode(" ", $line);
		$key = $el[0];
		array_shift($el);
		$iw_networks[$key] = implode(" ", $el);

		
		
	}
	return $iw_networks;
}

// Show which clients are connected
// iw dev wlan0 station dump
// Fetch arp table arp -i wlan0 -n 
// Find IP address on wlan0 interface address by matching mac address
// perform DNS query against local resolver for hostname


function process_if_changes($ifstate, $iflist, $ifname) {
	if(!isset($ifstate[$ifname])) {
		// New interface!
		echo "Found interface {$ifname}, status '". if_state($iflist, $ifname) ."', addresses ". implode(',', if_prefix($iflist, $ifname)) ."\n";
		$iflist[$ifname]['wi'] = iw_info($iflist, $ifname);
	}
	if(isset($ifstate[$ifname]) && (!isset($iflist[$ifname]))) {
		// Interface went away!
		echo "Interface {$ifname}, went away! Used to have, addresses ". implode(',', if_prefix($ifstate, $ifname)) ."\n";
	}
	if(isset($ifstate[$ifname]) && isset($iflist[$ifname])) {
		// We already have this interface, check if it changed
		if((if_state($ifstate, $ifname) != if_state($iflist, $ifname))) {
			echo "{$ifname} moved from '". if_state($ifstate, $ifname) ."' to '". if_state($iflist, $ifname) ."'\n";
		}
		if((if_address($ifstate, $ifname) != if_address($iflist, $ifname))) {
			echo "Interface {$ifname} changed addresses from '". implode(',', if_address($ifstate, $ifname)) ."' to '". implode(',', if_address($iflist, $ifname)) ."'\n";
		}
		$iflist[$ifname]['wi'] = iw_info($iflist, $ifname);
	}
	
	// save current interface state to the state array. 
	return $iflist[$ifname];
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
	if(($gdns === true) && ($dns != "OK")){
		echo "Looks like we have a sane DNS for dns.google\n";
		return "OK";
	}
	if(($gdns === false) && ($dns != "NOK")) {
		echo "Looks like we can not resolve Public DNS yet, reload DNSmasq\n";
		service_restart("dnsmasq.conf");
		return "NOK";
	}
	return $dns;
}

function config_read_ovpn($state){
	$conf = "../conf/client.ovpn";
	$settings = array();

	if(is_readable($conf)) {
		$settings['conf'] = file_get_contents($conf);
	}
	// We can't actually read the login file, as it is moved into place and doesn't exists here anymore
	// $settings['login'] = config_read_ovpn_login($state);
	return $settings;
		
}

function config_read_ovpn_login($state){
	$conf = "../conf/client.ovpn.login";
	$settings = array();

	if(is_readable($conf)) {
		$settings['login'] = file_get_contents($conf);
	}
	return $settings;
}

function config_read_supplicant($state) {
	$conf = "../conf/wpa_supplicant.conf";
	$settings = array();
	if(is_readable($conf)) {
		$i = 0;
		foreach(file($conf) as $line) {
			$line = trim($line);
			$matches = array();
			preg_match_all("/([a-zA-Z_]+)=([{\" _a-z0-9-A-Z]+)/", $line, $matches);
			if(empty($matches[1]))
				continue;
			// echo "<pre>". print_r($matches, true);
			switch($matches[1][0]) {
				case "country":
					$settings[$matches[1][0]] = $matches[2][0];
					break;
				case "network":
					$i++;
					break;
				case "priority":
				case "key_mgmt":
					$settings['network'][$i][$matches[1][0]] = $matches[2][0];
					break;				
				case "ssid":
				case "psk":
					$settings['network'][$i][$matches[1][0]] = substr($matches[2][0], 1, -1);
					break;
			}
		}
	}
	//echo "yo. ". print_r($temp, true);

	//echo print_r($settings, true);
	return $settings;
}
function config_write_supplicant($settings) {
	$conf = "../conf/wpa_supplicant.conf";
	$conf_a = array();
	$conf_a[] = "ctrl_interface=DIR=/var/run/wpa_supplicant GROUP=netdev";
	$conf_a[] = "update_config=1";
	$conf_a[] = "";
	if(is_writeable($conf)) {
		$i = 0;
		foreach($settings as $varname => $setting) {
			switch($varname) {
				case "country":
					$conf_a[] = "{$varname}={$setting}";
					break;
				case "network":
					foreach($setting as $index => $values){
						$conf_a[] = "network={";
						// Skip empty entries
						if(($setting['ssid'] == "") && ($setting['psk'] == "") &&($setting['priority'] == "-1"))
							continue;
						foreach($values as $name => $value) {
							$var = "{$index}{$name}";
							switch($name) {
								case "ssid":
								case "psk":
									// Don't leave empty PSK fields, that is illegal config.
									if($settings['network'][$index][$name] != "")
										$conf_a[] = "    {$name}=\"{$settings['network'][$index][$name]}\"";
									break;
								case "priority":
								case "key_mgmt":
									$conf_a[] = "    {$name}={$settings['network'][$index][$name]}";
									break;
							}
									
						}
						$conf_a[] = "}";
						$conf_a[] = "";
						$i++;
					}
					$conf_a[] = "";
			}

		}
	} else {
		return false;
	}
	file_put_contents($conf, implode("\n", $conf_a));
	//echo "<pre>". print_r($conf_a, true);
	return true;
}

function config_read_hostapd($state) {
	$conf = "../conf/hostapd.conf";
	$settings = array();
	if(is_readable($conf)) {
		$i = 0;
		foreach(file($conf) as $line) {
			$line = trim($line);
			preg_match_all("/([a-zA-Z_]+)=([{\" _a-z0-9-A-Z]+)/", $line, $matches);
			//echo print_r($matches, true);
			$settings[$matches[1][0]] = $matches[2][0];
		}
		//preg_match_all("/(network)=.*?(ssid)=\"([a-zA-Z0-9-., ]+)\".*?(psk)=\"(.*?)\".*?(key_mgmt)=([A_Z]+)/si", file_get_contents($conf), $matches);
		//preg_match_all("/(network)=.*?(ssid)=\"([a-zA-Z0-9-., ]+)\".*?(psk)=\"(.*?)\".*?/si", file_get_contents($conf), $matches);
	}

	//echo print_r($settings, true);
	return $settings;
}

function config_read_dhcpcd($state) {
	$conf = "../conf/dhcpcd.conf";
	if(is_readable($conf)) {
		foreach($state['if'] as $ifname => $iface) {
			preg_match_all("/#{$ifname}start(.*?)#{$ifname}end/si", file_get_contents($conf), $matches);
			//print_r($matches);
			// Process sections
			if(empty($matches[1]))
				continue;
			$lines = explode("\n", $matches[1][0]);
			if(count($matches[1]) > 0)
				$settings[$ifname]['if'] = $ifname;
			foreach($lines as $line) {
				$line = trim($line);
				$el = explode(" ", $line);
					switch($el[0]) {
						case "denyinterface":
							$settings[$ifname]['deny'] = true;
							break;
						case "dhcp":
							$settings[$ifname]['mode'] = $el[0];
							break;
						case "nohook":
							$settings[$ifname]['nohook'] = $el[1];
							break;
						case "static":
							$settings[$ifname]['mode'] = $el[0];
							$a_str = explode("=", $el[1]);
							$add_a = explode("/", $a_str[1]);
							$settings[$ifname]['ip4'] = $add_a[0];
							$settings[$ifname]['prefix4'] = $add_a[1];
							break;
					}
			}
		
		}
		
	}
	return($settings);
}

function config_write_dhcpcd_interface($iflist, $ifname, $settings) {
	if(!isset($iflist[$ifname]))
		return false;
	
	$string[0] = "interface {$ifname}";
	foreach(settings as $key => $value) {
		switch($key) {
			case "dhcp":
				$string[0] .= "dhcp";
				break;
			case "static":
				$string[2] .= "static ip_address= {$settings['address']}/{$settings['prefixlen']}";
				break;
			case "lan":
				$string[3] .= "\tnohook wpa_supplicant"; 
				break;
		}
	}
	// Make sure that wlan0 is always the AP if for now.
	if($ifname == "wlan0")
		$string[3] .= "\tnohook wpa_supplicant"; 
			
	$conf = implode("\n", $string);
	return $conf;
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
			case "client.ovpn":
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
			case "client.ovpn.login":
				move_config($file);
				restart_service($file);
				break;
			case "sysctl-routed-ap.conf":
				copy_config($file);
				break;
			case "wpa_supplicant.conf":
			case "dnsmasq.conf":
			case "hostapd.conf":
			case "client.ovpn":
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
			case "client.ovpn":
			case "client.ovpn.login":
				$cmd = "sudo service openvpn reload";
				break;
			case "dnsmasq.conf":
				$cmd = "sudo service dnsmasq reload";
				break;
			case "hostapd.conf":
				$cmd = "sudo service hostapd reload";
				break;
			case "dhcpcd.conf":
				$cmd = "sudo service dhcpcd reload";
				break;
			case "wpa_supplicant.conf":
				$cmd = "sudo wpa_cli -i wlan1 reconfigure";
				break;
			default:
				echo "What is this mythical service file '{$file}' of which you speak?\n";
				return false;
				break;
	}
	if($cmd != ""){
		echo "Running command '{$cmd}'\n";
		exec($cmd, $out, $ret);
		if($ret > 0) {
			echo "Failed to restart service for {$file}\n";
			return false;
		}
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


function move_config($file) {
	global $cfgmap;
	global $cfgdir;

	echo "Move config file '{$file}' to '{$cfgmap[$file]}'\n";
	$cmd = "sudo mv -f {$cfgdir}/{$file} {$cfgmap[$file]}";
	exec($cmd, $out, $ret);
	if($ret > 0)
		echo "Failed to move config {$file} to {$cfgmap[$file]}\n";
}
