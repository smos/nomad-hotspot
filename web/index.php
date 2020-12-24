<?php
include "web.php";

if (preg_match('/\.(?:css|png|jpg|jpeg|gif)$/', $_SERVER["REQUEST_URI"])) {
    return false;    // serve the requested resource as-is.
} else {
	include "../functions.php";
	$state = read_shm($shm_id, $shm_size);
	
	switch($_SERVER["REQUEST_URI"]) {
		case "/":
		case "/status":
			// html containing refreshing status divs
			echo html_head();
			echo html_jquery();
			echo html_status($state);
			echo html_jquery_reload();
			echo html_foot();	
			break;
		case "/interfaces":
			// interface status div
			echo html_interfaces($state);
			break;
		case "/clients":
			// clients status div 
			echo html_clients($state);
			break;
		case "/connectivity":
			echo html_connectivity($state);
			break;
		case "/processes":
			// processes status div 
			echo html_processes($state);
			break;
		case "/cfgif":
		case "/cfgwiap":
		case "/cfgwiclient":
		case "/cfgovpn":
			echo html_head();
			echo html_config($state, $_SERVER["REQUEST_URI"]);
			echo html_foot();	
			break;
		case "/json":
		case "/json?":
			echo send_json($state);	
			break;
	}
	//print_r($_SERVER["REQUEST_URI"]);
}




?>
