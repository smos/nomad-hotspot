<?php


function html_header(){
	header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
	header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
	header("Content-Type: text/html");
}

function html_head(){
	echo "<html><head><title>Nomad Hotspot</title>";
	echo "<link rel='stylesheet' href='web.css'>\n";
	echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>\n";
	echo "</head>";
	echo "<body >\n";
}

function html_menu(){
	// Menu Header
	echo "<center>";
	echo "<table><tr>";
	echo "<td><a href='/status'><img height='50px' src='images/status.png' alt='Status'></a></td>";
	echo "<td><a href='/cfgif'><img height='50px' src='images/interfaces.png' alt='Interfaces'></a></td>";
	echo "<td><a href='/cfgwiap'><img height='50px' src='images/apblue.png' alt='Wifi AP'></a></td>";
	echo "<td><a href='/cfgwiclient'><img height='50px' src='images/wifi.png' alt='Wifi Client'></a></td>";
	echo "<td><a href='/cfgovpn'><img height='50px' src='images/vpnblue.png' alt='OpenVPN'></a></td>";
	echo "<td><a href='/logs'><img height='50px' src='images/json.png' alt='Logs'></a></td>";
	echo "<tr></table>\n";
}

function html_foot(){
	echo "</body></html>\n";
}

function html_button_save() {
	echo "<input type='submit' value='Save'>";
}

function html_form_open() {
	echo "<form method='post' action='{$_SERVER["REQUEST_URI"]}'>";	
}

function html_form_close() {
	echo "<form method='post' action='{$_SERVER["REQUEST_URI"]}'>";	
}

function html_config($state, $uri){
	global $cfgmap;
	html_form_open();
	html_button_save();
	switch($uri) {
		case "/cfgif":
			config_dhcpcd($state);
			echo html_jquery_reload_cfgif();
			echo html_interfaces($state);
			break;
		case "/cfgwiap":
			echo html_interfaces($state, "wlan0");
			echo html_jquery_reload_cfgap();
			config_hostapd($state);
			break;
		case "/cfgwiclient":
			echo html_interfaces($state, "wlan1");
			echo html_jquery_reload_cfgwi();
			config_supplicant($state);
			echo html_jquery_reload_wilist();
			break;
		case "/cfgovpn":
			echo html_interfaces($state, "tun0");
			echo html_jquery_reload_cfgvpn();
			config_openvpn($state);
			break;

	}
	html_form_close();
}

function config_openvpn($state) {
	echo "<br>Config OpenVPN<br>";
	// global $shm_id;
	// global $state;
	$state['config'] = read_config($state['cfgfile']);
	//echo "<pre> blah ". print_r($state['config'], true) . "</pre>";

	if(!empty($_POST)) {
		//print_r($_POST);
		$i = 0;
		foreach($_POST as $varname => $setting) {
			switch($varname) {
				case "enable":
					$enabled = $_POST[$varname];
					break;
				case "username":
					$username = $_POST[$varname];
					break;
				case "password":
					$password = $_POST[$varname];
					break;
				case "conf":
					if(!empty($_POST[$varname])) {
						echo "Found new conf data <br>";
							config_write_ovpn($_POST);
					}
					break;
			}
		}
		if((!empty($_POST['username'])) && (!empty($_POST['password']))){
			echo "Found new Login data <br>";
			$credentials = array("{$username}", "{$password}");
			if(count ($credentials) < 2) {
				echo "Not supplied both credentials<br>";
				$loginerror = true;
			} else {
				config_write_ovpn_login($credentials);
			}
		}
		if(!empty($_POST['enable'])) {
			if(($enabled == "on") && ($state['config']['openvpn'] != true)) {
				$state['config']['openvpn'] = true;
				//write_shm($shm_id, $state);
				echo "<pre>";
				save_config($state['cfgfile'], $state['config']);
				enable_service("client.ovpn");
				restart_service("client.ovpn");
				echo "</pre>";
			}
		}
		if(empty($_POST['enable'])) {
			if(($enabled == "") && ($state['config']['openvpn'] != false)) {
				$state['config']['openvpn'] = false;
				//write_shm($shm_id, $state);
				echo "<pre>";
				save_config($state['cfgfile'], $state['config']);
				stop_service("client.ovpn");
				disable_service("client.ovpn");
				echo "</pre>";
			}
		}
	}

	$settings = config_read_ovpn($state);
	$state['config'] = read_config($state['cfgfile']);
	//echo "<pre> blah ". print_r($state['config'], true) . "</pre>";

	echo "OpenVPN Enabled<br>";
	if($state['config']['openvpn'] === true) {
		//echo "hoi;";
		$checked = "on";
	}
	html_checkbox("enable", "on", $checked);
	echo "<br>";
	echo "OpenVPN client username. Existing not shown.<br>";
	html_input("username", "");
	echo "<br>";
	echo "OpenVPN client username. Existing not shown.<br>";
	html_input("password", "");
	echo "<br>";
	echo "OpenVPN client configuration below<br>";
	html_textarea("conf", $settings['conf'], 120, 80);
	echo "<br>";


	//echo "<pre>". print_r($settings, true);
	return $state;
}

function config_dhcpcd($state) {
	echo "<br>Config interfaces dhcpcd<br>";
	echo "<table border=1><tr><td>";
	$settings = config_read_dhcpcd($state);
	echo "<form >";
	foreach($settings as $ifname => $setting) {
		echo "Interface {$ifname}:";
		echo "<br>";
		//print_r($setting);
		html_hidden("interface", $ifname);
		echo "Mode: ";
		html_radio("{$ifname}mode", array("dhcp" => "dhcp", "static" => "static"), $setting['mode']);
		echo "<br>";
		echo "AP mode: ";
		html_checkbox("{$ifname}nohook", "wpa_supplicant", $setting['nohook']);
		echo "<br>";
		echo "IP4 Address: ";
		html_input("{$ifname}ip4", $setting['ip4']);
		echo "<br>";
		echo "IP4 Mask: "; 
		html_select("{$ifname}prefixlen4", array(24 => 24, 22 => 22), $setting['prefixlen4']);
		echo "<br>";
	}
	echo "</form >";
	echo "</td></tr></table>\n";
	//echo print_r($settings, true);
	// Will want to write dnsmasq.conf too, for LAN address(es) and dhcp range(s).
}

function config_hostapd($state) {
	echo "<br>Config interfaces hostapd: <br>";
	echo "<table border=1><tr><td>";
	$settings = config_read_hostapd($state);
	foreach($settings as $varname => $setting) {
		switch($varname) {
			case "country_code":
				echo "Country setting for Wireless AP adapter: ";
				html_select($varname, array("NL" => "NL", "US" => "US", "JP" => "JP"), $setting);
				break;
			case "interface":
				echo "AP Interface: ";
				html_select($varname, array("wlan0" => "wlan0"), $setting);
				break;
			case "interface":
				echo "AP SSID: "; 
				html_input($varname, $setting);
				break;
			case "wpa_passphrase":
				echo "AP Passphrase: "; 
				html_input($varname, $setting);
				break;
			case "wpa":
				echo "WPA version: ";
				html_select($varname, array(2 => 2), $setting);
				break;
			case "wpa_key_mgmt":
				echo "WPA mode: ";
				html_select($varname, array("WPA-PSK" => "WPA-PSK"), $setting);
				break;
			case "wpa_pairwise":
				echo "WPA Keying: ";
				html_select($varname, array("TKIP" => "TKIP"), $setting);
				break;
			case "rsn_pairwise":
				echo "RSN Keying: ";
				html_select($varname, array("CCMP" => "CCMP"), $setting);
				break;
			case "hw_mode":
				echo "AP band: ";
				html_select($varname, array("a" => "a", "n"=>"n"), $setting);
				break;
			case "channel":
				echo "AP Channel: "; 
				html_select($varname, array(1 => 1, 6 => 6, 11 => 11, 36 => 36, 40 => 40, 44 => 44, 48 => 48), $setting);
				break;
		}
		echo "<br>";
	}
	echo "</td></tr></table>\n";
	//echo print_r($settings, true);
}

function validate_select($array, $item){
	if(in_array($item, $array))
		return true;
	else
		return false;
}

function list_bssid_assoc($wi_list, $ssid = "") {
	$bssid_a = array("" => "Roam");

	foreach ($wi_list as $entry) {

		if($entry['ESSID'] == "\"{$ssid}\"") {

				preg_match("/\((.*?)\)/", $entry['Frequency'], $chnmatch);
			$bssid_a[$entry['Address']] = "{$chnmatch[1]} {$entry['Quality']}";
		
		}
	
	}

	// echo "<pre>". print_r($bssid_a, true) ."</pre>";
	return $bssid_a;

}

function config_supplicant($state) {
	echo "<br>Config wireless client networks<br>";
	echo "<table border=1><tr><td>\n";
	// Process POST request
	// fetch channel list for bbsid per ssid
	$ifname = "wlan1";
		$wi_list = list_iw_networks($state, $ifname);
		// echo "<pre>". print_r($wi_list, true) ."</pre>";
	
	
	$settings = config_read_supplicant($state);
	// Empty item at the end for adding new entry
	//$settings['network'][] = array("ssid" => "", "psk" => "", "key_mgmt" => "NONE", "priority" => "-1");
	//echo "<pre>". print_r($settings, true);
	$countries = array("NL" => "NL", "US" => "US", "JP" => "JP");
	$key_mgmt = array("WPA-PSK" => "WPA-PSK", "NONE" => "NONE");
	$priorities = array("-1" => "-1", "0" => "0", "1" => "1", "2" => "2", "3" => "3");
	// Compare 
	if(!empty($_POST)) {
		// echo "<pre>". print_r($_POST, true);
		$i = 0;
		foreach($settings as $varname => $setting) {
			switch($varname) {
				case "country":
					if(validate_select($countries, $_POST[$varname]))
						$settings[$varname] = $setting;
					break;
				case "network":
					foreach($setting as $index => $values) {
						// removing empty doesn't work
						// echo " Index {$index}, ssid '{$settings['network'][$index]['ssid']}'";
 						if($settings['network'][$index]['ssid'] = "") {
							echo "network index {$index} ssid empty";
							unset($settings['network'][$index]);
							continue;
						}
						if(!isset($settings['network'][$index]['ssid'])){
							echo "network index {$index} ssid not set";
							unset($settings['network'][$index]);
							continue;
						}
						foreach(array("ssid", "psk", "priority", "key_mgmt", "bssid") as $name) {
							$var = "{$index}{$name}";
							switch($name) {
								case "bssid":
								case "ssid":
								case "psk":
									$settings['network'][$index][$name] = $_POST[$var];
									break;
								case "priority":
									if(validate_select($priorities, $_POST[$var]))
										$settings['network'][$index][$name] = $_POST[$var];
									break;
								case "key_mgmt":
									if(validate_select($key_mgmt, $_POST[$var]))
										$settings['network'][$index][$name] = $_POST[$var];
									break;
							}
						}
					}
			}
		}
		// echo "<pre>" . print_r($settings, true) ."</pre>";
		// Check for new entry
		$index = count($settings['network']) +1;
		$var = "{$index}ssid";
		// echo "<pre>". print_r($var, true);
		
		//echo "$_POST[$var]";
		if(isset($_POST[$var]) && ($_POST[$var] != "")) {
			foreach(array("ssid", "psk", "priority", "key_mgmt", "bssid") as $name) {
				$var = "{$index}{$name}";
				switch($name) {
					case "ssid":
					case "bssid":
					case "psk":
						$settings['network'][$index][$name] = $_POST[$var];
						break;
					case "priority":
						if(validate_select($priorities, $_POST[$var]))
							$settings['network'][$index][$name] = $_POST[$var];
						break;
					case "key_mgmt":
						if(validate_select($key_mgmt, $_POST[$var]))
							$settings['network'][$index][$name] = $_POST[$var];
						break;
				}
			}
		}

		config_write_supplicant($settings);
		$settings = config_read_supplicant($state);
	}
	// echo "<pre>".  print_r($settings, true) ."</h>";
	// Empty item at the end for adding new entry
	$settings['network'][] = array("ssid" => "", "psk" => "", "key_mgmt" => "NONE", "bssid" => "");

	foreach($settings as $varname => $setting) {
		switch($varname) {
			case "country":
				echo "Country setting for Wireless adapter: ";
				html_select($varname, $countries, $setting);
				echo "<br>\n";
				break;
			case "network":
				//echo "Client networks to connect to:<br/>";
				foreach($setting as $index => $values){
					// echo "<pre>". print_r($values, true) ."</pre>";
					echo "Index {$index} <br>\n";
					echo "SSID: ";
					html_input("{$index}ssid", $values['ssid']) ."<br>\n";
					echo "Pre Shared Key: ";
					html_input("{$index}psk", $values['psk']) ."<br>\n";
					echo "Type: ";
					html_select("{$index}key_mgmt", $key_mgmt, $values['key_mgmt']) ."<br>\n";
					echo "<br>";
					echo "Bssid: ";
					$bssid_a = list_bssid_assoc($wi_list, $values['ssid']);
					if(!isset($bssid_a[$values['bssid']]))
						$bssid_a[$values['bssid']] = "Configured to '{$values['bssid']}'";
					html_select("{$index}bssid", $bssid_a, $values['bssid']) ."<br>\n";
					echo "<br>";
				}
		}
	}
	echo "</td></tr></table>\n";
	echo " <div id='wilist'>";
	echo "<table border=1><tr><td>Loading Wireless network list ...\n";
	echo "<script type='text/javascript'>\n";
	echo "
		\$(document).ready(function() {
			\$('#wilist').load(\"/wilist\");
		});
";
	echo "</script>\n";
	echo "</td></tr></table>\n";
	echo "</div>\n";
}

function html_wi_network_list($state) {
	echo " <div id='wilist'>";
	echo "<table border=1><tr><td>";
	$settings = config_read_supplicant($state);

	foreach ($state['if'] as $ifname => $iface) {
		// Skip AP interface
		if($ifname == "wlan0")
			continue;

		if(empty($state['if'][$ifname]['wi']))
			continue;

		echo "<strong>Wireless network list {$ifname}</strong><br>";
		//echo "<table border=1><tr><td>ssid</td><td>encryption</td><td>Quality</td></tr>\n";
		echo "<table border=1><tr><td>ssid</td></tr>\n";
		//print_r($iface['wi']);
		if(is_array($iface['wi']))
			$wi_list = list_iw_networks($state, $ifname);


		$clean_wi_list = clean_wi_list($wi_list);
		// echo "<pre>". print_r($clean_wi_list, true) ."</pre>";

		$index = count($settings['network']) +1;
		$ssidvar = "{$index}ssid";
		$encvar = "{$index}key_mgmt";
		$pskvar = "{$index}psk";

		echo "<script>\n";
		echo "	function setssid(ssid) { \n";
//		echo "    var txt=document.getElementById(\"{$ssidvar}\").value; \n";
//		echo "    txt= ssid; \n";
		echo "    document.getElementById(\"{$ssidvar}\").value=ssid; \n";
		echo "    } \n";
		echo "	function setenc(enc) { \n";
//		echo "	  console.log(enc);\n";
		echo "	    if(enc == 'on') { \n";
		echo " 	    newpsk='EnterPasswordHere'; \n";
		echo " 	    newkey_mgmt='WPA-PSK'; \n";
		echo "		document.getElementById(\"{$pskvar}\").focus(); \n";
		echo " 		   } else { \n";
		echo " 	    newpsk=''; \n";
		echo " 	    newkey_mgmt='NONE'; \n";
		echo " 	   } \n";
//		echo "	  console.log(newkey_mgmt);\n";
//		echo "	  console.log(newpsk);\n";
//		echo "  	  document.getElementById(\"{$pskvar}\").value=newpsk; \n";
		echo "  	  document.getElementById(\"{$encvar}\").value=newkey_mgmt; \n";
//		echo "	    var txt=document.getElementById(\"{$pskvar}\").value; \n";
//		echo "  	  txt= newpsk; \n";
		echo "    } \n";
		echo "</script>\n";
		
		
		if(is_array($clean_wi_list)) {
			foreach($clean_wi_list as $entry => $fields) {

				echo "<tr>";
				$entry = str_replace("\"", "", $entry);
				if($entry == "")
					continue;
				//echo "<td>'{$entry}'</a></td>";
				//echo print_r($fields, true);
				$bgcolor = scale_to_colorname($fields['snr']);
				echo "<td bgcolor='{$bgcolor}'><input type=\"button\" value=\"{$entry}\" name=\"no\" onclick=\"setssid(this.value)\"></td>";
				foreach($fields as $fname =>$field) {
					switch($fname) {
						case "snr":
							continue 2;
						case "bssid":
							continue 2;
						case "encryption":
							continue 2;
							echo "<td><input type=\"button\" value=\"{$field}\" name=\"no\" onclick=\"setenc(this.value)\"></td>";
							break;
						default:
							echo "<td>{$field}</td>";
							break;
					}
				}
				echo "</tr>\n";
			}
		}
		echo "</table>";
	}
	echo "</td></tr></table>\n";
	echo "</div>\n";
}

function html_hidden($varname, $value){
	echo "<input type=hidden name='{$varname}' value='{$value}' >";
}

function html_textarea($varname, $value, $rows, $cols){
echo "<textarea name='{$varname}' rows='{$rows}' cols='{$cols}'>{$value}</textarea>";
}

// Generate a drop down
function html_select($varname, $options, $selected) {
	echo "<select name='{$varname}' id='{$varname}' >";
	foreach($options as $option => $name) {
		$sel = '';
		if($option == $selected)
			$sel = "selected";
		echo "<option value='{$option}' {$sel} >{$name}</option>";
	}
	echo "</select>";
}
// Generate a input box
function html_input($varname, $existing) {
	$strtype = "text";
	if(stristr($varname, "password"))
		$strtype = "password";
	echo "<input type='{$strtype}' name='{$varname}' id='{$varname}' value='{$existing}' >";
}

// Generate a radio button
function html_radio($varname, $options, $selected) {
	foreach($options as $option => $name) {
		$sel = '';
		if($option == $selected)
			$sel = "checked";
		echo "<input type=radio name='{$varname}' value='{$option}' {$sel} >{$name}";
	}
}
// Generate a radio button
function html_checkbox($varname, $option, $selected) {
	$sel = '';
	if($option == $selected)
		$sel = "checked";

	echo "<input type=checkbox name='{$varname}' value='{$option}' {$sel} >{$name}";
}

function html_jquery(){
//	echo "<script
//  src='jquery-3.5.1.slim.min.js'
//  integrity='sha256-4+XzXVhsDmqanXGHaHvgh1gMQKX40OUvDEBTu8JcmNs='
//  crossorigin='anonymous'></script>\n";
  
echo "<script
  src='jquery-3.5.1.min.js'
  integrity='sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0='
  crossorigin='anonymous'></script>\n";
}

function html_jquery_reload(){
	echo "<script type='text/javascript'>\n";
	echo "

\$(document).ready(function() {
var pageRefresh = 1000; //1 s
    setInterval(function() {
        refresh();
    }, pageRefresh);
});

	// Functions

function refresh() {
    \$('#connectivityextra').load(\"/connectivityextra\");
    \$('#processing').load(\"/processing\");
    \$('#clients').load(\"/clients\");
}

";
	echo "</script>";
//    \$('#interfaces').load(\"/interfaces\");
//    \$('#processes').load(\"/processes\");

}

function html_jquery_reload_screensaver(){
	echo "<script type='text/javascript'>\n";
	echo "

\$(document).ready(function() {
var pageRefresh = 1000; //1 s
    setInterval(function() {
        refresh();
    }, pageRefresh);
});

	// Functions

function refresh() {
    \$('#connectivityscreensaver').load(\"/connectivityscreensaver\");
    \$('#bwup').load(\"/bwup\");
    \$('#bwdown').load(\"/bwdown\");
    \$('#processing').load(\"/processing\");
    \$('#clients').load(\"/clients\");
}

";
	echo "</script>";

}


function html_jquery_reload_cfgif(){
	echo "<script type='text/javascript'>\n";
	echo "

\$(document).ready(function() {
var pageifRefresh = 1000; //1 s
    setInterval(function() {
        ifrefresh();
    }, pageifRefresh);
});

	// Functions

function ifrefresh() {
    \$('#interfaces').load(\"/interfaces\");
}

";
	echo "</script>";

}

function html_jquery_reload_cfgwi(){
	echo "<script type='text/javascript'>\n";
	echo "

\$(document).ready(function() {
var pageifRefresh = 1000; //1 s
    setInterval(function() {
        ifrefresh();
    }, pageifRefresh);
});

	// Functions

function ifrefresh() {
    \$('#interfaces').load(\"/interfacewlan1\");
}

";
	echo "</script>";

}

function html_jquery_reload_cfgap(){
	echo "<script type='text/javascript'>\n";
	echo "

\$(document).ready(function() {
var pageifRefresh = 1000; //1 s
    setInterval(function() {
        ifrefresh();
    }, pageifRefresh);
});

	// Functions

function ifrefresh() {
    \$('#interfaces').load(\"/interfacewlan0\");
}

";
	echo "</script>";

}

function html_jquery_reload_wilist(){
	echo "<script type='text/javascript'>\n";
	echo "

\$(document).ready(function() {
var pagewiRefresh = 30000; //30 s
    setInterval(function() {
        wirefresh();
    }, pagewiRefresh);
});

	// Functions

function wirefresh() {
    \$('#wilist').load(\"/wilist\");
}

";
	echo "</script>";

}

function html_jquery_reload_cfgvpn(){
	echo "<script type='text/javascript'>\n";
	echo "

\$(document).ready(function() {
var pageifRefresh = 1000; //1 s
    setInterval(function() {
        ifrefresh();
    }, pageifRefresh);
});

	// Functions

function ifrefresh() {
    \$('#interfaces').load(\"/interfacetun0\");
}

";
	echo "</script>";

}

function html_status($state){
	echo "<center>";
	echo "<table border=0><tr><td valign=top>";
	echo html_bw_down($state);
	echo "</td><td>";
	echo html_connectivity_screensaver($state);
	echo "</td><td valign=bottom>";
	echo html_bw_up($state);
	echo "</td></tr>\n";
	echo "</table>";
	
	//echo " <div id='interfaces'></div>\n";
	//echo html_interfaces($state);
	//echo " <div id='connectivity'></div>\n";
	//echo html_connectivity($state);
	echo html_processing($state);
	//echo " <div id='clients'></div>\n";
	echo html_clients($state);
	//echo " <div id='processes'></div>\n";
	//echo html_processes($state);
}

function html_status_extra($state){
	echo "<center>";
	echo "<table border=0><tr><td valign=top>";
	//echo html_bw_down($state);
	//echo "</td><td>";
	echo html_connectivity_extra($state);
	//echo "</td><td valign=bottom>";
	//echo html_bw_up($state);
	//echo "</td>
	echo "</tr>\n";
	echo "</table>";
	
	//echo " <div id='interfaces'></div>\n";
	//echo html_interfaces($state);
	//echo " <div id='connectivity'></div>\n";
	//echo html_connectivity($state);
	echo html_processing($state);
	//echo " <div id='clients'></div>\n";
	echo html_clients($state);
	//echo " <div id='processes'></div>\n";
	//echo html_processes($state);
}

function filter_log ($proc, $logfile = "/var/log/syslog", $limit = 20) {
	switch($proc) {
		case "agent.php":
		case "web.php":
		case "hostapd":
		case "ovpn-client":
		case "dnsmasq\[":
		case "dhcpcd":
			break;
		default:
			return false;		
	}
	$limit = intval($limit);
	$cmd = "awk '/{$proc}/ {print $0}' '{$logfile}'| tail -n{$limit}";
	if($cmd != ""){
		exec($cmd, $out, $ret);
		if($ret > 0) {
			msglog("web.php", "Failed to fetch log results for {$proc}");
			return false;
		}
	}
	return $out;
}

function html_log ($state) {
	echo "<div id='logs'>";
	$cats = array("Agent" => "agent.php", "Web" => "web.php", "Accesspoint" => "hostapd", "OpenVPN" => "ovpn-client", "Dnsmasq" => "dnsmasq\[", "DHCPcd" => "dhcpcd");
	foreach($cats as $name => $proc) {
		echo "<table border=0>";
		echo "<tr><td>{$name}</td></tr>\n";
		 
		foreach(filter_log($proc) as $line) {
			$line = preg_replace("/nomad-hotspot/", "", $line);
			echo "<tr><td>{$line}</td></tr>\n";
		}
		echo "</table>\n";
	}
	echo "</div>";
	
}

function html_logs($state){
	echo "<table border=0><tr><td valign=top>";
	echo "</td></tr>\n";
	echo html_log($state);
	echo "<tr><td>\n";
	echo html_clients($state);
	echo "</td></tr>\n";
	echo "<tr><td>\n";
	echo "<div id='json'>";
	echo "<table><tr><td>";
	echo "<a href='/json?'>JSON</a>";
	echo "<pre>";
	echo json_encode($state, JSON_PRETTY_PRINT);
	echo "</pre>";
	echo "</td></tr></table>";
	echo "</div>";
	echo "</td></tr>\n";
	echo "</table>";
}

function html_status_screensaver($state){
	echo "<center>";
	echo "<table border=0><tr><td valign=top>";
	echo html_bw_down($state);
	echo "</td><td>";
	echo html_connectivity_screensaver($state);
	echo "</td><td valign=bottom>";
	echo html_bw_up($state);
	echo "</td></tr>\n";
	echo "</table>";
	echo html_processing($state);
	echo html_clients($state);
}

function html_bw_up_bar($state) {
	$ifname = find_wan_interface($state);
	$height = round(($state['if'][$ifname]['traffic']['tx'] / $state['traffic'][$ifname]['toptx']),2) * 100;
	$rest = abs(100 - $height); 
	echo "<!-- current rx {$state['if'][$ifname]['traffic']['tx']} top rx {$state['traffic'][$ifname]['toptx']}-->\n";
	echo " <div id='bwup'>";
	echo "<table border=0 width='50px' valign='bottom' height='550px'>\n";
	echo "<tr><td valign='top'>". thousandsCurrencyFormat(($state['traffic'][$ifname]['toptx'] * 8)) ."bit</td></tr>\n";
	echo "<tr><td height='{$rest}%'></td></tr>\n";
	echo "<tr><td bgcolor='lightblue' height='{$height}%'></td></tr>\n";
	echo "</table>\n";	
	echo "</div>\n";		
}

function html_bw_up($state) {
	$ifname = find_wan_interface($state);
	
	echo "<!-- current rx {$state['if'][$ifname]['traffic']['tx']} top rx {$state['traffic'][$ifname]['toptx']}-->\n";
	echo " <div id='bwup'>";
	echo "<table border=0 width='150px' valign='bottom' height='500px' cellpadding=0 cellspacing=0>\n";
	$state['traffic'][$ifname]['hist']['tx'] = array_reverse($state['traffic'][$ifname]['hist']['tx']);
	foreach($state['traffic'][$ifname]['hist']['tx'] as $counter) {
		$height = round(($counter / $state['traffic'][$ifname]['toptx']),2) * 150; //percentage
		$rest = abs(100 - $height); 
	
		echo "<tr>";
		//echo "<td width='{$rest}%'>&nbsp;</td>";
		echo "<td align=right ><img height='2px' width='{$height}' border=0 src='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkPfenHgAE/wJQ/BZMvAAAAABJRU5ErkJggg=='></td>";
		echo "</tr>\n";
	
	}
	echo "<tr><td valign='top' >". thousandsCurrencyFormat(($state['traffic'][$ifname]['toptx'] * 8)) ."bit</td></tr>\n";
	echo "</table>\n";	
	echo "</div>\n";		
}

function html_bw_down_bar($state) {
	$ifname = find_wan_interface($state);
	$height = round(($state['if'][$ifname]['traffic']['rx'] / $state['traffic'][$ifname]['toprx']), 2) * 100;
	$rest = abs(100 - $height); 

	echo "<!-- current rx {$state['if'][$ifname]['traffic']['rx']} top rx {$state['traffic'][$ifname]['toprx']}-->\n";
	echo " <div id='bwdown'>";
	echo "<table border=0 width='50px'valign='top' height='550px'>";
	echo "<tr><td bgcolor='lightblue' height='{$height}%'></td></tr>\n";
	echo "<tr><td height='{$rest}%'></td></tr>\n";
	echo "<tr><td valign='bottom'>". thousandsCurrencyFormat(($state['traffic'][$ifname]['toprx'] * 8)) ."bit</td></tr>\n";	
	echo "</table>";
	echo "</div>\n";		
}

function html_bw_down($state) {
	$ifname = find_wan_interface($state);
	echo "<!-- current rx {$state['if'][$ifname]['traffic']['rx']} top rx {$state['traffic'][$ifname]['toprx']}-->\n";
	echo " <div id='bwdown'>";
	echo "<table border=0 width='150px' valign='bottom' height='500px' cellpadding=0 cellspacing=0>\n";
	foreach($state['traffic'][$ifname]['hist']['rx'] as $counter) {
		$height = round(($counter / $state['traffic'][$ifname]['toprx']),2) * 150; //percentage
		$rest = abs(100 - $height); 
	
		echo "<tr>";
		//echo "<td width='{$rest}%'>&nbsp;</td>";
		echo "<td align=left ><img height='2px' width='{$height}' border=0 src='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkPfenHgAE/wJQ/BZMvAAAAABJRU5ErkJggg=='></td>";
		echo "</tr>\n";
	
	}
	echo "<tr><td valign='top' >". thousandsCurrencyFormat(($state['traffic'][$ifname]['toprx'] * 8)) ."bit</td></tr>\n";
	echo "</table>\n";	
	echo "</div>\n";		
}


function html_interfaces($state, $interface = ""){

	echo " <div id='interfaces'>";
	echo "<table border=0>\n";
	//echo "<pre>".  print_r($state['if'][$interface]['wi'], true) ."</h>";
	//<tr><td>Interface</td><td>State</td></tr>\n";
	//echo "<tr><td>Adresses</td><td>Traffic</td><td>Totals</td><td>Info</td></tr>\n";
	foreach ($state['if'] as $ifname => $iface) {
		
		if(!preg_match("/{$interface}/i", $ifname)) {
			continue;
		}
		
		$wireless = "&nbsp;";
		//print_r($iface['wi']);
		
		//echo "<tr><td>{$ifname}</td><td>". if_state($state['if'], $ifname)."</td><td>". implode('<br />', if_prefix($state['if'], $ifname)) ."</td><td>". round(html_traffic_speed($state['if'], $ifname)) ."</td><td>". round(html_traffic_total($state['if'], $ifname)) ."</td><td>{$wireless}</td></tr>\n";
		echo "<tr><td>Interface: {$ifname}, State: ". if_state($state['if'], $ifname)."</td></tr>\n";
		if((is_array($iface['wi'])) && ($iface['wi']['mode'] != "Master")){
			//$wireless = "&nbsp;&nbsp;SSID: '{$iface['wi']['essid']}', BSSID: {$iface['wi']['bssid']}";
			//echo "<tr><td >{$wireless}</td></tr>\n";			echo "<tr><td >&nbsp;&nbsp;Frequency {$iface['wi']['frequency']}, Type {$iface['wi']['phy']}, Rate {$iface['wi']['rate']} Mbit/s</td></tr>\n";
			echo "<tr><td >";
			echo html_wi_link_bar($iface);
			echo html_list_wi_link($state, $ifname);
			echo "</td></tr>\n";
		}
		if(!empty(if_prefix($state['if'], $ifname))) {
			echo "<tr><td >&nbsp;&nbsp;IP ". implode('<br />&nbsp;&nbsp;', if_prefix($state['if'], $ifname)) ."</td></tr>\n";
		}

		if(!empty($state['lldp']['interface'][$ifname])) {
			echo "<tr><td >";
			echo html_list_lldp($state, $ifname);
			echo "</td></tr>\n";
		}

		$defgw = fetch_default_route_gw();
		if(!empty($defgw6)) {
			echo "<tr><td >&nbsp;&nbsp;Gateway6 {$defgw[6][0]['gateway']}</td></tr>\n";
		}
		if(!empty($defgw4)) {
			echo "<tr><td >&nbsp;&nbsp;Gateway4 {$defgw[4][0]['gateway']}</td></tr>\n";
		}

		//echo "<tr><td>". round(html_traffic_speed($state['if'], $ifname)) ."</td><td>". round(html_traffic_total($state['if'], $ifname)) ."</td></tr>\n";
	}
	echo "</table>";	
	echo "</div>\n";		
}

function html_wi_link_bar($iface, $width = 300) {

	if($width < 150) {
		$qstr = "";
		$lstr = "";
		$lwidth = "0";
		$factor = 1;
	} else {
		$qstr = "Quality";
		$lstr = "Level";
		$lwidth = "60";
		$factor = 3;
	
	}
	
	$qcolor = scale_to_colorname($iface['wi']['quality']);
	$lcolor = scale_to_colorname($iface['wi']['level']);

			echo "<table>";
			echo "<tr><td width={$lwidth}px >{$qstr}</td><td bgcolor={$qcolor} width='{$iface['wi']['quality']}px'>&nbsp;{$iface['wi']['quality']}</td><td width='". ((100 - $iface['wi']['quality'])*$factor) ."px'>&nbsp;</td></tr>";
			echo "</table>\n";
			echo "<table>";
			echo "<tr><td width={$lwidth}px >{$lstr}</td><td bgcolor={$lcolor} width='{$iface['wi']['level']}px'>&nbsp;{$iface['wi']['level']}</td><td width='". ((100 - $iface['wi']['level'])*$factor) ."px'>&nbsp;</td></tr>";
			echo "</table>\n";

}

function html_connectivity($state){
	echo " <div id='connectivity'>";
	echo "<table border=0><tr><td>Connectivity</td><td>Result</td></tr>\n";
	foreach ($state['internet'] as $check => $result) {	
		if($check == "url")
	$result = "<a target=\"_blank\" href='{$result}'>{$result}</a>";
		echo "<tr><td>{$check}</td><td>{$result}</td></tr>\n";
	}
	echo "</table>";
	echo "</div>\n";		
		
}

function scale_to_color($num = 0) {
	$bgcolor = "red";
	$num = floatval($num);
	if($num < 20)
		$bgcolor = "red";
	elseif($num< 40)
		$bgcolor = "orange";
	elseif($num< 60)
		$bgcolor = "orange";
	elseif($num> 59)
		$bgcolor = "green";

	return $bgcolor;
}

function scale_to_colorname($num = 0) {
	$bgcolor = "crimson";
	$num = floatval($num);
	if($num < 20)
		$bgcolor = "crimson";
	elseif($num< 40)
		$bgcolor = "darkorange";
	elseif($num< 60)
		$bgcolor = "yellow";
	elseif($num> 59)
		$bgcolor = "forestgreen";

	return $bgcolor;
}


function html_connectivity_screensaver($state){
	$hrefo = "";
	$hrefc = "";
	echo " <div id='connectivityscreensaver'>";
	echo "<table>";
	// VPN
	$img = "images/vpngrey.png";
	$vpncon = "Not configured";
	if(isset($state['if']['tun0'])) {
		// print_r($state['if']['tun0']['addr_info'][0]['local']);
		if(!empty($state['if']['tun0']['addr_info'][0]['local'])) {
			$img = "images/vpngreen.png";
			$vpncon = "Connected with address: {$state['if']['tun0']['addr_info'][0]['local']}";
		} else {
			$img = "images/vpnred.png";
			$vpncon = "Not connected";
		}
		echo "<tr><td><img height='125px' src='{$img}' alt='VPN: {$vpncon}'></td></tr>\n";
	}
	// Internet, ping color
	$color = "grey";
	if($state['internet']['ping'] == 999)
		$color = "blue";
	if($state['internet']['ping'] < 999)
		$color = "red";
	if($state['internet']['ping'] < 300)
		$color = "orange";
	if($state['internet']['ping'] < 100)
		$color = "green";

	switch($state['internet']['captive']) {
		case "TIMEOUT":
			$color = "red";
			$img = "images/globe{$color}.png";
			break;
		case "OK":
			$img = "images/globe{$color}.png";
			break;;
		case "PORTAL":
		case "NOK":
			$img = "images/nogo.png";
			$hrefo = "<a target='_parent' href='http://www.msftconnecttest.com/connecttest.txt' >";
			$hrefc = "</a>";
			break;;
		case "DNSERR":
			$img = "images/nogo.png";
			break;;
		default:
			$img = "images/globegrey.png";
			break;;
	}
	echo "<tr><td>{$hrefo}<img height='125px' src='{$img}' alt='Internet: {$state['internet']['captive']} Latency: {$state['internet']['ping']}'>{$hrefc}</td></tr>\n";

	// DNS
	switch($state['internet']['dns']) {
		case "OK":
			$img = "images/dnsgreen.png";
			break;;
		case "NOK":
			$img = "images/dnsred.png";
			break;;
		default:
			$img = "images/dnsgrey.png";
			break;;
	}
	echo "<tr><td><img height='125px' src='{$img}' alt='DNS: {$state['internet']['captive']}'></td></tr>\n";
	
	// find the default route interface
	$defif = find_wan_interface($state);
	
	if($defif != "") {
		// check for wireless stats
		if(isset($state['if'][$defif]['wi'])) {
			
			$bgcolor = scale_to_color(round(($state['if'][$defif]['wi']['quality'] + $state['if'][$defif]['wi']['level']) / 2));
				
			$img = "images/wifi{$bgcolor}.png";
			echo "<tr><td>";
			echo html_wi_link_bar($state['if'][$defif], 140);
			echo "</br>";
			echo "<img height='125px' src='{$img}' alt='WAN: {$state['if'][$defif]['wi']['quality']}'></td></tr>\n";
		} else {
			// must be wired
			$bgcolor = "green"; // place holder for now without indicators
			$img = "images/ether{$bgcolor}.png";
			echo "<tr><td><img height='125px' src='{$img}' alt='WAN ethernet'></td></tr>\n";
		}
		
	}
	
	echo "</table>";
	echo "</div>\n";		
		

/* 
	// AP is kind of a waste of screen real estate.
	switch($state['if']['wlan0']['wi']['mode']) {
		case "Master":
			$img = "images/apgreen.png";
			break;;
		default:
			$img = "images/apgrey.png";
			break;;		
	}
	// echo "<tr><td><img height='125px' src='{$img}' alt='AP: {$state['if']['wlan0']['wi']['type']}'></td></tr>\n";
*/


}

function html_list_dns($state) {	
	echo "<td>";
	foreach ($state['dns'] as $family => $entries) {
		foreach ($entries as $entry) {
			echo strtoupper($family) ." {$entry} </br>\n";
		}
	}
	echo "</td>";
}

function html_list_lldp($state, $defif) {
	// Fetch LLDP Info
	if(isset($state['lldp']['interface'][$defif])) {
		foreach($state['lldp']['interface'][$defif]['chassis'] as $id) {
			if(isset($id['descr'])) {
				echo "LLDP descr {$id['descr']} </br>";
			}
		}
		if(isset($state['lldp']['interface'][$defif]['port']['descr'])) {
				echo "LLDP port {$state['lldp']['interface'][$defif]['port']['descr']} </br>";
		}
	}
}

function html_list_wi_link($state, $defif) {
	if(isset($state['if'][$defif]['wi'])) {
		foreach($state['if'][$defif]['wi'] as $field => $value) {
			switch($field) {
				case "mode":
					continue 2;
				case "quality":
					echo ucwords($field) ." ". $value ." ";
					break;
				default:
					echo ucwords($field) ." ". $value ."</br>";
			}
		}

	}
}

function html_connectivity_extra($state){
	$hrefo = "";
	$hrefc = "";
	// find the default route interface
	$defif = find_wan_interface($state);

	echo " <div id='connectivityextra'>";
	echo "<table>";
	// VPN
	$img = "images/vpngrey.png";
	$vpncon = "Not configured";
	if(isset($state['if']['tun0'])) {
		// print_r($state['if']['tun0']['addr_info'][0]['local']);
		if(!empty($state['if']['tun0']['addr_info'][0]['local'])) {
			$img = "images/vpngreen.png";
			$vpncon = "Connected with address: {$state['if']['tun0']['addr_info'][0]['local']}";
		} else {
			$img = "images/vpnred.png";
			$vpncon = "Not connected";
		}
		echo "<tr><td><img height='125px' src='{$img}' alt='VPN: {$vpncon}'></td>";
		echo "<td>";
		$ifname = "tun0";
		if(isset($state['if'][$ifname])) {
			echo "VPN {$ifname} </br>";
			if(!empty(if_prefix($state['if'], $ifname))) {
				echo "IP ". implode('<br />IP ', if_prefix($state['if'], $ifname)) ."</br>\n";
			}
		}
		echo "</td>";
		echo "</tr>\n";
	}
	// Internet, ping color
	$color = "grey";
	if($state['internet']['ping'] == 999)
		$color = "blue";
	if($state['internet']['ping'] < 999)
		$color = "red";
	if($state['internet']['ping'] < 300)
		$color = "orange";
	if($state['internet']['ping'] < 100)
		$color = "green";

	switch($state['internet']['captive']) {
		case "TIMEOUT":
			$color = "red";
			$img = "images/globe{$color}.png";
			break;
		case "OK":
			$img = "images/globe{$color}.png";
			break;;
		case "PORTAL":
		case "NOK":
			$img = "images/nogo.png";
			$hrefo = "<a target='_parent' href='http://www.msftconnecttest.com/connecttest.txt' >";
			$hrefc = "</a>";
			break;;
		case "DNSERR":
			$img = "images/nogo.png";
			break;;
		default:
			$img = "images/globegrey.png";
			break;;
	}
	echo "<tr><td>{$hrefo}<img height='125px' src='{$img}' alt='Internet: {$state['internet']['captive']} Latency: {$state['internet']['ping']}'>{$hrefc}</td>\n";
	echo "<td>";
	if(isset($state['if'][$defif])) {
		echo "WAN {$defif} </br>";
		if(!empty(if_prefix($state['if'], $defif))) {
			echo "IP ". implode('<br />IP ', if_prefix($state['if'], $defif)) ."</br>\n";
		}
		$defgw = fetch_default_route_gw();
		//print_r($defgw);
		if(!empty($defgw[4])) {
			echo "Gateway4 {$defgw[4][0]['gateway']}</br>\n";
		}
		if(!empty($defgw[6])) {
			echo "Gateway6 {$defgw[6][0]['gateway']}</br>\n";
		}
		if(!empty($state['internet']['isp'])) {
			echo "Wan IP {$state['internet']['wanip']}</br>\n";
			echo "AS Number {$state['internet']['isp']['asnum']}</br>\n";
			echo "Descr {$state['internet']['isp']['descr']}</br>\n";
		}
	}
	echo "</td>";
	echo "</tr>\n";

	// DNS
	switch($state['internet']['dns']) {
		case "OK":
			$img = "images/dnsgreen.png";
			break;;
		case "NOK":
			$img = "images/dnsred.png";
			break;;
		default:
			$img = "images/dnsgrey.png";
			break;;
	}
	echo "<tr><td><img height='125px' src='{$img}' alt='DNS: {$state['internet']['captive']}'></td>";
	html_list_dns($state);
	echo "</tr>\n";
	
	
	if($defif != "") {
		// check for wireless stats
		if(isset($state['if'][$defif]['wi'])) {
			
			$bgcolor = scale_to_color(round(($state['if'][$defif]['wi']['quality'] + $state['if'][$defif]['wi']['level']) / 2));
				
			$img = "images/wifi{$bgcolor}.png";
			echo "<tr><td>";
			echo html_wi_link_bar($state['if'][$defif], 140);
			echo "<img height='125px' src='{$img}' alt='WAN: {$state['if'][$defif]['wi']['quality']}'></td>\n";
			echo "<td>";
			echo html_list_wi_link($state, $defif);
			echo html_list_lldp($state, $defif);
			echo "</td>";
			echo "</tr>\n";

		} else {
			// must be wired
			$bgcolor = "green"; // place holder for now without indicators
			$img = "images/ether{$bgcolor}.png";
			echo "<tr><td><img height='125px' src='{$img}' alt='WAN ethernet'></td>";
			echo "<td>";
			html_list_lldp($state, $defif);
			echo "</td>";
			echo "</tr>\n";
		}
	}
	
	echo "</table>";
	echo "</div>\n";
	
/* 
	// AP mode is kind of a waste of screen real estate.
	switch($state['if']['wlan0']['wi']['mode']) {
		case "Master":
			$img = "images/apgreen.png";
			break;;
		default:
			$img = "images/apgrey.png";
			break;;		
	}
	// echo "<tr><td><img height='125px' src='{$img}' alt='AP: {$state['if']['wlan0']['wi']['type']}'></td></tr>\n";
*/


}

function list_wi_link($state, $defif) {
	if(isset($state['if'][$defif]['wi'])) {
		foreach($state['if'][$defif]['wi'] as $field => $value) {
			switch($field) {
				case "mode":
					continue 2;
				case "quality":
					echo ucwords($field) ." ". $value ." ";
					break;
				default:
					echo ucwords($field) ." ". $value ."</br>";
			}
		}
	}
}

function html_clients($state){
	echo " <div id='clients'>";
	echo "<table border=0><tr><td>Client</td><td>Address</td><td>Time</td></tr>\n";
	if(is_array($state['clients']))
	foreach ($state['clients'] as $entry => $val) {
		echo "<tr><td>{$val['hostname']}</td><td>{$val['ip4']}<br/>{$val['mac']}</td><td>". date("Y-m-d H:i:s", $val['time']) ."</td></tr>\n";
	}
	echo "</table>";
	echo "</div>\n";		
		
}

function html_processes($state){
	echo " <div id='processes'>";
	echo "<table border=0><tr><td>Process name</td><td>Number</td></tr>\n";
	foreach ($state['proc'] as $procname => $number) {
		echo "<tr><td>{$procname}</td><td>{$number}</td></tr>\n";
	}
	echo "</table>";	
	echo "</div>\n";		
}


function html_processing($state){
	echo " <div id='processing'>";
	$diff = time() - $state['self']['time']; 
	if($diff < 0)
		$bgcolor = "mediumpurple";
	elseif($diff < 11)
		$bgcolor = "forestgreen";
	elseif($diff < 21)
		$bgcolor = "darkorange";	
	else
		$bgcolor = "crimson";
		
	echo "<table border=0><tr><td width='130px' bgcolor='{$bgcolor}'>&nbsp;{$diff}</td></tr>\n";
	echo "</table>";	
	echo "</div>\n";		
}

function html_openvpn($state){
	echo "";
		
}

function html_traffic_speed($iflist, $ifname) {
	// Auto scale?
	return "rx ". thousandsCurrencyFormat($iflist[$ifname]['traffic']['rx']) ."Bps, tx ". thousandsCurrencyFormat($iflist[$ifname]['traffic']['tx']) ."Bps";	
}
function html_traffic_total($iflist, $ifname) {
	// Auto scale?
	return "rx ". thousandsCurrencyFormat($iflist[$ifname]['traffic']['totalrx']) ."B, tx ". thousandsCurrencyFormat($iflist[$ifname]['traffic']['totaltx']) ."B";	
}

function send_json($state){
	header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
	header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
	header("Content-Type: application/json");
	echo json_encode($state, JSON_PRETTY_PRINT);
	
}

/* Below is Copyright RafaSashi on StackOverflow
https://stackoverflow.com/questions/4116499/php-count-round-thousand-to-a-k-style-count-like-facebook-share-twitter-bu
*/
function thousandsCurrencyFormat($num) {

  if($num>1024) {

        $x = round($num);
        $x_number_format = number_format($x);
        $x_array = explode(',', $x_number_format);
        $x_parts = array('K', 'M', 'G', 'T');
        $x_count_parts = count($x_array) - 1;
        $x_display = $x;
        $x_display = $x_array[0] . ((int) $x_array[1][0] !== 0 ? '.' . $x_array[1][0] : '');
        $x_display .= $x_parts[$x_count_parts - 1];

        return $x_display;

  }

  return $num;
}
