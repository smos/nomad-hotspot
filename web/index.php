<?php
include "web.php";

if (preg_match('/\.(?:css|png|jpg|jpeg|gif)$/', $_SERVER["REQUEST_URI"])) {
    return false;    // serve the requested resource as-is.
} else {
	
	switch($_SERVER["REQUEST_URI"]) {
		case "/":
		case "/status":
			html_head();
			echo html_status();
			echo html_processes();
			echo html_foot();	
			break;
		case "/config":
			echo html_head();
			echo html_config();
			echo html_foot();	
			break;
		case "/json":
			echo send_json();	
			break;
		case "/openvpn":
			echo html_head();
			echo html_openvpn();
			echo html_foot();	
			break;
	}
	//print_r($_SERVER["REQUEST_URI"]);
}




?>
