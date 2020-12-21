<?php


function html_head(){
	header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
	header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
	header("Content-Type: text/html");
	echo "<html><head><title>Nomad Hotspot</title></head><body>\n";
	// Menu Header
	echo "<table><tr>";
	echo "<td><a href='/status'>Status</a></td>";
	echo "<td><a href='/config'>Config</a></td>";
	// echo "<td><a href='/openvpn'>OpenVPN</a></td>";
	echo "<td><a href='/json'>JSON</a></td>";
	echo "<tr></table>\n";
	
}
function html_foot(){
	echo "</body></html>\n";		
}

function html_config($state){
	echo "";
		
}

function html_status($state){
	echo "<table><tr><td>Interface</td><td>State</td><td>Adresses</td></tr>\n";
	foreach ($state['if'] as $ifname => $iface) {	
		echo "<tr><td>{$ifname}</td><td>". if_state($state['if'], $ifname)."</td><td>". implode(',', if_prefix($state['if'], $ifname)) ."</td></tr>\n";
	}
	echo "</table>";	
	echo "\n";		
		
}
function html_openvpn($state){
	echo "";
		
}

function html_processes($state){
	echo "<table><tr><td>Process name</td><td>Number</td></tr>\n";
	foreach ($state['proc'] as $procname => $number) {
		echo "<tr><td>{$procname}</td><td>{$number}</td></tr>\n";
	}
	echo "</table>";	
	echo "\n";		
}


function send_json($state){
	header("Content-Type: application/json");
	echo json_encode($state, JSON_PRETTY_PRINT);
	
}