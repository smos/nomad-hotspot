<?php


function html_header(){
	header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
	header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
	header("Content-Type: text/html");
}
	
function html_head(){
	echo "<html><head><title>Nomad Hotspot</title></head><body bgcolor='black'>\n";
}

function html_menu(){
	// Menu Header
	echo "<table><tr>";
	echo "<td><a href='/status'>Status</a></td>";
	echo "<td><a href='/cfgif'>Interfaces</a></td>";
	echo "<td><a href='/cfgwiap'>Wifi AP</a></td>";
	echo "<td><a href='/cfgwiclient'>Wifi Client</a></td>";
	echo "<td><a href='/cfgovpn'>OpenVPN</a></td>";
	echo "<td><a href='/json'>JSON</a></td>";
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
			break;
		case "/cfgwiap":
			config_hostapd($state);
			break;
		case "/cfgwiclient":
			config_supplicant($state);
			break;
		case "/cfgovpn":
			config_openvpn($state);
			break;
		
	}
	html_form_close();		
}

function config_openvpn($state) {
	echo "<br>Config OpenVPN<br>";

	if(!empty($_POST)) {
		//print_r($_POST);
		$i = 0;
		foreach($_POST as $varname => $setting) {
			switch($varname) {
				case "login":
					if(!empty($_POST[$varname])) {
						echo "Found new Login data <br>";
						$credentials = explode("\n", $_POST[$varname]);
						if(count ($credentials) < 2) {
							echo "Not enough data, put username and password on seperate lines <br>";
							$loginerror = true;
						} else {
							config_write_ovpn_login($_POST);
						}						
					}
					break;
				case "conf":
					if(!empty($_POST[$varname])) {
						echo "Found new conf data <br>";
							config_write_ovpn($_POST);
					}
					break;
			}
		}
		restart_service("client.ovpn");
	}

	$settings = config_read_ovpn($state);

	echo "OpenVPN client username and password on seperate lines. Existing not shown.<br>";
	html_textarea("login", $settings['login'], 3, 20);
	echo "<br>";
	echo "OpenVPN client configuration below<br>";
	html_textarea("conf", $settings['conf'], 120, 80);
	echo "<br>";


	//echo "<pre>". print_r($settings, true);

}

function config_dhcpcd($state) {
	echo "<br>Config interfaces dhcpcd<br>";
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
	//echo print_r($settings, true);
	// Will want to write dnsmasq.conf too, for LAN address(es) and dhcp range(s).
}
function config_hostapd($state) {
	echo "<br>Config interfaces hostapd: <br>";
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
	//echo print_r($settings, true);
}

function validate_select($array, $item){
	if(in_array($item, $array))
		return true;
	else
		return false;
}

function config_supplicant($state) {
	echo "<br>Config wireless client networks<br>";
	// Process POST request
	$settings = config_read_supplicant($state);
	// Empty item at the end for adding new entry
	//$settings['network'][] = array("ssid" => "", "psk" => "", "key_mgmt" => "NONE", "priority" => "-1");
	//echo "<pre>". print_r($settings, true);
	$countries = array("NL" => "NL", "US" => "US", "JP" => "JP");
	$key_mgmt = array("WPA-PSK" => "WPA-PSK", "NONE" => "NONE");
	$priorities = array("-1" => "-1", "0" => "0", "1" => "1", "2" => "2", "3" => "3");
	// Compare 
		echo "<pre>" . print_r($settings, true) ."</pre>";
	if(!empty($_POST)) {
		print_r($_POST);
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
						foreach(array("ssid", "psk", "priority", "key_mgmt") as $name) {
							$var = "{$index}{$name}";
								switch($name) {
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
		// Check for new entry
		$index = count($settings['network'])+1;
		$var = "{$i}ssid";
		//echo "$_POST[$var]";
		if(isset($_POST[$var]) && ($_POST[$var] != "")) {
			foreach(array("ssid", "psk", "priority", "key_mgmt") as $name) {
				$var = "{$index}{$name}";
					switch($name) {
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
		// echo "<pre>".  print_r($settings, true);
		config_write_supplicant($settings);
		$settings = config_read_supplicant($state);
	}
	// Empty item at the end for adding new entry
	// $settings['network'][] = array("ssid" => "", "psk" => "", "key_mgmt" => "NONE", "priority" => "-1");
	
	foreach($settings as $varname => $setting) {
		switch($varname) {
			case "country":
				echo "Country setting for Wireless adapter: ";
				html_select($varname, $countries, $setting);			
				echo "<br>\n";
				break;
			case "network":
				echo "Client networks to connect to:\n";
				foreach($setting as $index => $values){
					echo "Index {$index} <br>";
					echo "SSID: ";
					html_input("{$index}ssid", $values['ssid']) ."<br>";
					echo "Pre Shared Key: ";
					html_input("{$index}psk", $values['psk']) ."<br>";
					echo "Type: ";
					html_select("{$index}key_mgmt", $key_mgmt, $values['key_mgmt']) ."<br>";
					echo "Priority: ";
					html_select("{$index}priority", $priorities, $values['priority']) ."<br>";
					echo "<br>";
				}			
		}		
	}
	//echo "<pre>". print_r($settings, true);
	foreach ($state['if'] as $ifname => $iface) {
		// Skip AP interface
		if($ifname == "wlan0")
			continue;

		if(empty($state['if'][$ifname]['wi']))
			continue;
		
		echo "<strong>Wireless network list {$ifname}</strong><br>";
		echo "<table border=1><tr><td>bssid</td><td>name</td><td>Frequency</td><td>Encryption</td><td>Quality</td></tr>\n";
		//print_r($iface['wi']);
		if(is_array($iface['wi']))
			$wi_list = list_iw_networks($state, $ifname);
		
		if(is_array($wi_list)) {
			foreach($wi_list as $entry => $fields) {
				echo "<tr>";
				foreach($fields as $field)
					echo "<td>{$field}</td>";
				echo "</tr>";
			}
		}
		echo "</table>";	
	}
	

}

function html_hidden($varname, $value){
	echo "<input type=hidden name='{$varname}' value='{$value}' >";
}

function html_textarea($varname, $value, $rows, $cols){
echo "<textarea name='{$varname}' rows='{$rows}' cols='{$cols}'>{$value}</textarea>";
}

// Generate a drop down
function html_select($varname, $options, $selected) {
	echo "<select name='{$varname}' >";
	foreach($options as $option => $name) {
		$sel = '';
		if($option == $selected)
			$sel = "selected";
		echo "<option name='{$option}' {$sel} >{$name}</option>";
	}
	echo "</select>";
}
// Generate a input box
function html_input($varname, $existing) {
	echo "<input type=text name='{$varname}' value='{$existing}' >";
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
var pageRefresh = 5000; //5 s
    setInterval(function() {
        refresh();
    }, pageRefresh);
});

	// Functions

function refresh() {
    \$('#interfaces').load(\"/interfaces\");
    \$('#connectivity').load(\"/connectivity\");
    \$('#clients').load(\"/clients\");
    \$('#processes').load(\"/processes\");
}

";
	echo "</script>";

}

function html_jquery_reload_screensaver(){
	echo "<script type='text/javascript'>\n";
	echo "

\$(document).ready(function() {
var pageRefresh = 5000; //5 s
    setInterval(function() {
        refresh();
    }, pageRefresh);
});

	// Functions

function refresh() {
    \$('#connectivityscreensaver').load(\"/connectivityscreensaver\");
}

";
	echo "</script>";

}

function html_status($state){
	//echo " <div id='interfaces'></div>\n";
	echo html_interfaces($state);
	//echo " <div id='connectivity'></div>\n";
	echo html_connectivity($state);
	//echo " <div id='clients'></div>\n";
	echo html_clients($state);
	//echo " <div id='processes'></div>\n";
	echo html_processes($state);
}

function html_status_screensaver($state){
	echo html_connectivity_screensaver($state);
}

function html_interfaces($state){
	echo " <div id='interfaces'>";
	echo "<table border=1><tr><td>Interface</td><td>State</td><td>Adresses</td><td>Traffic</td><td>Totals</td><td>Info</td></tr>\n";
	foreach ($state['if'] as $ifname => $iface) {	
		$wireless = "&nbsp;";
		//print_r($iface['wi']);
		if(is_array($iface['wi']))
			$wireless = "SSID: '{$iface['wi']['ssid']}', Mode: {$iface['wi']['type']}";
		echo "<tr><td>{$ifname}</td><td>". if_state($state['if'], $ifname)."</td><td>". implode('<br />', if_prefix($state['if'], $ifname)) ."</td><td>". round(html_traffic_speed($state['if'], $ifname)) ."</td><td>". round(html_traffic_total($state['if'], $ifname)) ."</td><td>{$wireless}</td></tr>\n";
	}
	echo "</table>";	
	echo "</div>\n";		
}

function html_connectivity($state){
	echo " <div id='connectivity'>";
	echo "<table border=1><tr><td>Connectivity</td><td>Result</td></tr>\n";
	foreach ($state['internet'] as $check => $result) {	
		if($check == "url")
	$result = "<a _target=_blank href='{$result}'>{$result}</a>";
		echo "<tr><td>{$check}</td><td>{$result}</td></tr>\n";
	}
	echo "</table>";
	echo "</div>\n";		
		
}
function html_connectivity_screensaver($state){
	echo " <div id='connectivityscreensaver'>";
	echo "<center>";
	echo "<table>";
	// VPN
	$img = "images/vpngrey.png";
	$vpncon = "Not configured";
	if(isset($state['if']['tun0'])) {
		if(!empty($state['if']['tun0']['addr_info'])) {
			$img = "images/vpngreen.png";
			$vpncon = "Connected with address: {$state['if']['tun0']['addr_info']}";
		} else {
			$img = "images/vpnred.png";
			$vpncon = "Not connected";
		}
	}
	echo "<tr><td><img height='120px' src='{$img}' alt='VPN: {$vpncon}'></td></tr>\n";
	// Internet, ping color
	$color = "white";
	if($state['internet']['ping'] < 500)
		$color = "red";
	if($state['internet']['ping'] < 200)
		$color = "orange";
	if($state['internet']['ping'] < 100)
		$color = "green";

	switch($state['internet']['captive']) {
		case "OK":
			$img = "images/globe{$color}.png";
			break;;
		case "NOK":
			$img = "images/nogo.png";
			break;;
		default:
			$img = "images/globegrey.png";
			break;;		
	}
	echo "<tr><td><img height='120px' src='{$img}' alt='Internet: {$state['internet']['captive']} Latency: {$state['internet']['ping']}'></td></tr>\n";
	// DNS
	switch($state['internet']['captive']) {
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
	echo "<tr><td><img height='120px' src='{$img}' alt='DNS: {$state['internet']['captive']}'></td></tr>\n";
	// AP
	switch($state['if']['wlan0']['wi']['type']) {
		case "AP":
			$img = "images/apgreen.png";
			break;;
		default:
			$img = "images/apgrey.png";
			break;;		
	}
	echo "<tr><td><img height='120px' src='{$img}' alt='AP: {$state['if']['wlan0']['wi']['type']}'></td></tr>\n";

	echo "</table>";
	echo "</div>\n";		
		
}
function html_clients($state){
	echo " <div id='clients'>";
	echo "<table border=1><tr><td>Client</td><td>Address</td><td>Mac</td><td>Time</td></tr>\n";
	if(is_array($state['clients']))
	foreach ($state['leases'] as $entry => $val) {
		echo "<tr><td>{$val['hostname']}</td><td>{$val['ip4']}</td><td>{$val['mac']}</td><td>". date("Y-m-d H:i:s", $val['time']) ."</td></tr>\n";
	}
	echo "</table>";
	echo "</div>\n";		
		
}
function html_processes($state){
	echo " <div id='processes'>";
	echo "<table border=1><tr><td>Process name</td><td>Number</td></tr>\n";
	foreach ($state['proc'] as $procname => $number) {
		echo "<tr><td>{$procname}</td><td>{$number}</td></tr>\n";
	}
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
