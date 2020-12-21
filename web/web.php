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
    \$('#interfaces').load(location.href + \" #interfaces\");
    \$('#connectivity').load(location.href + \" #connectivity\");
    \$('#processes').load(location.href + \" #processes\");
}

";
	echo "</script>";

}
function html_status($state){
	//echo " <div id='interfaces'></div>\n";
	echo html_interfaces($state);
	//echo " <div id='connectivity'></div>\n";
	echo html_connectivity($state);
	//echo " <div id='processes'></div>\n";
	echo html_processes($state);
}
function html_interfaces($state){
	echo " <div id='interfaces'>";
	echo "<table border=1><tr><td>Interface</td><td>State</td><td>Adresses</td></tr>\n";
	foreach ($state['if'] as $ifname => $iface) {	
		echo "<tr><td>{$ifname}</td><td>". if_state($state['if'], $ifname)."</td><td>". implode(',', if_prefix($state['if'], $ifname)) ."</td></tr>\n";
	}
	echo "</table>";	
	echo "</div>\n";		
}
	
function html_connectivity($state){
	echo "<table border=1><tr><td>Connectivity</td><td>Result</td></tr>\n";
	foreach ($state['internet'] as $check => $result) {	
		echo "<tr><td>{$check}</td><td>{$result}</td></tr>\n";
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



function send_json($state){
	header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
	header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
	header("Content-Type: application/json");
	echo json_encode($state, JSON_PRETTY_PRINT);
	
}