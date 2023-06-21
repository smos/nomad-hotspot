#!/bin/bash

echo "Screen sessions"
screen -ls


echo "Select 1 for Webserver"
echo "Select 2 for Agent"
echo "Select 3 for OpenVPN Client log"
echo "Select 4 for hostapd log"
echo "Select 5 for dnsmasq log"
echo "Select 6 for dhcpcd log"

if [ -n $1 ]; then
	select=$1;
else
	read select
fi
if [ "$select" = "1" ]; then
	echo "exit with ctrl-ad"
	sleep 3
	screen -r nomad-webserver
elif [ "$select" = "2" ]; then
	echo "exit with ctrl-ad"
	sleep 3
	screen -r nomad-hotspot
elif [ "$select" = "3" ]; then
	sudo grep -a ovpn /var/log/syslog |tail -n20
elif [ "$select" = "4" ]; then
	sudo grep -a hostap /var/log/syslog |tail -n20
elif [ "$select" = "5" ]; then
	sudo grep -a dnsmasq /var/log/syslog |grep -v dhcp |tail -n20
elif [ "$select" = "6" ]; then
	sudo grep -a dhcpcd /var/log/syslog |tail -n20
else
	echo Not a valid choice made
fi
