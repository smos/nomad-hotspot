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



$url = "http://www.microsoftconnecttest.com/connect.txt";
//$url = "connect.txt";
$arr = parse_portal_page($url);




?>
