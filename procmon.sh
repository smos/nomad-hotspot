#!/bin/bash

echo "Screen sessions"
screen -ls

echo "Select 1 for Webserver"
echo "Select 2 for Agent"
echo "Select 3 for OpenVPN Client log"
echo "Select 4 for hostapd log"

read select
if [ "$select" = "1" ]; then
	echo "exit with ctrl-ad"
	sleep 3
	screen -r nomad-webserver
elif [ "$select" = "2" ]; then
	echo "exit with ctrl-ad"
	sleep 3
	screen -r nomad-hotspot
elif [ "$select" = "3" ]; then
	sudo grep ovpn /var/log/syslog |tail -n20
elif [ "$select" = "4" ]; then
	sudo grep hostap /var/log/syslog |tail -n20
else
	echo Not a valid choice made
fi
