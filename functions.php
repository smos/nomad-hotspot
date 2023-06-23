<?php

// Let's store in tmpfs
$user = get_current_user();
$uid = trim(shell_exec("id -u {$user}"));
$tmpfsurl = "/run/user/{$uid}/state.serialize";

// Shared memory for exchanging between proc and webserver
// $shm_size = 128 * 1024;
// $shm_id = create_shm($shm_size);


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
			"iptables.v4" => "/etc/iptables/iptables.v4",
			"iptables.v6" => "/etc/iptables/iptables.v6",
			"config.json" => "config.json",
			"README.md" => "README.md",
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
	create_certificate();
	create_stunnel4_config($address, $port);
	start_stunnel4();
	


	// Start in a detached screen session
	msglog("agent.php", "Starting webserver on adress {$address} and port {$port} in dir {$dir}");
	$cmd = "screen -d -m -S nomad-webserver php -S $address:$port -t $dir";
	if(exec_log($cmd) === false)
		msglog("agent.php", "Failed to start webserver process in screen");
		

	// Start in a detached screen session
	msglog("redirect.php", "Starting redirect webserver on adress {$address} and port 80");
	$cmd = "screen -d -m -S nomad-redirect sudo php -S $address:80 $dir/redirect.php";
	if(exec_log($cmd) === false)
		msglog("agent.php", "Failed to start webserver process in screen");

}

function start_stunnel4() {
	if(!is_executable("/usr/bin/stunnel4")) {
		$cmd = "sudo apt -y install stunnel4";
		if(exec_log($cmd) === false)
			msglog("agent.php", "Failed to install stunnel4");
	}
		
	// Start in a detached screen session
	msglog("stunnel4", "Starting redirect https webserver on port 443");
	$cmd = "screen -d -m -S nomad-stunnel sudo stunnel4 ssl/stunnel4.conf";
	if(exec_log($cmd) === false)
		msglog("agent.php", "Failed to start webserver process in screen");

}

function create_stunnel4_config($address, $port){

$cfg = "

[webserver]
accept = {$address}:443
connect = {$address}:{$port}

cert = ssl/nomad-hotspot.crt
key = ssl/nomad-hotspot.key

";

file_put_contents("ssl/stunnel4.conf", $cfg);

}

function create_certificate() {
	if(!file_exists("ssl/nomad-hotspot.crt")) {

		$cmd = "openssl req -x509 -nodes -days 365 -newkey rsa:4096 -keyout ssl/nomad-hotspot.key -out ssl/nomad-hotspot.crt -batch";

		if(exec_log($cmd) === false)
			msglog("agent.php", "failed to generate new certificate");
	}

}

function msglog($process = "", $msg = "") {
	// Just take global state to store logs in array
	global $state;
	// Setup logging instead of just plain echo
	if(!isset($state['log'][$process]))
		$state['log'][$process] = array();
	
	$last = end($state['log'][$process]);
	// msg differs from previous
	if($msg != $last) {
		$time = date("Y-m-d H:m:s");
		$state['log'][$process][$time] = $msg;
		// Also log to syslog
		syslog(LOG_NOTICE, "{$process} {$msg}");	
		echo "{$time}: {$msg}\n";

		// Prune array
		//while(count($state['log'][$process]) > 20) {
		//	array_shift($state['log'][$process]);			
		//}
		//write_shm($shm_id, $state);
			
	}
		

}

function save_config ($cfgfile, $config){
	global $state;
	if(empty($cfgfile))
		return false;

	//echo "<pre>". print_r($state['config'], true) . "</pre>";
	file_put_contents($cfgfile, json_encode($config, JSON_PRETTY_PRINT));
	msglog("agent.php", "saving config to json");

	$state['config'] = $config;
	//echo "<pre>". print_r($state['config'], true) . "</pre>";

	return true;
}

function read_config ($cfgfile){
	if(empty($cfgfile))
		return false;


	// echo "<pre>". print_r($cfgfile, true) . "</pre>";

	$config = json_decode(file_get_contents($cfgfile), true);
	// msglog("agent.php", "reading config.json to state");

	// print_r($config);
	if(empty($config))
		$config['port'] = 8000;

	// print_r($config);

	return($config);
}

function find_wan_interface($state) {
	//which has the default route?
	$defgw = fetch_default_route_gw();
	$iface = $defgw[4][0]['dev'];
	if($iface == "")
		$iface = "wlan1";

	return($iface);
}

function iw_info($ifstate, $ifname) {
	if(!isset($ifstate[$ifname]))
		return false;
	// Don't scan on our eth0 interface ;)
	if(($ifname == "eth0") || ($ifname == "tun0"))
		return null;

	// List wireless interface statistics
	// iw wlan0 info
	$cmd = "iwconfig {$ifname}";
	exec($cmd, $out, $ret);
	if($ret > 0)
		msglog("agent.php", "Failed to fetch wireless info {$cmd}");

	$iw_state = array();
	$i = 0;
	foreach($out as $line) {
		$line = trim($line);
		$els = preg_split("/[ ]+/", $line);

		if($i == 0) {
		 	preg_match("/ESSID\:\"(.*?)\"/", $line, $essidmatch);
		 	preg_match("/Mode\:([a-zA-Z0-9]+) /", $line, $modematch);
			if(!empty($essidmatch)) {
				$iw_state['essid'] = "{$essidmatch[1]}";
			}
			if(!empty($modematch)) {
				$iw_state['mode'] = "{$modematch[1]}";
			}
			$iw_state['phy'] = "{$els[1]}{$els[2]}";
			$i++;
			continue;
		}
		foreach($els as $num => $val) {
			$elc = preg_split("/\:/", $val, 2);
			$ele = preg_split("/\=/", $val, 2);
			switch($elc[0]) {
				case "Point":
					$key = "bssid";
					$value = strtolower($els[$num+1]);
					break;
				case "Frequency":
				case "Rate":
				case "Mode":
					$key = strtolower($elc[0]);
					$value = $elc[1];
					break;
			
			}

			if(!isset($iw_state['level'])) {
				switch($ele[0]) {
					case "level":
					case "Quality":
						$ell = preg_split("/\//", $ele[1], 2);
						$key = strtolower($ele[0]);
						$value = $ell[0];
						break;
			
				}

			}

			if((isset($key)) && (isset($value)))				$iw_state[$key] = $value;

		}
		$i++;


	}
	//print_r($el);
	return $iw_state;
}

function list_iw_networks($state, $ifname) {
	if(!isset($state['if'][$ifname]))
		return false;

	// Don't scan on our AP interface ;)
	if($ifname == "wlan0")
		return true;
	// Show which network we are connected to
	// sudo iw wlan1 scan
	$cmd = "sudo iwlist {$ifname} scan";
	exec($cmd, $out, $ret);
	if($ret > 0)
		msglog("agent.php", "Failed to list wireless networks");


	$iw_networks = array();
	$i = 0;
	foreach($out as $line) {
		preg_replace("/^[ ]+/i", "", $line);
		$line = trim($line);
		$el = preg_split("/:/", $line, 2);
		if(strstr($line, "Cell")) {
			$el[0] = "Address";
			$el[1] = trim(strtolower("{$el[1]}"));
		}
		if(strstr($line, "Quality")) {
			if($el[1] == "") {
				$el[1] = trim($el[0]);
				$el[0] = "Quality";
			}
		}
		switch($el[0]) {
			case "ESSID":
			case "Address":
			case "Frequency":
			case "Quality":
			case "Encryption key":
				$iw_networks[$i][$el[0]] = $el[1];
				break;
		}

		if(strstr($line, "Quality")) {
			// Do not list self
			if("{$iw_networks[$i]['Address']}" == $state['if']['wlan0']['address'])
				continue;
			$i++;
		}
	}
	
	return $iw_networks;
}

function clean_wi_list($iw_networks) {
	// normalise array by ssid
	
	
	//echo "<br/>";
	$iw_list = array();
	foreach($iw_networks as $num => $net) {
		
		//echo "<pre>". print_r($net, true) ."</pre>";
		//echo "'{$net['ESSID']}' <br/>";
		
		if(!isset($iw_list[$net['ESSID']])) {
			$iw_list[$net['ESSID']] = array();
		}
		$iw_list[$net['ESSID']]['encryption'] = $net['Encryption key'];
		$iw_list[$net['ESSID']]['bssid'][] = $net['Address'];
		
		// Parse quality number
		preg_match("/Quality\=([0-9]+)\/100/i", $net['Quality'], $matches);
		
		$snr = $matches[1];
		
		// Only save highest quality
		if(!isset($iw_list[$net['ESSID']]['snr'])) {
			$iw_list[$net['ESSID']]['snr'] = $snr;
		} else {
			if(floatval($snr) > floatval($iw_list[$net['ESSID']]['snr']))
				$iw_list[$net['ESSID']]['snr'] = $snr;
		}
		
	}

	//echo "<pre> test". print_r($iw_list, true) ."</pre>";
	return $iw_list;
}


function process_if_changes($ifstate, $iflist, $ifname) {
	global $state;
	if(!isset($ifstate[$ifname])) {
		// New interface!
		msglog("agent.php", "Found interface {$ifname}, status '". if_state($iflist, $ifname) ."', addresses ". implode(',', if_prefix($iflist, $ifname)) ."");
		$iflist[$ifname]['wi'] = iw_info($iflist, $ifname);
		restart_service("iptables.v4");
		restart_service("iptables.v6");


		// This interface resets counters when going up/down
		if(strstr($ifname, "tun"))
			$ifstate[$ifname]['stats64start'] = $iflist[$ifname]['stats64'];

		if(if_state($iflist, $ifname) == "UP") {
			$state['leases'][$ifname] = dump_dhcp_lease($iflist, $ifname);
			//print_r($lease);
			if(isset($state['leases'][$ifname]['domain_name_servers'])) {
				foreach(explode(" ", $state['leases'][$ifname]['domain_name_servers']) as $dns_server) {
					msglog("agent.php", "Adding route to DNS Server {$dns_server} via default GW of {$ifname}");
					route_add($dns_server, "");
				}
			}
		}
		
		// init Counters
		$state['traffic'][$ifname]['toprx'] = 0;
		$state['traffic'][$ifname]['toptx'] = 0;
		$state['traffic'][$ifname]['hist']['rx'] = array();
		$state['traffic'][$ifname]['hist']['tx'] = array();
	}
	if(isset($ifstate[$ifname]) && (!isset($iflist[$ifname]))) {
		// Interface went away!
		msglog("agent.php", "Interface {$ifname}, went away! Used to have, addresses ". implode(',', if_prefix($ifstate, $ifname)) ."");
		unset($state['if'][$ifname]);
	}
	if(isset($ifstate[$ifname]) && isset($iflist[$ifname])) {
		// We already have this interface, check if it changed
		if((if_state($ifstate, $ifname) != if_state($iflist, $ifname))) {
			msglog("agent.php", "{$ifname} moved from '". if_state($ifstate, $ifname) ."' to '". if_state($iflist, $ifname) ."'");
		}
		if((if_address($ifstate, $ifname) != if_address($iflist, $ifname))) {
			msglog("agent.php", "Interface {$ifname} changed addresses from '". implode(',', if_address($ifstate, $ifname)) ."' to '". implode(',', if_address($iflist, $ifname)) ."'");
		}
		$iflist[$ifname]['wi'] = iw_info($iflist, $ifname);
	}

	if(!isset($ifstate[$ifname]['stats64start'])) {
		$ifstate[$ifname]['stats64start'] = $iflist[$ifname]['stats64'];
	}
	$iflist[$ifname]['stats64start'] = $ifstate[$ifname]['stats64start'];

	$iflist[$ifname]['time'] = time();
	// If we are here, we can collect some statistics.



	// print_r($ifstate[$ifname]['traffic']);
	if(isset($ifstate[$ifname])) {
		$iflist[$ifname]['traffic'] = calculate_traffic($ifstate[$ifname], $iflist[$ifname], $ifname);
		$state['traffic'][$ifname]['hist'] = process_traffic_hist($state['traffic'][$ifname]['hist'], $iflist[$ifname]['traffic']);
	}
	// save current interface state to the state array. 
	if(isset($iflist[$ifname]))
		return $iflist[$ifname];
	else
		return false;
}

function calculate_traffic($ifstate, $iflist, $ifname) {
	global $state;
	if(!isset($ifstate['time']))
		return false;
	if(!isset($iflist['time']))
		return false;
	$timediff = $iflist['time'] - $ifstate['time'];
	// echo "newtime = {$iflist['time']} - {$ifstate['time']}\n";
	if($timediff < 0)
		return false;

	if(!isset($iflist['stats64']))
		return false;
	if(!isset($ifstate['stats64']))
		return false;
	if(!isset($iflist['stats64start']))
		return false;
	if(!isset($ifstate['stats64start']))
		return false;

	$rx = floatval($iflist['stats64']['rx']['bytes']) - floatval($ifstate['stats64']['rx']['bytes']);
	$tx = floatval($iflist['stats64']['tx']['bytes']) - floatval($ifstate['stats64']['tx']['bytes']);


	$traffic['totalrx'] = floatval(floatval($iflist['stats64']['rx']['bytes']) - floatval($ifstate['stats64start']['rx']['bytes']));
	$traffic['totaltx'] = floatval(floatval($iflist['stats64']['tx']['bytes']) - floatval($ifstate['stats64start']['tx']['bytes']));

	// echo "rx {$rx}, tx {$tx}, timediff {$timediff}\n";
	// Bytes per second
	$traffic['rx'] = round($rx/$timediff);
	$traffic['tx'] = round($tx/$timediff);


	if($traffic['rx'] > $state['traffic'][$ifname]['toprx']) {
		$state['traffic'][$ifname]['toprx'] = $traffic['rx'];
	}
	if($traffic['tx'] > $state['traffic'][$ifname]['toptx']) {
		$state['traffic'][$ifname]['toptx'] = $traffic['tx'];
	}	
	return $traffic;
}

function process_traffic_hist($old, $stats) {
	// limit to 200;
	$count = 200;
	$hist = array();
	
	if(isset($stats['rx']))
		$hist['rx'][] = $stats['rx'];
	if(isset($stats['tx']))
		$hist['tx'][] = $stats['tx'];
	$i = 0;
	while($count > $i) {
		if(isset($old['rx'][$i]))
			$hist['rx'][] = $old['rx'][$i];
		else
			$hist['rx'][] = 0;
		
		if(isset($old['tx'][$i]))
			$hist['tx'][] = $old['tx'][$i];
		else
			$hist['tx'][] = 0;
		
		$i++;		
	}
	
	return $hist;
}

// Create 32kb shared memory block with system id of 0xff3
function create_shm($shm_size) {
	$shm_id = shmop_open(0xff3, "c", 0644, $shm_size);
	if (!$shm_id) {
		msglog("agent.php", "Couldn't create shared memory segment");
	}
	return $shm_id;
}

// Lets write a test string into shared memory
function write_shm($shm_id, $state) {
	$shm_bytes_written = shmop_write($shm_id, serialize($state), 0);
	if ($shm_bytes_written != strlen(serialize($state))) {
		msglog("agent.php", "Couldn't write the entire length of data to shm");
		return false;
	}
	return true;
}

function read_shm($shm_id, $shm_size) {
	// Now lets read the string back
	$state = unserialize(shmop_read($shm_id, 0, $shm_size));
	if (!is_array($state)) {
		msglog("agent.php", "Couldn't read serialized array from shared memory block");
		return false;
	}
	return $state;
}

// Lets write a test string into shared memory
function write_tmpfs($tmpfsurl, $state) {
	$tmpfsurl_written = file_put_contents($tmpfsurl, serialize($state), 0);
	if ($tmpfsurl_written != strlen(serialize($state))) {
		msglog("agent.php", "Couldn't write the entire length of data to tmpfs");
		return false;
	}
	return true;
}

function read_tmpfs($tmpfsurl) {
	// Now lets read the string back
	$state = unserialize(file_get_contents($tmpfsurl));
	if (!is_array($state)) {
		msglog("agent.php", "Couldn't read serialized array from tmpfs");
		return false;
	}
	return $state;
}

// Working DNS check
function working_dns($dns) {
	global $state;
	$gdns = check_gdns_rec();
	if(($gdns === true) && ($dns != "OK")){
		msglog("agent.php", "Looks like we have a sane DNS for dns.google");
		//if($state['config']['openvpn'] == true)
		//	start_service("client.ovpn");
		return "OK";
	}
	if(($gdns === false) && ($dns != "NOK")) {
		msglog("agent.php", "Looks like we can not resolve Public DNS, stop OpenVPN, reload DNSmasq");
		$config = read_config($state['cfgfile']);
		if($config['openvpn'] === true)
			stop_service("client.ovpn");
		// restart_service("dnsmasq.conf");
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
			preg_match_all("/([a-zA-Z_]+)=([{\" _a-z0-9-A-Z:]+)/", $line, $matches);
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
				case "bssid":
				case "key_mgmt":
					$settings['network'][$i][$matches[1][0]] = $matches[2][0];
					break;
				case "ssid":
					$settings['network'][$i][$matches[1][0]] = substr($matches[2][0], 1, -1);
					if($settings['network'][$i][$matches[1][0]] == "")
						$settings['network'][$i][$matches[1][0]] = "any";
					break;
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

	$freq = fetch_freq_list("wlan1", 5);
	// print_r($freq);
	$conf = "../conf/wpa_supplicant.conf";
	$conf_a = array();
	$conf_a[] = "ctrl_interface=DIR=/var/run/wpa_supplicant GROUP=netdev";
	$conf_a[] = "update_config=1";
	$conf_a[] = "bgscan=\"learn:30:-70:3600\"";
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
						if(($values['ssid'] == "") && ($values['psk'] == ""))
							continue;

						if($values['ssid'] == "any") {
							$values['priority'] = "-9";
							$values['ssid'] = "";
							//$values['freq_list'] = implode(" ", $freq);
							//echo print_r($settings['network'][$index], true);
						}

						foreach($values as $name => $value) {
							$var = "{$index}{$name}";
							switch($name) {
								case "bssid":
									if($values[$name] != "") {
										$conf_a[] = "    {$name}={$values[$name]}";
										$conf_a[] = "    freq_list=". implode(" ", $freq);
										$conf_a[] = "    scan_freq=". implode(" ", $freq);
									}
									break;
								case "freq_list":
									if($values[$name] != "")
										$conf_a[] = "    {$name}=". implode(" ", $freq);
									break;
								case "ssid":
									// Override the any setting to ""
									$conf_a[] = "    {$name}=\"{$values[$name]}\"";
									break;
								case "psk":
									// Don't leave empty PSK fields, that is illegal config.
									if($values[$name] != "")
										$conf_a[] = "    {$name}=\"{$values[$name]}\"";
									break;
								case "priority":
								case "key_mgmt":
									$conf_a[] = "    {$name}={$values[$name]}";
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
function config_write_ovpn($settings) {
	$conf = "../conf/client.ovpn";
	if(is_writeable($conf)) {
		//echo "<pre>". print_r($settings['conf'], true) ."</pre>";
		// Replace "auth-user-pass" with "auth-user-pass client.ovpn.login"
		if((stristr($settings['conf'], "auth-user-pass")) && (!stristr($settings['conf'], "client.ovpn.login"))) {
			$settings['conf'] = str_replace("auth-user-pass", "auth-user-pass client.ovpn.login", $settings['conf']);
			msglog("agent.php", "Adding openvpn client login data parameter.");
		}
		file_put_contents($conf, $settings['conf']);
		return true;
	}
}
function config_write_ovpn_login($settings) {
	$conf = "../conf/client.ovpn.login";

//	if(is_writeable($conf)) {
		//echo "<pre>". print_r($settings['login'], true) ."</pre>";
		file_put_contents($conf, $settings['login']);
		return true;
//	}
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
				$string[1] .= "no ipv4ll";
				break;
			case "static":
				$string[2] .= "static ip_address= {$settings['address']}/{$settings['prefixlen']}";
				break;
			case "lan":
				$string[3] .= "\tnohook wpa_supplicant";
				$string[4] .= "\tnoipv4ll";
				$string[5] .= "\tnoipv6rs";
				break;
		}
	}
	// Make sure that wlan0 is always the AP if for now.
	if($ifname == "wlan0") {
		$string[3] .= "\tnohook wpa_supplicant";
		$string[4] .= "\tnoipv4ll";
		$string[5] .= "\tnoipv6rs";
	}

	$conf = implode("\n", $string);
	return $conf;
}

// Working internet check
function working_msftconnect($captive) {
	global $state;
	$i = 0;
	while($i < 3) {
		$msftconnect = check_msft_connect();
		if($msftconnect !== false)
			break;
		$i++;
	}
	$config = read_config($state['cfgfile']);
	//print_r($msftconnect);
	if(($msftconnect == "OK") && ($captive != "OK")){
		msglog("agent.php", "Captive Portal check succeeded, looks like we have working Internet");
		// Hook in OpenVPN start here
		if($config['openvpn'] === true)
			start_service("client.ovpn");

		$wanip = file_get_contents("http://ipecho.net/plain");
		$state['internet']['wanip'] = $wanip;
		$state['internet']['isp'] = fetch_as_info($state, $wanip);
	}
	if(($msftconnect == "DNSERR") && ($captive != "DNSERR")) {
		msglog("agent.php", "Looks like DNS doesn't work properly yet");
		// Hook in OpenVPN start here
		if($config['openvpn'] === true)
			stop_service("client.ovpn");
	}
	if(($msftconnect == "PORTAL") && ($captive != "PORTAL")) {
		msglog("agent.php", "Looks like we we are stuck behind a portal, someone needs to log in");

		msglog("agent.php", "Attempting to parse the portal page");
		// Attempt a Portal authentication, bit basic, but anyhow.
		$result = parse_portal_page(); // Default url is msft connect
		if($result === false)
			msglog("agent.php", "It tried, to bad, to sad, nevermind.");
		else
			msglog("agent.php", "It actually worked?!");
	}
	return $msftconnect;
}

function check_msft_connect($url = "") {
	if($url == "")
		$url = "http://www.msftconnecttest.com/connecttest.txt";

	// Inject route to MSFT check directly
	// echo "Adding route to MSFT NCSI server";
	route_add(url_to_ip($url), "");

	$string = "Microsoft Connect Test";
	// check DNS
	$cmd = "host -W 1 www.msftconnecttest.com";
	$i = 0;
	while ($i < 5) {
		exec($cmd, $out, $ret);
		if($ret == 0)
			break;
		sleep (1);
		$i++;
	}
	if($ret > 0) {
		msglog("agent.php", "msft connect failed to resolve in $i attempts");
		return "DNSERR";
	}

	$j = 0;
	while($j < 5) {
		$test = simple_web_request($url);

		// catch curl error, skip  timeout
		if(stristr($test, "operation timed out after 15")) {
					msglog("agent.php", "msft connect test returned code '$test' attempt $j");
					sleep(3);
					$j++;
					continue;
		}
		
		if(is_string($test) && (strlen($test) == strlen($string))) {
			if($test == trim($string)) {
					// msglog("agent.php", "msft connect test suceeded code '$test' attempt $j");
					return "OK";
			}


			if($test != trim($string))
				return "PORTAL";
		}
		$j++;
	}

	if($j == 5){
			msglog("agent.php", "msft connect test failed to fetch in $j attempts");
			return "TIMEOUT";
	}

}

// return request array based on parsing of portal page.
function parse_portal_page($url = ""){
	global $state;
	$config = read_config($state['cfgfile']);
	if($url == "")
		$url = "http://www.msftconnecttest.com/connecttest.txt";


	$state['internet']['url'] = $url;
	// attempt this 3 times
	$t = 3;
	while($t > 0) {
		// We might want to add the captive portal IP address to the routing table, we should be able to reach it regardless of Openvpn
		route_add(url_to_ip($url), "");

		$test = simple_web_request($url);
		// Skip false positives under load, make sure we don't enter parsing stage.
		if($test == "Microsoft Connect Test")
			return true;
		if($test == "")
			return true;

		// Hook in OpenVPN stop here
		if($config['openvpn'] === true)
			stop_service("client.ovpn");

		// Save the portal page in /tmp for later diagnosis or testing
		$datestr = date("Ymd-His");
		file_put_contents("/tmp/portal_page_{$datestr}.html", "{$url}\n{$test}");

		echo "String: {$test}\n";
		// Test for javascript redirect
		preg_match("/window.location=[\'\"](.*?)[\'\"]/i", $test, $jsmatches);

		// test for meta refresh
		// <meta http-equiv="refresh" content="0; url=https://login.wifi.site.com" />
		preg_match("/meta http-equiv=[\'\"]refresh[\'\"].*?url=(.*?)[\'\"]/i", $test, $metamatches);

		if(isset($jsmatches[1])) {
			if(strstr($jsmatches[1], "http")) {
				$url = $jsmatches[1];
				route_add(url_to_ip($url), "");
				echo "Oh Look, a javascript redirect, looks like we have a followup url '{$url}', following, remember this\n";
				$test = simple_web_request($url);
				$state['internet']['url'] = $url;
			}
		}
		//print_r($metamatches);
		if(isset($metamatches[1])) {
			if(strstr($metamatches[1], "http")) {
				$url = $metamatches[1];
				route_add(url_to_ip($url), "");
				echo "Oh Look, a meta refresh redirect, looks like we have a followup url '{$url}', following, remember this\n";
				$test = simple_web_request($url);
				$state['internet']['url'] = $url;
			}
		}
		// Does this result have a form we can use?
		// Collect forms and inputs from page
		preg_match_all("/<form.*?>/", $test, $forms_a);
		preg_match_all("/<input.*?>/", $test, $inputs_a);
		preg_match_all("/onclick=[\'\"](.*?)[\'\"] /i", $test, $onclicks_a);

		if(!empty($forms_a[0])) {
			echo "Hey look, this one has forms!\n";
			// print_r($forms_a);
			// print_r($inputs_a);
			// print_r($onclicks_a);

			$request = build_form_request($forms_a, $inputs_a, $onclicks_a);
			// print_r($request);

			// Remember that url we found?, yeah, we need that here, we should strip to base and include form here. Then again, the post can be absolute. meh.
			//$result = simple_web_request($url, $request['form']['method'], $request['vars']);
			//print_r($result);
			echo "Did it work?\n";
			return $result;
		}
		$t--;
	}
}

function simple_web_request($url, $method = "get", $vars = array(), $credentials = array()) {
	if(!strstr($url, "msftconnecttest"))
		msglog("agent.php", "Request url '{$url}', method {$method}, vars". json_encode($vars) ."");
	if($url == "")
		return false;

	if(!empty($credentials)) {
		$username = $credentials[0];
		$password = $credentials[1];
	}
	$method = strtolower($method);
	switch($method) {
		case "get":
			$post = false;
			break;
		case "post":
			$post = true;
			break;
		default:
			return false;
	}

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, $post);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 15);
	curl_setopt($ch, CURLOPT_COOKIEFILE, "/tmp/nomad-hotspot.jar");
	curl_setopt($ch, CURLOPT_COOKIEJAR, "/tmp/nomad-hotspot.jar");
	curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0");

	if((!empty($vars)) && ($post === true)) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($vars));
	}
 	$result = curl_exec($ch);
	if(curl_error($ch)) {
    		return curl_error($ch);
	}
	curl_close($ch);
	return $result;
}

function build_form_request($forms_a, $inputs_a, $onclicks_a) {
	// Spaghetti code alert
	// No forms, no go.
	if(empty($forms_a))
		return false;
	$request = array();
	// Transform form into associative array, 1st item only
	//print_r($forms_a);
	$form_a = transform_form_to_array($forms_a[0][0]);
	//print_r($form_a);
	foreach($form_a[1] as $key => $value) {
		//print_r($value);
		if($value == "")
			continue;
		switch($value) {
			case "method":
			case "action":
			case "name":
				$request['form'][$value] = $form_a[2][$key];
				break;
		}
	}
	//print_r($request);
	// No form? No go.
	if(!isset($request['form']))
		return false;
	// We need atleast 3 elements for a succesful form submission.
	if(count($request['form']) < 3)
		return false;

	// Transform inputs into associative arrays
	$i = 0;
	foreach($inputs_a[0] as $input) {
		$input_a[$i] = transform_form_to_array($input);
		//print_r($input_a[$i]);

		$proc = false;
		$req = array();
		foreach($input_a[$i][1] as $key => $fname) {
			$fname = strtolower($fname);
			switch($fname) {
				case "type":
					// Might need to flip a variable
					if(stristr($input_a[$i][2][$key], "checkbox")) {
						// echo "It has a checkbox \n";
						$proc = true;
					}
					if(stristr($input_a[$i][2][$key], "hidden")) {
						// echo "It has a hidden variable \n";
						$proc = true;
					}
					if(stristr($input_a[$i][2][$key], "submit")) {
						// echo "It has a submit button \n";
						$proc = true;
					}
					break;
				case "name":
					$req['name'] = $input_a[$i][2][$key];
					break;
				case "value":
					$req['value'] = $input_a[$i][2][$key];
					break;
				case "onclick":
					echo "It has a OnClick event\n";
					// validateTerms(&quot;return f600122sub(&#39;continue&#39;);&quot;)
					$request['onclick'] = $input_a[$i][2][$key];
					$request['onclick'] = parse_js_function($request['onclick']);
					break;
			}
		}
		// If we can find a onclick function, try that too.
		if(!empty($onclicks_a[1])) {
			$request['onclick'] = parse_js_function($onclicks_a[1][0]);
		}
		if($proc == true) {
			if(!isset($req['value'])) {
				$req['value'] = "";
				if(isset($request['onclick'])) {
				echo "Well, the variable '{$req['name']}' has no value, and we do have a onclick value '{$request['onclick']}', try that\n";
					$req['value'] = "{$request['onclick']}";
				}
			}
			$request['vars'][$req['name']] = $req['value'];
		}
		$i++;
	}
	return $request;
}

function parse_js_function($string) {

	// print_r($string);
	// Let's see how far we can narrow this down
	$i = 1;
	while (strstr($string, "(",)) {
		$start = strpos($string, "(") + 1;
		$stop = strrpos($string, ")") - 1;
		$length = $stop - strlen($string) +1;
		$string = str_replace("'", "", substr($string, $start, $length));
		//echo " substr start at {$start}, stop at char {$stop}, length {$length}, {$string} \n"; 
		$i++;
	}
	$string = preg_replace("/(\&#[0-9][0-9];)/", "", $string);
	//echo "Found {$i} layers, result string is {$string}\n";
	return $string;
}

function ping($address = ""){
	if($address == "") {
		$defgw = fetch_default_route_gw();
		if($defgw === false)
			return false;
		$address = $defgw[4][0]['gateway'];
	}
	$latency = 0;

	// basic IP sanity check on address
	preg_match("/([0-9:\.a-f]+)/i", $address, $ipmatch);

	if(!isset($ipmatch[1]))
		return false;

	if($ipmatch[1] == "")
		return false;

	$cmd = "ping -U -W1 -c1 {$ipmatch[1]}";
	exec($cmd, $out, $ret);
	if ($ret > 0) {
		// Timeout
		$latency = 999;
		return $latency;
	}
	$num=count($out);
	$line = $out[$num-1];
	preg_match("/([0-9\.]+)\/([0-9\.]+)\/([0-9\.]+)\//i", $line, $matches);


	return round($matches[2]);
}

function url_to_ip($url){
	$host = parse_url($url, PHP_URL_HOST);
	$ip = gethostbyname($host);
	return $ip;
}

function fetch_freq_list($iface, $band = "25") {
	if($iface == "")
		return false;
	
	$band = strval($band);
	$freq = array();
	$cmd = "iwlist {$iface} freq";
	exec($cmd, $out, $ret);
	if ($ret > 0)
		return false;

	if(empty($out))
		return false;
		
	foreach($out as $line) {
		// print_r($line);
		preg_match("/\: ([{$band}]\.[0-9]+)/i", $line, $freqmatch);
		// print_r($freqmatch);
		if($freqmatch[1] > 0)
			$freq[] = floatval($freqmatch[1]) * 1000;
	
	}
	return $freq;
}

function fetch_default_route_gw() {
	// Fetch the default gateway 4
	$defgw = array();
	$cmd = "ip -j route show default";
	exec($cmd, $out4, $ret4);
	if ($ret4 > 0)
		return false;

	if(empty($out4))
		return false;

	// Fetch the default gateway 4
	$cmd = "ip -j -6 route show default";
	exec($cmd, $out6, $ret6);
	if ($ret6 > 0)
		return false;

	if(empty($out6))
		return false;

	$defgw[4] = json_decode($out4[0], true);
	$defgw[6] = json_decode($out6[0], true);

	if(empty($defgw))
		return false;

	return ($defgw);
}


// We only care for adding routes for now
function route_add($ip, $gwip = ""){
	// basic IP sanity check on address
	preg_match("/([0-9:\.a-f]+)/", $ip, $ipmatch);

	// basic IP sanity check on address
	preg_match("/([0-9:\.a-f]+)/", $gwip, $gwipmatch);

	if(strstr("$ip", ":"))
		$ipv6 = true;
	else
		$ipv6 = false;
	
	// print_r($gwipmatch);
	// Needs actual ip address check

	if($ipmatch[1] == "")
		return false;
	if($ipmatch[1] == ".")
		return false;

	if($gwip == "") {
		$defgw = fetch_default_route_gw();
		if($defgw === false) {
			msglog("agent.php", "We don't have a default route! Do we even have a wifi connection?");
			return false;
		}
	} else {
		$defgw['gateway'] = $gwipmatch[1];
	}
	
	if(!isset($defgw[4][0]['gateway']))
		return false;
	
	if($ipv6 === true) {
		if($defgw[6][0]['gateway'] == "")
			return false;	
	} else {
		if($defgw[4][0]['gateway'] == "")
			return false;	
		
	}
	// print_r($ipmatch);
	if($ipv6 === true)
		$cmd = "sudo ip -6 route replace {$ipmatch[1]} via {$defgw[6][0]['gateway']}";
	else
		$cmd = "sudo ip route replace {$ipmatch[1]} via {$defgw[4][0]['gateway']}";

	//print_r($cmd);
	if(exec_log($cmd) === false)
		if($ipv6 === true)
			msglog("agent.php", "Failed to change route for '{$ipmatch[1]}' through '{$defgw[6]['gateway']}'");
		else
			msglog("agent.php", "Failed to change route for '{$ipmatch[1]}' through '{$defgw[4]['gateway']}'");
}

function exec_log($cmd) {
	exec($cmd, $out, $ret);
	if($ret > 0) {
		msglog("agent.php", "Failed to exec '{$cmd}'");
		return false;
	}
	return $ret;
}

function dump_dhcp_lease($iflist, $ifname){
	if(!isset($iflist[$ifname]))
		return false;

	$cmd = "sudo dhcpcd --dumplease {$ifname} 2>/dev/null";
	exec($cmd, $out, $ret);
	// Don't check return value

	$lease = array();
	foreach($out as $line){
		preg_match("/([a-z0-9-_]+)=[\'\"](.*?)[\'\"]/i", $line, $matches);
		$lease[$matches[1]] = $matches[2];
	}
	return($lease);
}

function transform_form_to_array($string) {
	preg_match_all("/([a-z0-9-_]+)=[\'\"](.*?)[\'\"]/i", $string, $matches);
	return $matches;
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
					msglog("agent.php", "We are missing a process called {$procname}");
				break;
			default:
				msglog("agent.php", "What is this mythical process for file '{$file}' of which you speak?");
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
		msglog("agent.php", "Failed to check process for {$file} to {$procmap[$file]}");

	return count($out);

}

// Get all our interface information, index by ifname
function interface_status() {
	$iflist = array();

	$cmd = "ip -j address show ";
	exec($cmd, $out, $ret);
	if($ret > 0)
		return false;
	$ifjson = json_decode($out[0], true);

	$cmdlink = "ip -j -s link ";
	exec($cmdlink, $outlink, $retlink);
	if($retlink > 0)
		return false;
	$iflinkjson = json_decode($outlink[0], true);

	foreach($ifjson as $key => $if){
		$iflist[$if['ifname']] = $if;
		$iflist[$if['ifname']]['stats64'] = $iflinkjson[$key]['stats64'];
	}

	return $iflist;
}

// Return interface status
function if_state($iflist, $name){
	// Does this interface even exist?
	if(!isset($iflist[$name]))
		return false;

	// Help out the OpenVPN status
	if($name == "tun0")
		$iflist[$name]['operstate'] = "UP";

	return $iflist[$name]['operstate'];
}

// Return interface addresses
function if_address($iflist, $ifname) {
	// Does this interface even exist?
	if(!isset($iflist[$ifname]))
		return false;

	$add = array();

	//print_r($iflist[$ifname]);
	if(isset($iflist[$ifname]['addr_info'])) {
		foreach($iflist[$ifname]['addr_info'] as $index => $address) {
			if($address['scope'] != "global")
				continue;

			$add[] = $address['local'];
		}
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

	$cmd = "host -W 2 dns.google";
	$i = 0;
	while ($i < 3) {
		exec($cmd, $out, $ret);
		if($ret == 0)
			break;
		$i++;
	}

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
		// Skip nano swap files
		if(stristr(".swp", $file))
			continue;
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
	global $state;
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
			case "dhcpcd.conf":
				copy_config($file);
				restart_service($file);
				break;
			case "client.ovpn":
				copy_config($file);
				if($config['openvpn'] === true)
					restart_service($file);
			case "iptables.v4":
				copy_config($file);
				restart_service($file);
				break;
			case "iptables.v6":
				copy_config($file);
				restart_service($file);
				break;
			case "config.json":
				$state['config'] = read_config($state['cfgfile']);
				break;
			case "README.md":
				// do nothing
				break;
			default:
				msglog("agent.php", "What is this mythical config file '{$file}' of which you speak?");
				break;
		}
	}
}

function parse_dhcp_nameservers($state) {
	$ifname = find_wan_interface($state);
	$file = "/var/run/resolvconf/interfaces/{$ifname}.dhcp";
	$dns = array();
	
	if(is_readable($file))
		$dfile = file($file);

	// and ipv6 nameservers
	$file = "/var/run/resolvconf/interfaces/{$ifname}.ra";
	if(is_readable($file))
		$rfile = file($file);
		
	foreach($dfile as $line)
		if(preg_match("/^nameserver[ ]+([0-9a-f.:]+)/", $line, $matches4))
			$dns['dns4'][] = $matches4[1];

	foreach($rfile as $line)
		if(preg_match("/^nameserver[ ]+([0-9a-f.:]+)/", $line, $matches6))
			$dns['dns6'][] = $matches6[1];

	return $dns;
}

function parse_dnsmasq_leases() {
	$file = "/var/lib/misc/dnsmasq.leases";
	if(is_readable($file))
		$lfile = file($file);

	$leases = array();
	foreach($lfile as $i => $line) {
		$el = explode(" ", $line);
		$leases[$i]['time'] = $el[0];
		$leases[$i]['mac'] = $el[1];
		$leases[$i]['ip4'] = $el[2];
		$leases[$i]['hostname'] = $el[3];
	}
	return $leases;
}


function restart_service($file) {
	msglog("agent.php", "Restart service for config file '{$file}'");
	switch($file) {
			case "client.ovpn":
			case "client.ovpn.login":
				if($config['openvpn'] === false)
					return false;
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
			case "iptables.v4":
				$cmd = "sudo iptables-restore < /etc/iptables/iptables.v4;sudo iptables-save > /etc/iptables/iptables.v4;";
				break;
			case "iptables.v6":
				$cmd = "sudo iptables-restore < /etc/iptables/iptables.v6;sudo iptables-save > /etc/iptables/iptables.v6;";
				break;
			default:
				msglog("agent.php", "What is this mythical service file '{$file}' of which you speak?");
				return false;
				break;
	}
	if($cmd != ""){
		msglog("agent.php", "Running command '{$cmd}'");
		if(exec_log($cmd) === false) {
			msglog("agent.php", "Failed to restart service for {$file}");
			return false;
		}
	}
}

function disable_service($file) {
	msglog("agent.php", "Disable service for config file '{$file}'");
	switch($file) {
			case "client.ovpn":
			case "client.ovpn.login":
				$cmd = "sudo service openvpn stop; sudo systemctl disable openvpn; sudo systemctl mask openvpn";
				break;
			default:
				msglog("agent.php", "What is this mythical service file '{$file}' of which you speak?");
				return false;
				break;
	}
	if($cmd != ""){
		msglog("agent.php", "Running command '{$cmd}'");
		if(exec_log($cmd) === false) {
			msglog("agent.php", "Failed to disable service for {$file}");
			return false;
		}
	}
}
function enable_service($file) {
	msglog("agent.php", "Enable service for config file '{$file}'");
	switch($file) {
			case "client.ovpn":
			case "client.ovpn.login":
				$cmd = "sudo systemctl unmask openvpn; sudo systemctl enable openvpn; sudo service openvpn start;  ";
				break;
			default:
				msglog("agent.php", "What is this mythical service file '{$file}' of which you speak?");
				return false;
				break;
	}
	if($cmd != ""){
		msglog("agent.php", "Running command '{$cmd}'");
		if(exec_log($cmd) === false) {
			msglog("agent.php", "Failed to enable service for {$file}");
			return false;
		}
	}
}
function stop_service($file) {
	msglog("agent.php", "Stop service for config file '{$file}'");
	switch($file) {
			case "client.ovpn":
			case "client.ovpn.login":
				$cmd = "sudo service openvpn stop";
				break;
			default:
				msglog("agent.php", "What is this mythical service file '{$file}' of which you speak?");
				return false;
				break;
	}
	if($cmd != ""){
		msglog("agent.php", "Running command '{$cmd}'");
		if(exec_log($cmd) === false) {
			msglog("agent.php", "Failed to stop service for {$file}");
			return false;
		}
	}
}
function start_service($file) {
	msglog("agent.php", "Start service for config file '{$file}'");
	switch($file) {
			case "client.ovpn":
			case "client.ovpn.login":
				$cmd = "sudo service openvpn start";
				break;
			default:
				msglog("agent.php", "What is this mythical service file '{$file}' of which you speak?");
				return false;
				break;
	}
	if($cmd != ""){
		msglog("agent.php", "Running command '{$cmd}'");
		if(exec_log($cmd) === false) {
			msglog("agent.php", "Failed to start service for {$file}");
			return false;
		}
	}
}

function copy_config($file) {
	global $cfgmap;
	global $cfgdir;

	msglog("agent.php", "Copy config file '{$file}' to '{$cfgmap[$file]}'");
	$cmd = "sudo cp -a {$cfgdir}/{$file} {$cfgmap[$file]}";
	if(exec_log($cmd) === false)
		msglog("agent.php", "Failed to copy config {$file} to {$cfgmap[$file]}");
}


function move_config($file) {
	global $cfgmap;
	global $cfgdir;

	msglog("agent.php", "Move config file '{$file}' to '{$cfgmap[$file]}'");
	$cmd = "sudo mv -f {$cfgdir}/{$file} {$cfgmap[$file]}";
	if(exec_log($cmd) === false)
		msglog("agent.php", "Failed to move config {$file} to {$cfgmap[$file]}");
}

function fetch_lldp_neighbors() {
	$cmd = "lldpcli show neighbors -f json";
	exec($cmd, $out, $ret);
	if($ret > 0)
		return false;
	
	if(is_array($out))
		$lldpjson = json_decode(implode("\n", $out), true);
	else
		return false;

	return $lldpjson['lldp'];	
}

function fetch_as_info($state, $ip) {
	$asinfo = array();
	if(! preg_match("/([0-9:\.a-f]+)/", $ip, $ipmatch))
		return false;

	msglog("fetch_as_info", "Looking up AS information with whois for {$ip}");
	
	$cmd = "whois -c {$ip}";
	exec($cmd, $out, $ret);
	if($ret > 0)
		return false;

	foreach($out as $line) {
		if(preg_match("/^route:[ ]+([0-9a-f.:\/]+)/", $line, $rtmatch))
			$asinfo['route'] = $rtmatch[1];
		if(preg_match("/^origin:[ ]+([0-9a-fA-Z.:\/]+)/", $line, $asmatch))
			$asinfo['asnum'] = $asmatch[1];
		if(preg_match("/^descr:[ ]+([0-9a-fA-Z.:\/ ]+)/", $line, $orgmatch))
			$asinfo['descr'] = $orgmatch[1];
		if(preg_match("/^org-name:[ ]+([0-9a-fA-Z.:\/ ]+)/", $line, $orgmatch))
			$asinfo['descr'] = $orgmatch[1];
		
		
	}

	return $asinfo;
}

