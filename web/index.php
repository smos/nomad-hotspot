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
			echo html_head();
			echo html_jquery();
			echo html_status($state);
			echo html_jquery_reload();
			echo html_foot();	
			break;
		case "/interfaces":
			echo html_interfaces($state);
			break;
		case "/connectivity":
			echo html_connectivity($state);
			break;
		case "/processes":
			echo html_processes($state);
			break;
		case "/config":
			echo html_head();
			echo html_config($state);
			echo html_foot();	
			break;
		case "/json":
		case "/json?":
			echo send_json($state);	
			break;
		case "/openvpn":
			echo html_head();
			echo html_openvpn($state);
			echo html_foot();	
			break;
	}
	//print_r($_SERVER["REQUEST_URI"]);
}




?>
