#!/bin/bash

echo "Let's restore the original configuration files"

cd ~/nomad-hotspot/orig
FILES="dhcpcd.conf
dnsmasq.conf
hostapd.conf
"
for FILE in $FILES; do
	case $FILE in
		"dnsmasq.conf")
			echo "Found dnsmasq";
			#sudo cp -a dnsmasq.conf /etc/dnsmasq.conf
			sudo DEBIAN_FRONTEND=noninteractive apt -y purge dnsmasq
			continue;;
		"dhcpcd.conf")
			echo "Found dhcpcd";
			sudo cp -a dhcpcd.conf /etc/dhcpcd.conf
			# Don't remove, just copy original config back
			#sudo DEBIAN_FRONTEND=noninteractive apt -y purge dhcpcd
			continue;;
		"wpa_supplicant.conf")
			echo "Found wpa_supplicant.conf";
			sudo cp -a wpa_supplicant.conf /etc/wpa_supplicant/wpa_supplicant.conf
			# Don't remove, just copy original config back
			#sudo DEBIAN_FRONTEND=noninteractive apt -y purge dhcpcd
			continue;;
		"hostapd.conf")
			echo "Found hostapd";
			#sudo cp -a hostapd.conf /etc/hostapd/hostapd.conf
			#sudo DEBIAN_FRONTEND=noninteractive apt -y purge hostapd
			sudo rm /etc/hostapd/hostapd.conf
			continue;;
		*)
			echo "Found file "$FILE" that I have no idea what to do with, sorry."
	esac
	echo "Next File"
done

echo "Disable nomad-hotspot service"
sudo systemctl stop nomad-hotspot.service
sudo systemctl disable nomad-hotspot.service
sudo systemctl mask nomad-hotspot.service

echo "Removing IP forwarding Sysctl, don't forget to reboot"
sudo rm -f /etc/sysctl/sysctl-routed-ap.conf

echo "Removing OpenVPN client configuration"
sudo rm -f /etc/openvpn/client/nomad.conf

echo "Removing Firewall rules"
sudo DEBIAN_FRONTEND=noninteractive apt purge -y netfilter-persistent iptables-persistent
