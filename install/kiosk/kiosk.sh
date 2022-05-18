#!/bin/bash
export DISPLAY=:0

CURRENTIP=`ifconfig wlan1 |grep -oE "\b([0-9]{1,3}\.){3}[0-9]{1,3}\b"|head -n1`

URL="http://172.17.88.1:8000"

# Check if we are already running, just reset existing session
KIOSKPID=`ps ax|egrep -A5 "[k]iosk.sh"|egrep "[c]hromium" |awk '{print $1}'`
if [ "$KIOSKPID" != "" ]; then
	echo "Found PID "$KIOSKPID""
	# Kill previous browser leftover
	killall chromium-browser
	exit 0;
fi

# Hide the mouse from the display
unclutter &

# Nothing started? Enter the loop
while true
do
	rsync -a --delete ~/chromium-default/ ~/.config/chromium/
	# If Chromium crashes (usually due to rebooting), clear the crash flag so we don't have the annoying warning bar
	sed -i 's/"exited_cleanly":false/"exited_cleanly":true/' ~/.config/chromium/Default/Preferences
	sed -i 's/"exit_type":"Crashed"/"exit_type":"Normal"/' ~/.config/chromium/Default/Preferences
	chromium-browser --app="$URL" --no-default-browser-check --no-first-run --noerrdialogs --disable-gpu \
	--disable-restore-background-contents --start-maximized --bwsi --disable-infobars --no-error-dialogs --disable-translate \
	--window-size=470,800 --window-position=0,0
done
