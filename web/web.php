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
	echo "<table class='menu-row' ><tr>";
	echo "<td><a href='/status'><img height='50px' src='images/status.png' alt='Status'></a></td>";
	echo "<td><a href='/cfgif'><img height='50px' src='images/interfaces.png' alt='Interfaces'></a></td>";
	echo "<td><a href='/cfgwiap'><img height='50px' src='images/apunkc.png' alt='Wifi AP'></a></td>";
	echo "<td><a href='/cfgwiclient'><img height='50px' src='images/wifi.png' alt='Wifi Client'></a></td>";
	echo "<td><a href='/cfgovpn'><img height='50px' src='images/vpnunkc.png' alt='OpenVPN'></a></td>";
	echo "<td><a href='/logs'><img height='50px' src='images/json.png' alt='Logs'></a></td>";
	echo "<tr></table>\n";
}

function html_foot(){
	echo "</body></html>\n";
}

function html_button($value = "Save") {
	echo "<input type='submit' value='{$value}' class='button'>";
}

function html_form_open() {
	echo "<form method='post' action='{$_SERVER["REQUEST_URI"]}'>";	
}

function html_form_close() {
	echo "</form>";
}

function wopr_json($state) {
	header("content-type: application/json");
	// we have 25 rows of 16 bits to display on the screen
	$rows = array();
// echo print_r($state, true);
	$rows[] = substr(sprintf("0%016b", decbin($state['self']['itteration'])), -16);
	$rows[] = substr(decbin($state['self']['time']), -16);
	
	foreach($state['captive'] as $time => $status) {
		$msb = "0";
		if($status != "OK")
			$msb = "1";
		
		$bits = substr(decbin($time), -15);
		$rows[] = $msb . $bits;
		//echo print_r($bits, true);
	}
	
	foreach($state['internet']['latency']['ping'] as $addr => $time)
			$rows[] = sprintf("%016b", decbin($time));
	
	foreach($state['internet']['latency']['dnsping'] as $addr => $time)
			$rows[] = sprintf("%016b", decbin($time));
			
	$rows[] = substr(sprintf("%032b",inet_pton($state['internet']['wanip'])), 16, 16);
	$rows[] = substr(sprintf("%032b",inet_pton($state['internet']['wanip'])), -16);
	
	//foreach($state['clients'] as $client)
	//		$rows[] = substr(decbin($client['time']), -16);
	
	// toprx and toptx are the max values, grab 1st item from hist, rx and hist, tx arrays
	// calculate percentage, create string of 0 and 1 s
	
	foreach($state['traffic'] as $ifname => $if) {
		$defif = find_wan_interface($state);
		if($ifname != $defif)
			continue;
		//echo print_r($if, true);
		for($i =0; $i < 10; $i++) {
		
			$ra = array();
			// init empty array
			for($r = 0; $r < 16; $r++)
				$ra[$r] = "0";

			//$crx = str_repeat("1", (round($if['hist']['rx'][$i] / ($if['toprx']/ 1)) * 16));
			// inflate numbers somewhat
			$crx = round(($if['hist']['rx'][$i] / ($if['toprx']/1)) * 16);
			$ctx = round(($if['hist']['tx'][10 - $i] / ($if['toptx']/1)) * 16);
			
			for($r = 0;$r < $crx; $r++) {
				$ra[$r] = "1";
				}
			
			for($r = 0;$r < $ctx; $r++) {
				$ra[15 - $r] = "1";
				}
			
			//$crx = str_repeat("1", (ceil($crx)));
			//$ctx = str_repeat("1", (ceil($ctx)));
			//$rows[] = substr(str_pad($crx, 16, "0", STR_PAD_LEFT), -16);
			//$rows[] = substr(str_pad($ctx, 16, "0", STR_PAD_RIGHT), 0, 16);
					//echo print_r($ra, true);
			$rows[] = implode("", $ra);
			//echo "{$if['hist']['rx'][$i]} / {$if['toprx']} $crx\n";
			//echo "{$if['hist']['tx'][$i]} / {$if['toptx']} $ctx\n";
		}
	}
	

	while(count($rows) < 25)
		$rows[] = sprintf("%016b", 0);

	return json_encode(array("rows" => $rows), JSON_PRETTY_PRINT);
}

function html_wopr() {
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WOPR Binary Display</title>
    <style>
        body {
            background-color: #000;
            color: #fff;
            font-family: 'Inter', monospace;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            overflow: hidden;
        }

        .wopr-label {
            font-size: 32px;
            text-align: center;
            margin-bottom: 20px;
            color: lightgreen;
            text-shadow: 0 0 10px lightgreen;
        }

        .wopr-container {
            width: 480px; /* 16 columns * 30px */
            height: 750px; /* 25 rows * 30px */
            border: 2px solid #333;
            box-shadow: 0 0 20px rgba(0, 255, 0, 0.5);
            display: grid;
            grid-template-columns: repeat(16, 30px);
            grid-template-rows: repeat(25, 30px);
            background-color: #111;
        }

        .grid-square {
            width: 30px;
            height: 30px;
            background-color: grey;
            border: 1px solid rgba(0, 0, 0, 0.1);
            transition: background-color 0.2s ease;
        }

        .grid-square.lit {
            background-color: lightyellow;
            box-shadow: 0 0 5px lightyellow;
        }
    </style>
</head>
<body>

    <div class="wopr-label">WOPR</div>
    <div id="wopr-display" class="wopr-container">
        <!-- Grid squares will be generated here by JavaScript -->
    </div>

    <script>
        const displayContainer = document.getElementById('wopr-display');
        const squares = [];
        const numCols = 16;
        const numRows = 25;
        const totalBits = numCols * numRows;

        // Function to create the initial grid of squares
        function createGrid() {
            for (let i = 0; i < totalBits; i++) {
                const square = document.createElement('div');
                square.className = 'grid-square';
                displayContainer.appendChild(square);
                squares.push(square);
            }
        }

        // Function to clear all squares
        function clearDisplay() {
            squares.forEach(square => {
                square.classList.remove('lit');
            });
        }

        // Function to update the display
        async function updateDisplay() {
            clearDisplay();
            let binaryRows = [];

            try {
                // Attempt to fetch data from the external URL
                const response = await fetch('/woprjson');
                const data = await response.json();
                
                if (data && Array.isArray(data.rows) && data.rows.length === numRows) {
                    binaryRows = data.rows;
                    console.log('Successfully fetched and processed data.');
                } else {
                    throw new Error('Invalid data structure from JSON endpoint.');
                }

            } catch (error) {
                console.error('Failed to fetch from /woprjson. Using fallback data.', error);
                
                // Fallback to a random pattern if fetching fails
                for (let i = 0; i < numRows; i++) {
                    let randomRow = '';
                    for (let j = 0; j < numCols; j++) {
                        randomRow += Math.random() > 0.5 ? '1' : '0';
                    }
                    binaryRows.push(randomRow);
                }
            }
            
            // Draw the pattern based on the binary rows
            for (let row = 0; row < numRows; row++) {
                const binaryString = binaryRows[row];
                for (let col = 0; col < numCols; col++) {
                    const index = row * numCols + col;
                    if (binaryString[col] === '1') {
                        if (squares[index]) {
                            squares[index].classList.add('lit');
                        }
                    }
                }
            }
        }

        // Create the grid and start the update loop
        createGrid();
        updateDisplay();
        setInterval(updateDisplay, 2000);
    </script>

</body>
</html>




<?php

}

function html_wi_channel_use() {
?>
    <style>

        .container {
            max-width: 800px;
            margin: auto;

            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        h1 {
            color: #0056b3;
            text-align: center;
        }
        ul {
            list-style-type: none;
            padding: 0;
        }
        .channel-group-header {
            font-size: 1.5em;
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 10px;
            color: white;
            cursor: pointer;
            text-align: left;
        }
        .channel-group-content {
            display: none;
        }
        .channel-group-content.expanded {
            display: block;
        }
        .channel-item {
            margin-bottom: 5px;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
            #background: #fff;
        }
        .channel-header {
            display: flex;
            align-items: center;
            padding: 10px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .channel-header:hover {
            background-color: #f0f0f0;
        }
        .bar-container {
            flex-grow: 1;
            height: 25px;
            margin-left: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;

            overflow: hidden;
            display: flex;
        }
        .color-bar {
            height: 100%;
            transition: width 0.5s ease, background-color 0.5s ease;
        }
        .channel-count {
            width: 30px;
            text-align: center;
            font-weight: bold;
        }
        .ssid-list {
            list-style-type: none;
            padding: 0 10px 10px 10px;
            margin: 0;
            display: none;
        }
        .ssid-list.expanded {
            display: block;
        }
        .ssid-list li {
            background: #e9ecef;
            padding: 8px;
            margin-top: 5px;
            border-radius: 4px;
        }
    </style>

    <div class="container">
        <h2>Channel Occupancy</h2>
        <div id="channel-list-container"></div>
        <p id="loading-message">Loading data...</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const channelListContainer = document.getElementById('channel-list-container');
            const loadingMessage = document.getElementById('loading-message');

            fetch('/wilistjson')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    loadingMessage.style.display = 'none';

                    const allChannels = {
                        '2.4 GHz': ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14'],
                        '5 GHz': ['36', '40', '44', '48', '52', '56', '60', '64', '100', '104', '108', '112', '116', '120', '124', '128', '132', '136', '140', '144', '149', '153', '157', '161', '165'],
                        '6 GHz': ['1', '5', '9', '13', '17', '21', '25', '29', '33', '37', '41', '45', '49', '53', '57', '61', '65', '69', '73', '77', '81', '85', '89', '93', '97', '101', '105', '109', '113', '117', '121', '125', '129', '133', '137', '141', '145', '149', '153', '157', '161', '165', '169', '173', '177', '181', '185', '189', '193', '197', '201', '205', '209', '213', '217', '221', '225', '229', '233']
                    };

                    const channelData = {};

                    for (const band in allChannels) {
                        channelData[band] = {};
                        allChannels[band].forEach(channel => {
                            channelData[band][channel] = {
                                count: 0,
                                ssids: []
                            };
                        });
                    }

                    for (const macAddress in data) {
                        const network = data[macAddress];
                        const channel = network.channel;
                        const ssid = network.ssid;
                        // Use a more accurate check for frequency
                        const frequency = parseInt(network.frequency.split(' ')[0]);

                        let band = '';
                        if (frequency >= 2400 && frequency < 2500) {
                            band = '2.4 GHz';
                        } else if (frequency >= 5000 && frequency < 5900) {
                            band = '5 GHz';
                        } else if (frequency >= 5900 && frequency < 7200) {
                            band = '6 GHz';
                        }
                        
                        if (band && channelData[band][channel]) {
                            channelData[band][channel].count++;
                            channelData[band][channel].ssids.push(ssid);
                        }
                    }

                    for (const band in channelData) {
                        // Create collapsible header
                        const groupHeader = document.createElement('h2');
                        groupHeader.className = 'channel-group-header';
                        groupHeader.textContent = band;
                        channelListContainer.appendChild(groupHeader);

                        // Create collapsible content container
                        const groupContent = document.createElement('div');
                        groupContent.className = 'channel-group-content';
                        channelListContainer.appendChild(groupContent);
                        
                        const ul = document.createElement('ul');
                        
                        const sortedChannels = Object.keys(channelData[band]).sort((a, b) => a - b);
                        
                        sortedChannels.forEach(channel => {
                            const { count, ssids } = channelData[band][channel];

                            const channelItem = document.createElement('li');
                            channelItem.className = 'channel-item';

                            const clampedCount = Math.min(count, 10);
                            const percentage = (clampedCount / 10) * 100;
                            
                            let barColor;
                            if (clampedCount === 0) {
                                barColor = '#adb5bd';
                            } else if (clampedCount <= 3) {
                                barColor = '#28a745';
                            } else if (clampedCount <= 7) {
                                barColor = '#ffc107';
                            } else {
                                barColor = '#dc3545';
                            }

                            const headerHtml = `
                                <div class="channel-header">
                                    <div class="channel-count">${count}</div>
                                    <span>Channel ${channel}</span>
                                    <div class="bar-container">
                                        <div class="color-bar" style="width: ${percentage}%; background-color: ${barColor};"></div>
                                    </div>
                                </div>
                            `;

                            const ssidList = document.createElement('ul');
                            ssidList.className = 'ssid-list';
                            if (ssids.length > 0) {
                                ssids.forEach(ssid => {
                                    const ssidItem = document.createElement('li');
                                    ssidItem.textContent = ssid;
                                    ssidList.appendChild(ssidItem);
                                });
                            } else {
                                const emptyItem = document.createElement('li');
                                emptyItem.textContent = 'No networks found';
                                ssidList.appendChild(emptyItem);
                            }

                            channelItem.innerHTML = headerHtml;
                            channelItem.appendChild(ssidList);
                            ul.appendChild(channelItem);
                            
                            channelItem.querySelector('.channel-header').addEventListener('click', () => {
                                ssidList.classList.toggle('expanded');
                            });
                        });
                        
                        groupContent.appendChild(ul);

                        // Add click event listener to the band header
                        groupHeader.addEventListener('click', () => {
                            groupContent.classList.toggle('expanded');
                        });
                    }
                })
                .catch(error => {
                    console.error('Error fetching data:', error);
                    loadingMessage.textContent = 'Failed to load data. Please try again later.';
                    loadingMessage.style.color = 'red';
                });
        });
    </script>
<?php

}

function html_config($state, $uri){
	global $cfgmap;
	// html_form_open();
	// html_button();
	switch($uri) {
		case "/cfgif":
			//config_dhcpcd($state);
			config_nmconnection($state, "etherclient.nmconnection");
			echo html_jquery_reload_cfgif();
			echo html_interfaces($state);
			break;
		case "/cfgwiap":
			config_nmconnection($state, "hotspot.nmconnection");
			echo html_interfaces($state, fetch_ap_if($state));
			echo html_jquery_reload_cfgap();
			// config_hostapd($state);
			break;
		case "/cfgwiclient":
			foreach(list_wifi_cons() as $con) {
				// echo "config $con";
				config_nmconnection($state, $con);
			}
			if($_POST['con'] == 'new-connection')
					config_nmconnection($state, 'new-connection');
			echo html_interfaces($state, fetch_wi_client_if($state));
			echo html_jquery_reload_cfgwi();
			// config_supplicant($state);
			echo html_jquery_reload_wilist();
			echo html_wi_channel_use();
			break;
		case "/cfgovpn":
			html_form_open();
			html_button();
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


function parse_nm_config($filepath) {
    $config = [];
    $current_section = null;
	global $basedir;
	global $cfgdir;
	// $file = "{$basedir}/{$cfgdir}/hotspot.nmconnection";

	$file = "{$basedir}/{$cfgdir}/$filepath";
	if(!file_exists($file)) {
		// echo "file $file not found\n";
		// empty array

		return false;
	}

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        // Skip comments
        if (empty($line) || $line[0] === '#') {
            continue;
        }

        // Section header
        if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
            $current_section = $matches[1];
            $config[$current_section] = [];
        }
        // Key-value pair
        elseif ($current_section && strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $config[$current_section][trim($key)] = trim($value);
        }
    }

    return $config;
}


function build_nm_config($con, $post, $old = array()) {
    $config = [];
	// Use previous connection settings for uuid, etc
	if(!empty($old)) {
		$config = $old;
	} else {
		foreach($post as $field => $value) {
			$field = preg_replace("/^new-connection_/", "", $field);
			$data[$field] = $value;
		}
		$config['connection']['id'] = "$con";
		$config['connection']['uuid'] = guidv4();
		$config['connection']['autoconnect'] = "true";
		$config['connection']['type'] = "wifi";
		$config['connection']['interface-name'] = "wlan1";
		$config['ipv4']['method'] = 'auto';
		$config['ipv6']['method'] = 'auto';
		$data['ipv4_method'] = 'auto';
		$data['ipv6_method'] = 'auto';
		$config['wifi']['mode'] = 'infrastructure';
	}

	// process variables and strip their con_ prefix.
	foreach($post as $field => $value) {
		$field = preg_replace("/^{$con}_/", "", $field);
		$data[$field] = $value;
	}
	//echo "<pre>". print_r($data, true) ."</pre>\n";
	
	// connection
	if (!empty	($data['autoconnect'])) {
		$config['connection']['autoconnect'] = $data['autoconnect'] ?? "true";
	}
	
	// WiFi section
	if (!empty($data['wifi_ssid'])) {
		$config['wifi']['ssid'] = $data['wifi_ssid'] ?? "";
	}
	// WiFi band
	if (!empty($data['wifi_band'])) {
		$config['wifi']['band'] = $data['wifi_band'] ?? "";
	}
	// WiFi channel section
	if (!empty($data['wifi_channel'])) {
		if($data['wifi_channel'] == "auto") {
			unset($config['wifi']['channel']);
			unset($config['wifi']['band']);
		} else {
			$chan = round(floatval($data['wifi_channel']));
			if(($chan > 0) && ($chan < 15)) {
				$config['wifi']['channel'] = $chan ?? "1";
				$config['wifi']['band'] = $band ?? "bg"; // these numbers are also valid for 6ghz, and fall under "a" fixme
			}
			if(($chan > 35) && ($chan < 140)) {
				$config['wifi']['channel'] = $chan ?? "36";
				$config['wifi']['band'] = $band ?? "a";
			}
		}
	}

	// WiFi-security section
	if (!empty($data['wifi_psk'])) {
		$config['wifi-security'] = [
			'group' => 'ccmp;',
			'key-mgmt' => $data['wifi_key_mgmt'] ?? 'wpa-psk',
			'pairwise' => 'ccmp;',
			'proto' => 'rsn;',
			'psk' => $data['wifi_psk'] ?? ''
		];
	} else {
		// No psk, no settings
		// $config['wifi-security'] = [
		//	'key-mgmt' => 'none',
		//	'auth-alg' => 'open'
		//];
		unset($config['wifi-security']);
	}

    // IPv4 section
    $config['ipv4'] = ['method' => $data['ipv4_method'] ?? 'auto'];

	if ($data['ipv4_method'] == 'manual') {
		if (!empty($data['ipv4_address'])) {
			$config['ipv4']['address1'] = $data['ipv4_address'];
		}
		if (!empty($data['ipv4_gateway'])) {
			$config['ipv4']['gateway'] = $data['ipv4_gateway'];
		}
	}

    // IPv6 section
    $config['ipv6'] = [
        'addr-gen-mode' => 'default',
        'method' => $data['ipv6_method'] ?? 'auto'
    ];

	if ($data['ipv6_method'] == 'manual') {
		if (!empty($data['ipv6_address'])) {
			$config['ipv6']['address1'] = $data['ipv6_address'];
		}
		if (!empty($data['ipv6_gateway'])) {
			$config['ipv6']['gateway'] = $data['ipv6_gateway'];
		}
	} else {
		$config['ipv6']['method'] == 'ignore';
	}

    return $config;
}

function write_nm_file($config, $filename) {
    $lines = [];
    foreach ($config as $section => $values) {
        $lines[] = "[$section]";
        foreach ($values as $key => $value) {
            $lines[] = "$key=$value";
        }
        $lines[] = ""; // blank line between sections
    }
    file_put_contents($filename, implode(PHP_EOL, $lines));
}


function config_nmconnection($state, $con = "") {
	global $cfgdir;
	global $basedir;

	// validate we have a valid config file
	$config = array();
	switch($con) {
		case "hotspot.nmconnection":
		case "etherclient.nmconnection":
			// do something
			$file = $con;
			if(is_readable("{$basedir}/{$cfgdir}/{$con}")) {
				preg_match("/(.*?).nmconnection/i", $file, $matches);
				$con = $matches[1];
			} else {
				echo "file $file is not readable";
			}
			break;
		default:
			// test for other config files
			if(is_readable("{$basedir}/{$cfgdir}/{$con}")) {
				$file = $con;
				preg_match("/(.*?)-wifi.nmconnection/i", $file, $matches);
				$con = $matches[1];
			} elseif($con == 'new-connection') {
				$search = array("\/", "\*", "\'", "\&", "\<", "\>", "\"");
				$replace = array();
				$cssid = str_ireplace($search, "", $_POST['new-connection_wifi_ssid']);
				$file = "{$cssid}-wifi.nmconnection";
				$con = $cssid;
				//echo "<pre>". print_r($_POST, true) ."</pre>\n";
				//echo "<pre>". print_r($file, true) ."</pre>\n";
				$config = build_nm_config($con, $_POST);
				if($cssid != "") {
					write_nm_file($config, "{$basedir}/{$cfgdir}/{$file}");
				}
			} else {
				echo "file $file is not readable";
			}
			// no idea what to do here
			break;
			break;
	}
		//echo "$file";
		
		$config = parse_nm_config($file);

		//echo getcwd();
		// echo print_r($_SERVER, true);
		if(!empty($_POST)) {
			if($_POST['con'] == $con) {
				$config = parse_nm_config($file);
				$config = build_nm_config($con, $_POST, $config);
				write_nm_file($config, "{$basedir}/{$cfgdir}/{$file}");

				//echo "<pre>". print_r($_POST, true) ."</pre>\n";
				echo "<h2>Configuration Saved </h2>";
				// echo "<p>File '{$file}' written for $con </code>";
				//echo "<p><pre>". print_r($config, true) ."</pre></p>\n";
			}
		}

		
	?>
	    <div class="collapsible-container">
        <h3 class="collapsible-header">Edit Network: <?= $con ?></h3>
        <div class="collapsible-content">
          <table class="status-item">
            <tbody>
              <tr>
                <td>
	
	  <form method="post" action="<?= $_SERVER['REQUEST_URI'] ?? '' ?>">
	  <input type='hidden' name='con' value='<?= $con ?>'>
	<?php 
	if ($config['wifi']['mode'] != "ap") {
	?>
	   <fieldset>
		  <legend>General</legend>
		  <label><input type="checkbox" name="<?= "{$con}_autoconnect" ?>" value="true" <?= $config['connection']['autoconnect'] === 'true' ? 'checked' : 'false' ?> ">Automatically connect</label><br>
		</fieldset>
		<?php 
		}
		?>
	<?php 
	if (!empty($config['wifi'])) {
	?>
	   <fieldset>
		  <legend>Band</legend>
		  <label>
		<?php 
		echo html_select("{$con}_wifi_band", array("" => "Automatic", "a" => "A (5Ghz)", "bg"=>"B/G (2.4GHz)", "e" => "E (6GHz)"), $config['wifi']['band']);
		?>
		  </label><br>
		</fieldset>
	   <fieldset>
		  <legend>Channel</legend>
		  <label>
		<?php 
		echo html_select("{$con}_wifi_channel", array("auto" => "Automatic", 1 => 1, 6 => 6, 11 => 11, 36 => 36, 40 => 40, 44 => 44, 48 => 48), $config['wifi']['channel']);
		?>
		  </label><br>
		</fieldset>
		<?php 
		}
		?>

		<fieldset>
		  <legend>IPv4 Settings</legend>
		  <label><input type="radio" name="<?= "{$con}_ipv4_method" ?>" value="auto" <?= $config['ipv4']['method'] === 'auto' ? 'checked' : '' ?> onclick="toggleFields('<?= "{$con}_ipv4" ?>')"> Automatic (DHCP)</label><br>
		  <label><input type="radio" name="<?= "{$con}_ipv4_method" ?>" value="manual" <?= $config['ipv4']['method'] === 'manual' ? 'checked' : '' ?> onclick="toggleFields('<?= "{$con}_ipv4" ?>')"> Manual</label>

			<div id="<?= "{$con}_ipv4_static_fields" ?>" style="display: <?= $config['ipv4']['method'] === 'manual' ? 'block' : 'none' ?>;">
			  <label>IPv4 Address:
				<input type="text" name="<?= "{$con}_ipv4_address" ?>" value="<?= $config['ipv4']['address1'] ?? '' ?>">
			  </label><br>
			  <label>IPv4 Gateway:
				<input type="text" name="<?= "{$con}_ipv4_gateway" ?>" value="<?= $config['ipv4']['gateway'] ?? '' ?>">
			  </label>
			</div>

		</fieldset>

		<fieldset>
		  <legend>IPv6 Settings</legend>
		  <label><input type="radio" name="<?= "{$con}_ipv6_method" ?>" value="auto" <?= $config['ipv6']['method'] === 'auto' ? 'checked' : '' ?> onclick="toggleFields('<?= "{$con}_ipv6" ?>')"> Automatic</label><br>
		  <label><input type="radio" name="<?= "{$con}_ipv6_method" ?>" value="ignore" <?= $config['ipv6']['method'] === 'ignore' ? 'checked' : '' ?> onclick="toggleFields('<?= "{$con}_ipv6" ?>')"> Ignore</label><br>
		  <label><input type="radio" name="<?= "{$con}_ipv6_method" ?>" value="manual" <?= $config['ipv6']['method'] === 'manual' ? 'checked' : '' ?> onclick="toggleFields('<?= "{$con}_ipv6" ?>')"> Manual</label>


			<div id="<?= "{$con}_ipv6_static_fields" ?>" style="display: <?= $config['ipv6']['method'] === 'manual' ? 'block' : 'none' ?>;">
			  <label>IPv6 Address:
				<input type="text" name="<?= "{$con}_ipv6_address" ?>" value="<?= $config['ipv6']['address1'] ?? '' ?>">
			  </label><br>
			  <label>IPv6 Gateway:
				<input type="text" name="<?= "{$con}_ipv6_gateway" ?>" value="<?= $config['ipv6']['gateway'] ?? '' ?>">
			  </label>
			</div>

		</fieldset>


	<?php if((!empty($config['wifi'])) ) { ?>
		
		<fieldset>
		  <legend>Wireless Network Settings</legend>

		  <label>SSID:
			<input type="text" name="<?= "{$con}_wifi_ssid" ?>" value="<?= $config['wifi']['ssid'] ?? '' ?>">
		  </label><br>

		  <label>Password (PSK):
			<input type="text" name="<?= "{$con}_wifi_psk" ?>" value="<?= $config['wifi-security']['psk'] ?? '' ?>">
		  </label><br>

		</fieldset>

	<?php } ?>

	<input type='submit' value='Save' class='button'>
	</form>
              </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

<?php
	// echo "</td></tr></table>\n";
	// echo print_r($config, true);
}

function config_dhcpcd($state) {
	global $cfgdir;

	echo "<br>Config interfaces dhcpcd<br>";
	$state['config'] = read_config($state['cfgfile']);
	// echo print_r($state['config']);
	// If no config exists, default to "wlan0"
	if(!isset($state['config']['ap_if'])) {
		$state['config']['ap_if'] = "wlan0";
		save_config($state['cfgfile'], $state['config']);
	}

	$wlan_ifs = fetch_wlan_interfaces();

	chdir('..');
	//echo getcwd();
	if(!empty($_POST)) {
		//print_r($_POST);
		$i = 0;
		// if saved AP interface differs from POST go into change
		// rename interface in hostapd config file to new interface
		if((in_array($_POST['ap_if'], $wlan_ifs)) && ($_POST['ap_if'] != $state['config']['ap_if'])) {
			// preg replace old interface with new interface in dhcpcd, dnsmasq iptables 4 and 6, hostapd.conf
			$mut_files = array("dhcpcd.conf", "dnsmasq.conf", "iptables.v4", "iptables.v6", "hostapd.conf");
			foreach($mut_files as $mfile) {
				// rename to intermediate name as to ot clobber everything to one value
				// pass one
				if(!is_readable("{$cfgdir}/{$mfile}")) {
					echo "Can not read file {$cfgdir}/{$mfile}<br/>";
					continue;
				}

				$oldcfg = file_get_contents("$cfgdir/{$mfile}");
				// echo "{$oldcfg}\n";

				$search = array("/({$state['config']['ap_if']})/", "/({$_POST['ap_if']})/");
				$replace = array("if_lan", "if_wan");
				$newcfg = preg_replace($search, $replace, $oldcfg);

				// pass two
				$search = array("/(if_lan)/", "/(if_wan)/");
				$replace = array("{$_POST['ap_if']}", "{$state['config']['ap_if']}");
				$newcfg = preg_replace($search, $replace, $newcfg);

				// echo "{$newcfg}\n";

				file_put_contents("{$cfgdir}/{$mfile}" , $newcfg);

				// Copy the file but do not restart services, we really need to restart
				//echo "Changing AP interface to {$_POST['ap_if']} in {$mfile} and copy to base<br/>";
				copy_config($mfile);
				// restart_service($mfile);
			}
			// All changes done make sure to save
			$state['config']['ap_if'] = $_POST['ap_if'];
			save_config($state['cfgfile'], $state['config']);
			echo "Saving setting to json<br/>";
			// we reboot after changing the files
			restart();
			echo "Restart, then exit<br/>";
			//exit(0);
		}
		//config_write_hostapd($_POST);
	}
	chdir('web');

	echo "<table class='status-item'><tr><td>";
	// $settings = config_read_dhcpcd($state);
	$settings = array();
	echo "<form >";
	// select AP interface
	echo "Accesspoint Interface: ";
	echo html_select("ap_if", $wlan_ifs, $state['config']['ap_if']);
	echo "<br/>";
	foreach($settings as $ifname => $setting) {
		echo "Interface {$ifname}:";
		echo "<br>";
		//print_r($setting);
		//html_hidden("interface", $ifname);
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

	if(!empty($_POST)) {
		// print_r($_POST);
		$i = 0;
		config_write_hostapd($_POST);
	}

	$wi_ifs = fetch_wlan_interfaces();

	echo "<table class='status-item'><tr><td>";
	$settings = config_read_hostapd($state);
	foreach($settings as $varname => $setting) {
		switch($varname) {
			case "country_code":
				echo "Country setting for Wireless AP adapter: ";
				html_select($varname, array("NL" => "NL", "US" => "US", "JP" => "JP"), $setting);
				break;
			case "interface":
				// echo "AP Interface: ";
				html_hidden($varname, $setting);
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
				html_select($varname, array(1 => 1, 6 => 6, 11 => 11, 36 => 36, 40 => 40, 44 => "44", 48 => 48), $setting);
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
	if(!is_array($wi_list))
		return $bssid_a;

	foreach ($wi_list as $entry) {
		if(isset($entry['ESSID'])) {
			if($entry['ESSID'] == "\"{$ssid}\"") {

				preg_match("/\((.*?)\)/", $entry['Frequency'], $chnmatch);
				$bssid_a[$entry['Address']] = "{$chnmatch[1]} {$entry['Quality']}";
		
			}
		}
	}

	// echo "<pre>". print_r($bssid_a, true) ."</pre>";
	return $bssid_a;

}

function config_supplicant($state) {
	echo "<br>Config wireless client networks<br>";
	echo "<table class='status-item'><tr><td>\n";
	// Process POST request
	// fetch channel list for bbsid per ssid
	$ifname = fetch_wi_client_if($state);
	$nmcli_list = list_nmcli_networks($state, $ifname);
	// echo "<pre>". print_r($wi_list, true) ."</pre>";
	
	
	$settings = config_read_supplicant($state);
	// Empty item at the end for adding new entry
	//$settings['network'][] = array("ssid" => "", "psk" => "", "key_mgmt" => "NONE", "priority" => "-1");
	//echo "<pre>". print_r($settings, true);
	$countries = array("NL" => "NL", "US" => "US", "JP" => "JP");
	$bands = array("2.4" => "band2", "5" => "band5", "6" => "band6");
	$key_mgmt = array("WPA-PSK" => "WPA-PSK", "NONE" => "NONE");
	$priorities = array("-1" => "-1", "0" => "0", "1" => "1", "2" => "2", "3" => "3");
	// Compare 
	if(!empty($_POST)) {
		// echo "<pre>". print_r($_POST, true);
		foreach($bands as $name => $varname) {
			if(isset($_POST[$varname]))
				$settings[$varname] =  $_POST[$varname];
			else 
				unset($settings[$varname]);
		}
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
	// echo "<pre>".  print_r($settings, true) ."</h>";
		config_write_supplicant($settings);
		$settings = config_read_supplicant($state);
	}
	// echo "<pre>".  print_r($settings, true) ."</h>";
	// Empty item at the end for adding new entry
	echo "Frequency bands: ";
	foreach($bands as $name => $band) {
				// parse freq list out to independent vars
				echo " {$name} Ghz: ";
				if(!isset($settings[$band]))
					$settings[$band] = "";
				html_checkbox($band, "on", $settings[$band]);
	}
	echo "<br>\n";
				
	$settings['network'][] = array("ssid" => "", "psk" => "", "key_mgmt" => "NONE", "bssid" => "");

	foreach($settings as $varname => $setting) {
		switch($varname) {
			case "country":
				echo "Country setting for Wireless: ";
				html_select($varname, $countries, $setting);
				echo "<br>\n";
				break;
			case "network":
				//echo "Client networks to connect to:<br/>";
				foreach($setting as $index => $values){
					if(!isset($values['psk']))
						$values['psk'] = "";
					if(!isset($values['bssid']))
						$values['bssid'] = "";
					echo "<table class='status_item'><tr><td colspan=2>\n";
					// echo "<pre>". print_r($values, true) ."</pre>";
					echo "Index {$index} <br>\n";	
					echo "SSID: ";
					echo html_input("{$index}ssid", $values['ssid']);
					echo "<br/>\n";
					echo "Password: ";
					echo html_input("{$index}psk", $values['psk']);
					echo "<br/>\n";
					echo "Type: ";
					echo html_select("{$index}key_mgmt", $key_mgmt, $values['key_mgmt']);
					echo "<br/>\n";
					echo "Bssid: ";
					$bssid_a = list_bssid_assoc($wi_list, $values['ssid']);
					if((!isset($bssid_a[$values['bssid']])) && isset($values['bssid']))
						$bssid_a[$values['bssid']] = "Configured to '{$values['bssid']}'";
					echo html_select("{$index}bssid", $bssid_a, $values['bssid']);
					echo "<br/>\n";
					echo "</td></tr></table>\n";
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

function html_wi_network_json($state) {

	

	$ifname = fetch_wi_client_if($state);

	$nmcli_list = list_nmcli_networks($state, $ifname);
	
	return json_encode($nmcli_list, JSON_PRETTY_PRINT);
}

function html_wi_network_list($state) {
	echo " <div id='wilist'>";
	
	?>
	    <form id="wifi-connect-form" method="post" action="/cfgwiclient">
      <input type="hidden" name="con" value="new-connection" />
      <input type="hidden" name="new-connection_wifi_ssid" id="ssid-input" />
      <input type="hidden" name="new-connection_wifi_psk" id="psk-input" />
    </form>
	<?php
	$ifname = fetch_wi_client_if($state);
	echo "<table width=100% ><tr><td>";
	echo "<strong>Wireless network list {$ifname}</strong><br>";


	$nmcli_list = list_nmcli_networks($state, $ifname);
	$clean_nmcli_list = clean_nmcli_list($nmcli_list);
	// echo "<pre>". print_r($nmcli_list, true) ."</pre>";
	// echo "<pre>". print_r($clean_nmcli_list, true) ."</pre>";
		
	echo "<table class='status-item' >\n";
	if(is_array($clean_nmcli_list)) {
		foreach($clean_nmcli_list as $entry => $fields) {
			$entry = trim(str_replace("\"", "", $entry));
			if($entry == "")
				continue;
			// echo print_r($fields, true);
			$enc = "false";
			if($fields['encryption'] != "")
				$enc = "true";
			echo "<tr data-ssid='{$entry}' data-encrypted='{$enc}'>";
			echo "<td>'{$entry}'</td>";

			echo "<td class=". value_to_colorname($fields['snr']) .">";
			echo "<button class='wibutton connect-button'>Connect</button>";
			echo "</td>";
			echo "</tr>\n";
		}
		echo "</table>";
	}
	
	echo "</td></tr></table>\n";
	echo "</div>\n";
	
	?>
	<div id="password-modal" class="modal">
      <div class="modal-content">
        <span class="close-button">&times;</span>
        <h2>Enter Password</h2>
        <p>SSID: <span id="modal-ssid"></span></p>
        <input type="password" id="modal-password" placeholder="Password" />
        <button id="modal-connect-button">Connect</button>
      </div>
    </div>

   <script>
      $(document).ready(function () {
        let currentSSID = '';

        // Handle button clicks to connect to a network
        $('.connect-button').on('click', function () {
          // Get the SSID and encryption status from the parent table row
          const $row = $(this).closest('tr');
          currentSSID = $row.data('ssid');
          const isEncrypted = $row.data('encrypted');

          if (isEncrypted) {
            // Show the password modal for encrypted networks
            $('#modal-ssid').text(currentSSID);
            $('#password-modal').show();
            $('#modal-password').val('').focus();
          } else {
            // Directly submit for unencrypted networks
            submitForm(currentSSID, '');
          }
        });

        // Handle connection from the modal
        $('#modal-connect-button').on('click', function () {
          const password = $('#modal-password').val();
          $('#password-modal').hide();
          submitForm(currentSSID, password);
        });

        // Close modal when close button is clicked
        $('.close-button').on('click', function () {
          $('#password-modal').hide();
        });

        // Close modal when clicking outside of it
        $(window).on('click', function (event) {
          if ($(event.target).is('#password-modal')) {
            $('#password-modal').hide();
          }
        });

        // Function to submit the form
        function submitForm(ssid, psk) {
          // Set the form fields
          $('#ssid-input').val(ssid);
          $('#psk-input').val(psk);
          // Submit the form
          $('#wifi-connect-form').submit();
        }
      });
    </script>

<?php

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
	if(is_array($options)) {
		foreach($options as $option => $name) {
			$sel = '';
			if($option == $selected)
				$sel = "selected";
			echo "<option value='{$option}' {$sel} >{$name}</option>";
		}
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

	echo "<input type=checkbox name='{$varname}' value='{$option}' {$sel} >";
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
var pageRefresh = 3000; //1 s
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
?>
   <script>
      // New JavaScript for the collapsible sections
      $(document).ready(function () {
        $('.collapsible-header').click(function () {
          $(this).toggleClass('active');
          $(this).next('.collapsible-content').slideToggle();
        });
      });

      // Your existing toggleFields function
      function toggleFields(version) {
        const method = document.querySelector(
          `input[name="${version}_method"]:checked`,
        ).value;
        const block = document.getElementById(`${version}_static_fields`);
        block.style.display = method === 'manual' ? 'block' : 'none';
      }
    </script>
<?php
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
?>
   <script>
      // New JavaScript for the collapsible sections
      $(document).ready(function () {
        $('.collapsible-header').click(function () {
          $(this).toggleClass('active');
          $(this).next('.collapsible-content').slideToggle();
        });
      });

      // Your existing toggleFields function
      function toggleFields(version) {
        const method = document.querySelector(
          `input[name="${version}_method"]:checked`,
        ).value;
        const block = document.getElementById(`${version}_static_fields`);
        block.style.display = method === 'manual' ? 'block' : 'none';
      }
    </script>
<?php
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
    \$('#interfaces').load(\"/interfaceclient\");
}

";
	echo "</script>";

}

function html_jquery_reload_cfgap(){
?>
   <script>
      // New JavaScript for the collapsible sections
      $(document).ready(function () {
        $('.collapsible-header').click(function () {
          $(this).toggleClass('active');
          $(this).next('.collapsible-content').slideToggle();
        });
      });

      // Your existing toggleFields function
      function toggleFields(version) {
        const method = document.querySelector(
          `input[name="${version}_method"]:checked`,
        ).value;
        const block = document.getElementById(`${version}_static_fields`);
        block.style.display = method === 'manual' ? 'block' : 'none';
      }
    </script>
<?php
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
    \$('#interfaces').load(\"/interfaceap\");
}

";
	echo "</script>";

}

function html_jquery_reload_wilist(){
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
		case "NetworkManager":
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
	$cats = array("Agent" => "agent.php", "Web" => "web.php", "Accesspoint" => "hostapd", "OpenVPN" => "ovpn-client", "Dnsmasq" => "dnsmasq\[", "NetworkManager" => "NetworkManager");
	foreach($cats as $name => $proc) {
		echo "<table class='status-item'>";
		echo "<tr><td>{$name}</td></tr>\n";

		foreach(filter_log($proc) as $line) {
			$line = preg_replace("/nomad-hotspot/", "", $line);
			echo "<tr><td>{$line}</td></tr>\n";
		}
		echo "</table>\n";
	}
	echo "</div>";
}

function html_redirect_home() {
	echo "<script type=\"text/javascript\">
	window.location.replace(\"/\");
	</script>";

}

function html_redirect_screensavermenu() {
	echo "<script type=\"text/javascript\">
	window.location.replace(\"/screensavermenu\");
	</script>";

}

function restart(){
	exec("screen -dm bash -c 'sleep 10 && sudo reboot'");
}

function poweroff(){
	exec("screen -dm bash -c 'sleep 10 && sudo poweroff'");
}

function reload(){
	exec("screen -dm bash -c 'sleep 10 && cd nomad-hotspot && ./killagent.sh'");
}

function html_logs($state){
	//echo html_redirect_home();
	if(!empty($_POST)) {
		// print_r($_POST);
		$i = 0;
		foreach($_POST as $varname => $setting) {
			switch($varname) {
				case "action":
						switch($setting) {
							case "screensaver":
								echo html_redirect_screensavermenu();
								break;
							case "restart":
								echo html_redirect_home();
								restart();
								break;
							case "shutdown":
								html_redirect_home();
								poweroff();
								break;
							case "reload":
								html_redirect_home();
								reload();
								break;
						}
						break;
			}
		}
	}

	echo "<table border=0>";
	echo "<tr><td>";
	echo html_form_open();
	echo html_hidden("action", "screensaver");
	echo html_button("Screensaver");
	echo html_form_close();
	echo "</td></tr>\n";
	echo "<tr><td>";
	echo html_form_open();
	echo html_hidden("action", "restart");
	echo html_button("Restart");
	echo html_form_close();
	echo "</td></tr>\n";
	echo "<tr><td>";
	echo html_form_open();
	echo html_hidden("action", "shutdown");
	echo html_button("Shutdown");
	echo html_form_close();
	echo "</td></tr>\n";
	echo "<tr><td>";
	echo html_form_open();
	echo html_hidden("action", "reload");
	echo html_button("Reload");
	echo html_form_close();
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
	//echo "<!-- current rx {$state['if'][$ifname]['traffic']['tx']} top rx {$state['traffic'][$ifname]['toptx']}-->\n";
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
	
	//echo "<!-- current rx {$state['if'][$ifname]['traffic']['tx']} top rx {$state['traffic'][$ifname]['toptx']}-->\n";
	echo " <div id='bwup'>";
	echo "<table border=0 width='150px' valign='bottom' height='500px' cellpadding=0 cellspacing=0>\n";
	if(isset($state['traffic'][$ifname]['hist']['tx'])) {
		$state['traffic'][$ifname]['hist']['tx'] = array_reverse($state['traffic'][$ifname]['hist']['tx']);
		foreach($state['traffic'][$ifname]['hist']['tx'] as $counter) {
			$height = round(($counter / $state['traffic'][$ifname]['toptx']),2) * 150; //percentage
			$rest = abs(100 - $height); 
	
			echo "<tr>";
			//echo "<td width='{$rest}%'>&nbsp;</td>";
			echo "<td align=right ><img height='2px' width='{$height}' border=0 src='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkPfenHgAE/wJQ/BZMvAAAAABJRU5ErkJggg=='></td>";
			echo "</tr>\n";
		}
	
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
	//echo "<!-- current rx {$state['if'][$ifname]['traffic']['rx']} top rx {$state['traffic'][$ifname]['toprx']}-->\n";
	echo " <div id='bwdown'>";
	echo "<table border=0 width='150px' valign='bottom' height='500px' cellpadding=0 cellspacing=0>\n";
	if(isset($state['traffic'][$ifname]['hist']['rx'])) {
		foreach($state['traffic'][$ifname]['hist']['rx'] as $counter) {
			$height = 100;
			if(isset($state['traffic'][$ifname]['toprx']))
				$height = round(($counter / $state['traffic'][$ifname]['toprx']),2) * 150; //percentage
			$rest = abs(100 - $height); 
	
			echo "<tr>";
			//	echo "<td width='{$rest}%'>&nbsp;</td>";
			echo "<td align=left ><img height='2px' width='{$height}' border=0 src='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkPfenHgAE/wJQ/BZMvAAAAABJRU5ErkJggg=='></td>";
			echo "</tr>\n";
		}
	}
	echo "<tr><td valign='top' >". thousandsCurrencyFormat(($state['traffic'][$ifname]['toprx'] * 8)) ."bit</td></tr>\n";
	echo "</table>\n";	
	echo "</div>\n";		
}


function html_interfaces($state, $interface = ""){

	echo " <div id='interfaces'>";
	//echo "<pre>".  print_r($state['if'][$interface]['wi'], true) ."</h>";
	//<tr><td>Interface</td><td>State</td></tr>\n";
	//echo "<tr><td>Adresses</td><td>Traffic</td><td>Totals</td><td>Info</td></tr>\n";
	foreach ($state['if'] as $ifname => $iface) {
		
		if(!preg_match("/{$interface}/i", $ifname)) {
			continue;
		}
		echo "<table class='status-item'>\n";
		
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
		if(!empty($state['devices'][$ifname])) {
			echo "<tr><td >";
			echo html_list_device($state, $ifname);
			echo "</td></tr>";
		}
		if(!empty($state['lldp']['interface'][$ifname])) {
			echo "<tr><td >";
			echo html_list_lldp($state, $ifname);
			echo "</td></tr>\n";
		}
		if(is_array($iface['eth'])){
			echo "<tr><td >";
			echo html_list_eth_link($state, $ifname);
			echo "</td></tr>\n";
		}

		$defgw = fetch_default_route_gw();
		if(!empty($defgw[6])) {
			echo "<tr><td >&nbsp;&nbsp;Gateway6 {$defgw[6][0]['gateway']}</td></tr>\n";
		}
		if(!empty($defgw[4])) {
			echo "<tr><td >&nbsp;&nbsp;Gateway4 {$defgw[4][0]['gateway']}</td></tr>\n";
		}

		//echo "<tr><td>". round(html_traffic_speed($state['if'], $ifname)) ."</td><td>". round(html_traffic_total($state['if'], $ifname)) ."</td></tr>\n";
		echo "</table>";
	}
	echo "</div>\n";
}

function html_list_device($state, $ifname) {
	// echo print_r($state['devices'][$ifname], true);
	if(!is_array($state['devices'][$ifname]))
		return false;
	
	foreach($state['devices'][$ifname] as $key => $value) {
		//echo "{$key}: {$value} <br/>";
		echo "{$value} <br/>";
		
	}
	
}

function html_wi_link_bar($iface, $width = 300, $stack = true) {

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


	if(!isset($iface['wi']['quality']))
		$iface['wi']['quality'] = 0;
	if(!isset($iface['wi']['level']))
		$iface['wi']['level'] = 0;
	
	$qcolor = scale_to_colorname($iface['wi']['quality']);
	$lcolor = scale_to_colorname($iface['wi']['level']);

			echo "<table>";
			echo "<tr><td width={$lwidth}px >{$qstr}</td><td class='$qcolor' width='{$iface['wi']['quality']}px'>&nbsp;{$iface['wi']['quality']}</td><td width='". ((100 - $iface['wi']['quality'])*$factor) ."px'>&nbsp;</td>";
			if($stack == true) {
				echo "</tr>";
				echo "</table>\n";
				echo "<table>";
				echo "<tr>";
			}
			echo "<td width={$lwidth}px >{$lstr}</td><td class='{$lcolor}' width='{$iface['wi']['level']}px'>&nbsp;{$iface['wi']['level']}</td><td width='". ((100 - $iface['wi']['level'])*$factor) ."px'>&nbsp;</td></tr>";
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


function latency_to_color($num = 0) {

	$bgcolor = "unkc";
	$num = floatval($num);
	if($num == 0)
		$bgcolor = "nac";
	elseif($num < 100)
		$bgcolor = "okc";
	elseif($num< 200)
		$bgcolor = "noticec";
	elseif($num< 300)
		$bgcolor = "warnc";
	elseif($num> 500)
		$bgcolor = "nokc";
	elseif($num == 999)
		$bgcolor = "unkc";

	return $bgcolor;
}

function scale_to_color($num = 0) {

	$bgcolor = "nokc";
	$num = floatval($num);
	if($num < 20)
		$bgcolor = "nokc";
	elseif($num< 40)
		$bgcolor = "warnc";
	elseif($num< 60)
		$bgcolor = "noticec";
	elseif($num> 59)
		$bgcolor = "okc";

	return $bgcolor;
}

function value_to_colorname($num = 0) {

	$bgcolor = "nokc";
	$num = floatval($num);
	if($num < 20)
		$bgcolor = "nokc";
	elseif($num < 40)
		$bgcolor = "warnc";
	elseif($num < 60)
		$bgcolor = "noticec";
	elseif($num > 59)
		$bgcolor = "okc";

	return $bgcolor;
}

function scale_to_colorname($num = 0) {
	$bgcolor = "nokc";
	$num = floatval($num);
	if($num < 20)
		$bgcolor = "nokc";
	elseif($num< 40)
		$bgcolor = "warnc";
	elseif($num< 60)
		$bgcolor = "noticec";
	elseif($num> 59)
		$bgcolor = "okc";

	return $bgcolor;
}

function html_status_vpn($state, $icon = true) {
	$color = "nac";
	$vpncon = "Not configured";
	if(isset($state['if']['tun0'])) {
		// print_r($state['if']['tun0']['addr_info'][0]['local']);

		if(!empty($state['if']['tun0']['addr_info'][0]['local'])) {
			$color = "okc";
			$vpncon = "Connected with address: {$state['if']['tun0']['addr_info'][0]['local']}";
		} else {
			$color = "nokc";			$vpncon = "Not connected";
		}
		$img = "images/vpn{$color}.png";
		if($icon === true)
			echo "<tr><td><img height='125px' src='{$img}' alt='VPN: {$vpncon}'></td></tr>\n";
		
		if($icon === false) {
			echo "<table class='status-item'>";
			echo "<tr><td class='{$color}' width='20px'><span style='writing-mode: vertical-lr; text-orientation: upright;'>VPN</span></td>";
			echo "<td>";
			$ifname = "tun0";
			if(isset($state['if'][$ifname])) {
				echo "VPN {$ifname} <br/>";
				if(!empty(if_prefix($state['if'], $ifname))) {
					echo "IP ". implode('<br />IP ', if_prefix($state['if'], $ifname)) ."<br/>\n";
				}
			}
			echo "</td>";
			echo "</tr>\n";
			echo "</table>";
			echo "<table height=2px><tr><td></td></tr></table>\n";
		}
	}
	
	
	if(isset($state['if']['tun0'])) {
		// print_r($state['if']['tun0']['addr_info'][0]['local']);
		//echo "<tr><td><img height='125px' src='{$img}' alt='VPN: {$vpncon}'></td>";
	}

}

function html_status_internet($state, $defif, $icon = true) {

	$defgw = fetch_default_route_gw();
	$gw4 = "";
	$gw6 = "";
	if(isset($defgw[4][0]['gateway']))
		$gw4 = $defgw[4][0]['gateway'];
	if(isset($defgw[6][0]['gateway']))
		$gw6 = $defgw[6][0]['gateway'];
	
	$avgicmp = 999;
	if(isset($state['internet']['latency']['ping'][$gw4]))
		$avgicmp = $state['internet']['latency']['ping'][$gw4];

	if(isset($state['internet']['latency']['ping'][$gw6]))
		$avgicmp = round(($state['internet']['latency']['ping'][$gw4] + $state['internet']['latency']['ping'][$gw6])/ 2);
		

	$color = latency_to_color($avgicmp);
		
	$hrefo = "<a target='_parent' href='http://www.msftconnecttest.com/connecttest.txt' >";
	$hrefc = "</a>";

	switch($state['internet']['captive']) {
		case "TIMEOUT":
			$color = "nokc";
			$img = "images/globe{$color}.png";
			break;
		case "OK":
			$img = "images/globe{$color}.png";
			break;;
		case "PORTAL":
		case "NOK":
			$img = "images/nogo.png";			
			break;;
		case "DNSERR":
			$img = "images/nogo.png";
			break;;
		default:
			$img = "images/globenac.png";
			break;;
	}
	if($icon === true) {
		echo "<tr><td>{$hrefo}<img height='125px' src='{$img}' alt='Internet: {$state['internet']['captive']}'>{$hrefc}</td></tr>\n";
	}

	if($icon === false) {
		echo "<table class='status-item'>";
		
	//echo "<tr><td colspan=2>debug $avgicmp <pre>.";
		//echo print_r($state['internet']['latency']['ping'], true);
				//echo print_r($defgw[4][0], true);
								//echo print_r($avgicmp, true);
	//echo ".</td></tr>";
	
		echo "<tr><td class='{$color}' width='20px'><span style='writing-mode: vertical-lr; text-orientation: upright;'>INTER</span></td>";
		echo "<td>";
		if(isset($state['if'][$defif])) {
			echo "WAN {$defif} <br/>";
			if(!empty(if_prefix($state['if'], $defif))) {
				echo "IP ". implode('<br />IP ', if_prefix($state['if'], $defif)) ."<br/>\n";
			}
			//$defgw = fetch_default_route_gw();
			//print_r($defgw);
			if(!empty($gw4)) {
				echo "<table><tr><td>GW4 {$gw4} &nbsp;</td><td
				  class='{$color}'>&nbsp; {$state['internet']['latency']['ping'][$gw4]} ms &nbsp; </td><td>&nbsp;". lookup_oui(lookup_mac_address($gw4))."</td></tr></table>\n";
			}
			if(!empty($gw6)) {
				echo "<table><tr><td>GW6 {$gw6} &nbsp;</td><td
				  class='{$color}'>&nbsp; {$state['internet']['latency']['ping'][$gw6]} ms &nbsp; </td><td>&nbsp;". lookup_oui(lookup_mac_address($gw6))."</td></tr></table>\n";
			}
			if(!empty($state['internet']['isp'])) {
				echo "Wan IP {$state['internet']['wanip']}<br/>\n";
				echo "AS '{$state['internet']['isp']['descr']}' ({$state['internet']['isp']['asnum']})<br/>\n";

			}
		}
		echo "</td>";
		echo "</tr>\n";
		echo "</table>";
	}
}

function html_status_dns($state, $icon = true) {
	$color = "nac";
	switch($state['internet']['dns']) {
		case "OK":
			$color = "okc";
			break;;
		case "NOK":
			$color = "nokc";
			break;;
		default:
			break;;
	}
	$img = "images/dns{$color}.png";
	if($icon === true)
		echo "<tr><td><img height='125px' src='{$img}' alt='DNS: {$state['internet']['captive']}'></td></tr>\n";	

	if($icon === false) {
		echo "<table class='status-item'>";

		//echo "<tr><td><img height='125px' src='{$img}' alt='DNS: {$state['internet']['captive']}'></td>";
		echo "<tr><td class='$color' width='20px'><span style='writing-mode: vertical-lr; text-orientation: upright;'>DNS</span></td>";
		html_list_dns($state);
		echo "</tr>\n";
		echo "</table>";
	}
}

function html_status_uplink($state, $defif, $icon = true){
	
	if($defif != "") {
		// check for wireless stats
		if(isset($state['if'][$defif]['wi'])) {
			
			$color = scale_to_color(round(($state['if'][$defif]['wi']['quality'] + $state['if'][$defif]['wi']['level']) / 2));
				
			$img = "images/wifi{$color}.png";
			if($icon === true) {
				echo "<tr><td>";
				echo html_wi_link_bar($state['if'][$defif], 140);
				echo "<br/>";
				echo "<img height='125px' src='{$img}' alt='WAN: wireless '></td></tr>\n";
			}
			
			if($icon === false) {
				echo "<table class='status-item'>";

				echo "<tr><td class='$color' width='20px'><span style='writing-mode: vertical-lr; text-orientation: upright;'>NETWORK</span></td>";
				echo "<td>";
				echo html_wi_link_bar($state['if'][$defif], 200, false);
				echo html_list_wi_link($state, $defif);
				echo html_list_lldp($state, $defif);
				echo "</td>";
				echo "</tr>\n";
				echo "</table>";	
			}


		} else {
			// must be wired
			$color = "okc"; // place holder for now without indicators
			$img = "images/ether{$color}.png";
			if($icon === true)
				echo "<tr><td><img height='125px' src='{$img}' alt='WAN Ethernet'></td></tr>\n";

			if($icon === false) {
				echo "<table class='status-item'>";
				
				echo "<tr ><td class='$color' width='20px'><span style='writing-mode: vertical-lr; text-orientation: upright;'>NETWORK</span></td>";
				echo "<td>";
				echo html_list_lldp($state, $defif);
				echo html_list_eth_link($state, $defif);
				echo "</td>";
				echo "</tr>\n";
				echo "</table>";				
			}

		}
		
	}	

}


function html_list_lldp($state, $defif) {
	// Fetch LLDP Info
	if(isset($state['lldp']['interface'][$defif])) {
		foreach($state['lldp']['interface'][$defif]['chassis'] as $id) {
			if(isset($id['descr'])) {
				echo "LLDP descr {$id['descr']} <br/>";
			}
		}
		if(isset($state['lldp']['interface'][$defif]['port']['descr'])) {
				echo "LLDP port {$state['lldp']['interface'][$defif]['port']['descr']} <br/>";
		}
	}
}

function html_list_eth_link($state, $defif) {
	if(isset($state['if'][$defif]['eth'])) {
		foreach($state['if'][$defif]['eth'] as $field => $value) {
			switch($field) {
				case "phyad":
				case "transceiver":
				case "mdi-x":
				case "currentmessagelevel":
					continue 2;
				default:
					echo ucwords($field) ." ". $value ."<br/>";
			}
		}

	}
}

function html_list_wi_link($state, $defif) {
	if(isset($state['if'][$defif]['wi'])) {
		foreach($state['if'][$defif]['wi'] as $field => $value) {
			switch($field) {
				case "phy":
				case "mode":
				case "frequency":
				case "width":
				case "quality":
				case "level":
					continue 2;
				case "bssid":
					echo ucwords($field) ." '". $value ."' ". lookup_oui($value) ."<br/>";
					break;
				case "channel":
					if(isset($state['if'][$defif]['wi']['frequency']))
					echo ucwords($field) ." ". $value ." ({$state['if'][$defif]['wi']['frequency']}Ghz) Width {$state['if'][$defif]['wi']['width']}Mhz<br/>";
					else
					 echo ucwords($field) ." ". $value ." Width {$state['if'][$defif]['wi']['width']}Mhz<br/>";
					break;
				default:
					echo ucwords($field) ." '". $value ."'<br/>";
			}
		}

	}
}

function html_list_dns($state) {	
	echo "<td><table>";
	
	foreach ($state['dns'] as $family => $entries) {
		foreach ($entries as $entry) {
			if(!isset($state['internet']['latency']['dnsping'][$entry]))
				$state['internet']['latency']['dnsping'][$entry] = 0;
			$color = latency_to_color($state['internet']['latency']['dnsping'][$entry]);
			echo "<tr><td>". strtoupper($family) ." {$entry} &nbsp;</td><td class='{$color}'>&nbsp; {$state['internet']['latency']['dnsping'][$entry]} ms &nbsp;</td></tr>\n";
			
		}
	}
	echo "</table></td>";
}

function html_connectivity_screensaver($state){
	$hrefo = "";
	$hrefc = "";
	echo " <div id='connectivityscreensaver'>";
	echo "<table>";

	// find the default route interface
	$defif = find_wan_interface($state);	

	// VPN
	html_status_vpn($state, true);
	
	// Internet, ping color
	html_status_internet($state, $defif, true);

	// DNS
	html_status_dns($state, true);
	
	// Uplink
	html_status_uplink($state, $defif, true);
	
	echo "</table>";
	echo "</div>\n";		
	
}

function html_connectivity_extra($state){
	$hrefo = "";
	$hrefc = "";
	
	// find the default route interface
	$defif = find_wan_interface($state);

	echo " <div id='connectivityextra'>";

	echo "<table height=2px><tr><td></td></tr></table>\n";
	// VPN
	echo html_status_vpn($state, false);

	// Internet
	echo html_status_internet($state, $defif, false);
	
	echo "<table height=2px><tr><td></td></tr></table>\n";

	// DNS
	html_status_dns($state, false);
	
	echo "<table height=2px><tr><td></td></tr></table>\n";

	// Uplink
	html_status_uplink($state, $defif, false);

	// Network

	echo "<table height=2px><tr><td></td></tr></table>\n";
	echo "</div>\n";
}

function css_color_to_image($cssc) {
	switch($cssc) {
		case "okc":
			return "green";
		case "nokc":
			return "red";
		case "noticec":
			return "yellow";
		case "warnc":
			return "orange";
		case "unkc":
			return "blue";
		default:
			return "grey";
	}

}


function list_wi_link($state, $defif) {
	if(isset($state['if'][$defif]['wi'])) {
		foreach($state['if'][$defif]['wi'] as $field => $value) {
			switch($field) {
				case "mode":
					continue 2;
				case "quality":
					echo ucwords($field) ." '". $value ."' ";
					break;
				default:
					echo ucwords($field) ." '". $value ."'<br/>";
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
		$bgcolor = "unkc";
	elseif($diff < 11)
		$bgcolor = "okc";
	elseif($diff < 21)
		$bgcolor = "noticec";	
	elseif($diff < 31)
		$bgcolor = "warnc";	
	else
		$bgcolor = "nokc";
		
	echo "<table class='menu-row'><tr><td class='{$bgcolor}'>&nbsp;{$diff}</td></tr>\n";
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

function lookup_oui($mac) {
 	if(empty($mac))
		return false;
	
	if(!is_string($mac))
		return false;

	$mac = str_replace(":", "", $mac);
	$mac = str_replace("-", "", $mac);
	$mac = strtoupper(substr($mac, 0, 6));
	$ieee = "/usr/share/ieee-data/oui.csv";
	if(is_readable($ieee)) {
		$cmd = "grep $mac $ieee";
		exec($cmd, $out, $ret);
		// echo print_r($out, true);
		foreach($out as $line) {
			// $el = explode(",", $line);
			preg_match("/\"(.*)\"/", $line, $match);
			if(isset($match[1]))
				return $match[1];
		}
	}
	return false;
}

function lookup_mac_address($ip) {
	if(!preg_match("/([0-9a-z.:]+)/i", $ip))
		return false;

	$cmd = "arp -na";
	exec($cmd, $out, $ret);
	foreach($out as $line) {
		if(preg_match("/\($ip\)/i", $line)) {
			$el = explode(" ", $line);
			return $el[3];
		}

	}

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
