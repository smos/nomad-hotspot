<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);
ini_set('log_errors', 1);
ini_set('error_log', 'syslog');

openlog("", LOG_PID, LOG_LOCAL0 );
$cfgdir = "conf";
include "web.php";

if (preg_match('/\.(?:css|png|jpg|jpeg|gif)$/', $_SERVER["REQUEST_URI"])) {
    return false;    // serve the requested resource as-is.
} else {
	include "../functions.php";
	$state = read_tmpfs($tmpfsurl);

	switch($_SERVER["REQUEST_URI"]) {
		case "/":
		case "/status":
			// html containing refreshing status divs
			echo html_header();
			echo html_head();
			echo html_jquery();
			echo html_menu();
			echo html_status_extra($state);
			echo html_jquery_reload();
			echo html_foot();	
			break;
		case "/interfaces":
			// interface status div
			echo html_interfaces($state);
			break;
		case "/interfaceap":
			// interface status div
			echo html_interfaces($state, fetch_ap_if($state));
			break;
		case "/interfaceclient":
			// interface status div
			echo html_interfaces($state, fetch_wi_client_if($state));
			break;
		case "/interfacetun0":
			// interface status div
			echo html_interfaces($state, "tun0");
			break;
		case "/wilist":
			// clients status div 
			echo html_wi_network_list($state);
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
		case "/processing":
			// processing status div 
			echo html_processing($state);
			break;
		case "/cfgif":
		case "/cfgwiap":
		case "/cfgwiclient":
		case "/cfgovpn":
			echo html_header();
			echo html_head();
			echo html_jquery();
			echo html_menu();
			echo html_config($state, $_SERVER["REQUEST_URI"]);
			echo html_foot();	
			break;
		case "/json":
		case "/json?":
			echo send_json($state);	
			break;
		case "/screensaver":
			echo html_header();
			echo html_head();
			echo html_jquery();
			echo html_status_screensaver($state);
			echo html_jquery_reload_screensaver();
			echo html_foot();	
			break;
		case "/screensavermenu":
			echo html_header();
			echo html_head();
			echo html_jquery();
			echo html_menu();
			echo html_status_screensaver($state);
			echo html_jquery_reload_screensaver();
			echo html_foot();	
			break;
		case "/connectivityscreensaver":
			// status div screensaver
			echo html_connectivity_screensaver($state);
			break;
		case "/connectivityextra":
			// status div screensaver
			echo html_connectivity_extra($state);
			break;
		case "/bwup":
			// status div screensaver
			echo html_bw_up($state);
			break;
		case "/bwdown":
			// status div screensaver
			echo html_bw_down($state);
			break;
		case "/logs":
			// logs
			echo html_header();
			echo html_head();
			echo html_jquery();
			echo html_menu();
			echo html_logs($state);
			echo html_foot();
			break;
	}
	//print_r($_SERVER["REQUEST_URI"]);
}




?>
