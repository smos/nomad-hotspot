<?php

include("functions.php");
include("web/web.php");

$state = read_tmpfs($tmpfsurl);


$defif = find_wan_interface($state);
echo print_r(if_prefix($state['if'], $defif), true);



$defgw = fetch_default_route_gw();
echo print_r($defgw, true);

echo print_r(iw_info($state['if'], $defif), true);
//echo print_r(iw_info($state['if'], "wlan1"), true);

echo html_wi_link_bar($state['if']['wlan1']);

echo print_r(eth_info($state['if'], $defif), true);
//echo print_r($state['if']['eth1']['eth'], true);

echo print_r(fetch_lldp_neighbors($state['if'], $defif), true);

echo print_r(dnsping($state), true);

echo print_r(ping(), true);
echo print_r(check_latency($state), true);

echo print_r(fetch_wlan_interfaces(), true);
