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
		echo "Failed to list wireless networks\n";


	$iw_networks = array();
	$i = 0;
	foreach($out as $line) {
		preg_replace("/^[ ]+/i", "", $line);
		$line = trim($line);
		$el = preg_split("/:/", $line);
		if(strstr($line, "Cell")) {
			$el[0] = "Address";
			$el[1] = trim(strtolower("{$el[1]}:{$el[2]}:{$el[3]}:{$el[4]}:{$el[5]}:{$el[6]}"));
		}
		if(strstr($line, "Quality")) {
			if($el[1] == "") {
				$el[1] = trim($el[0]);
				$el[0] = "Quality";
			}
		}
		switch($el[0]) {
			case "Address":
			case "ESSID":
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

		// This interface resets counters when going up/down
		if(strstr($ifname, "tun"))
			$ifstate[$ifname]['stats64start'] = $iflist[$ifname]['stats64'];

		if(if_state($iflist, $ifname) == "UP") {
			$lease[$ifname] = dump_dhcp_lease($iflist, $ifname);
			//print_r($lease);
			if(isset($lease[$ifname]['domain_name_servers'])) {
				foreach(explode(" ", $lease[$ifname]['domain_name_servers']) as $dns_server) {
					echo "Adding route to DNS Server {$dns_server} via default GW of {$ifname}\n";
					route_add($dns_server, "");
				}
			}
		}
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

	if(!isset($ifstate[$ifname]['stats64start'])) {
		$ifstate[$ifname]['stats64start'] = $iflist[$ifname]['stats64'];
	}
	$iflist[$ifname]['stats64start'] = $ifstate[$ifname]['stats64start'];

	$iflist[$ifname]['time'] = time();
	// If we are here, we can collect some statistics.
	if(isset($ifstate[$ifname]))
		$iflist[$ifname]['traffic'] = calculate_traffic($ifstate[$ifname], $iflist[$ifname]);

	// save current interface state to the state array. 
	if(isset($iflist[$ifname]))
		return $iflist[$ifname];
	else
		return false;
}

function calculate_traffic($ifstate, $iflist) {
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
	$traffic['rx'] = ($rx/$timediff);
	$traffic['tx'] = ($tx/$timediff);

	return $traffic;
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
		echo "Looks like we can not resolve Public DNS, stop OpenVPN, reload DNSmasq\n";
		stop_service("client.ovpn");
		restart_service("dnsmasq.conf");
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
function config_write_ovpn($settings) {
	$conf = "../conf/client.ovpn";
	if(is_writeable($conf)) {
		//echo "<pre>". print_r($settings['conf'], true) ."</pre>";
		file_put_contents($conf, $settings['conf']);
		return true;
	}
}
function config_write_ovpn_login($settings) {
	$conf = "../conf/client.ovpn.login";

	if(is_writeable($conf)) {
		//echo "<pre>". print_r($settings['login'], true) ."</pre>";
		file_put_contents($conf, $settings['login']);
		return true;
	}
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
		// Hook in OpenVPN start here
		start_service("client.ovpn");

	}
	if(($msftconnect == "DNSERR") && ($captive != "DNSERR")) {
		echo "Looks like DNS doesn't work properly yet\n";
		// Hook in OpenVPN start here
		stop_service("client.ovpn");
	}
	if(($msftconnect == "PORTAL") && ($captive != "PORTAL")) {
		echo "Looks like we we are stuck behind a portal, someone needs to log in\n";

		echo "Attempting to parse the portal page\n";
		// Attempt a Portal authentication, bit basic, but anyhow.
		$result = parse_portal_page(); // Default url is msft connect
		if($result === false)
			echo "It tried, to bad, to sad, nevermind.\n";
		else
			echo "It actually worked?!\n";
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
	exec($cmd, $out, $ret);
	if ($ret > 0)
		return "DNSERR";

	$test = simple_web_request($url);

	if($test == trim($string))
		return "OK";

	if($test != trim($string))
		return "PORTAL";

}

// return request array based on parsing of portal page.
function parse_portal_page($url = ""){
	global $state;
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
		print_r($metamatches);
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
		echo "Request url '{$url}', method {$method}, vars". json_encode($vars) ."\n";
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
	print_r($forms_a);
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
	print_r($request);
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
		$address = $defgw['gateway'];
	}
	$latency = 0;

	// basic IP sanity check on address
	preg_match("/([0-9:\.a-f]+)/i", $address, $ipmatch);

	if($ipmatch[1] == "")
		return false;

	$cmd = "ping -U -W1 -c1 {$ipmatch[1]}";
	exec($cmd, $out, $ret);
	if($ret > 0) {
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

function fetch_default_route_gw() {
	// Fetch the default gateway
	$cmd = "ip -j route show default";
	exec($cmd, $out, $ret);
	if($ret > 0)
		return false;

	if(empty($out))
		return false;

	$defgw = json_decode($out[0], true);

	return ($defgw[0]);
}

// We only care for adding routes for now
function route_add($ip, $gwip = ""){
	// basic IP sanity check on address
	preg_match("/([0-9:\.a-f]+)/", $ip, $ipmatch);
	// basic IP sanity check on address
	preg_match("/([0-9:\.a-f]+)/", $gwip, $gwipmatch);
	//print_r($ipmatch);
	if($ip == "")
		return false;

	if($gwip == "")
		$defgw = fetch_default_route_gw();
	else
		$defgw['gateway'] = $gwipmatch[1];

	$cmd = "sudo ip route replace {$ipmatch[1]} via {$defgw['gateway']}";
	//print_r($cmd);
	exec($cmd, $out, $ret);
	if($ret > 0)
		return false;

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

function stop_service($file) {
	echo "Stop service for config file '{$file}'\n";
	switch($file) {
			case "client.ovpn":
			case "client.ovpn.login":
				$cmd = "sudo service openvpn stop";
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
			echo "Failed to stop service for {$file}\n";
			return false;
		}
	}
}
function start_service($file) {
	echo "Start service for config file '{$file}'\n";
	switch($file) {
			case "client.ovpn":
			case "client.ovpn.login":
				$cmd = "sudo service openvpn start";
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
			echo "Failed to start service for {$file}\n";
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
