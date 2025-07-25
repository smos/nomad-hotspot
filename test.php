<?php

include("functions.php");
include("web/web.php");

$cfgdir = "conf";
if(strstr($_SERVER['DOCUMENT_ROOT'], "web")) {
        $basedir = str_replace("/web", "", dirname($_SERVER['DOCUMENT_ROOT']));
} else {
        $basedir = "/home/{$_SERVER['LOGNAME']}/nomad-hotspot";
}
$cfgfile = "{$basedir}/{$cfgdir}/config.json";
$state['config'] = read_config($cfgfile);
$state['cfgfile'] = $cfgfile;

$state = read_tmpfs($tmpfsurl);

// print_r(fetch_wlan_interfaces());

//$defif = find_wan_interface($state);
//echo print_r(if_prefix($state['if'], $defif), true);


//$nmcli = list_nmcli_networks($state, "wlan1");
//echo print_r(clean_nmcli_list($nmcli), true);

//$defgw = fetch_default_route_gw();
//echo print_r($defgw, true);

//echo print_r(iw_info($state['if'], $defif), true);
echo print_r(iw_info($state['if'], "wlan1"), true);

//echo html_wi_link_bar($state['if']['wlan1']);

//echo print_r(eth_info($state['if'], $defif), true);
//echo print_r($state['if']['eth1']['eth'], true);

//echo print_r(fetch_lldp_neighbors($state['if'], $defif), true);

//echo print_r(dnsping($state), true);

//echo print_r(ping(), true);
// echo print_r(check_latency($state), true);

//echo print_r(fetch_wlan_interfaces(), true);
//echo print_r(fetch_ap_if($state), true);

//print_r(parse_dhcp_nameservers($state));
//echo print_r(dnsping($state, "8.8.8.8"));

// echo print_r(ping("8.8.8.8"));

// echo lookup_mac_address("172.17.88.1");

// echo print_r(fetch_last_captive_test($state));

// echo key(fetch_last_captive_test($state));
// echo current(fetch_last_captive_test($state));

// echo print_r(lookup_oui("7c:69:f6:2b:8d:3f"));
// $ip = "82.151.32.166";

// echo print_r(fetch_as_info($state, $ip) );
