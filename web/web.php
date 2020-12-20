<?php


function html_head(){
	header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
	header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
	header("Content-Type: text/html");
	echo "<html><head><title>Nomad Hotspot</title></head><body>\n";
	echo "<table><tr>";
	echo "<td><a href='/status'>Status</a></td>";
	echo "<td><a href='/config'>Config</a></td>";
	echo "<td><a href='/processes'>Processes</a></td>";
	echo "<td><a href='/openvpn'>OpenVPN</a></td>";
	echo "<td><a href='/json'>JSON</a></td>";
	echo "<tr></table>\n";
	
}
function html_foot(){
	echo "</body></html>\n";
		
}

function html_config(){
	echo "";
		
}

function html_status(){
	echo "";
		
}
function html_openvpn(){
	echo "";
		
}

function html_processes(){
	echo "";
		
}
function send_json(){
	header("Content-Type: application/json");
    require_once('../functions.php');
	$state = read_shm($shm_id, $shm_size);
	echo json_encode($state, JSON_PRETTY_PRINT);
	
}