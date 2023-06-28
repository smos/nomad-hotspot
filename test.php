<?php

include("functions.php");
include("web/web.php");

$state = read_tmpfs($tmpfsurl);



echo print_r(iw_info($state['if'], "wlan1"), true);

echo html_wi_link_bar($state['if']['wlan1']);
