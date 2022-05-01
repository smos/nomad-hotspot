#!/bin/sh

# Hi, let's get some requirements out of the way

echo "Installing some software requirements"
sudo DEBIAN_FRONTEND=noninteractive apt -y install hostapd dnsmasq arping php-cli openvpn screen php-curl iptables-persistent

echo "Save original configuration files"
cd ~/nomad-hotspot/orig

if [ ! -f "dnsmasq.conf" ]; then
	sudo cp -a /etc/dnsmasq.conf .
fi
if [ ! -f "dhcpcd.conf" ]; then
	sudo cp -a /etc/dhcpcd.conf .
fi
if [ ! -f "hostapd.conf" ]; then
	sudo cp -a /etc/hostapd/hostapd.conf .
fi
cd ~/nomad-hotspot

echo "Changing Hostname"
sudo hostnamectl set-hostname nomad-hotspot
sudo sed -i 's/raspberrypi/nomad-hotspot/g' /etc/hosts

echo "Enable system services required for Wifi AP and DHCP Server"
sudo systemctl unmask hostapd
sudo systemctl enable hostapd
sudo systemctl unmask dnsmasq
sudo systemctl enable dnsmasq

echo "Install the nomad-hotspot service unit"
sed -i "s/pi/$LOGNAME/g" install/nomad-hotspot.service
sudo cp install/nomad-hotspot.service /etc/systemd/system/nomad-hotspot.service
sudo systemctl enable nomad-hotspot.service

echo "Enable IP forwarding"
sudo sed -i 's/net\.ipv4\.ip_forward\=0/net\.ipv4\.ip_forward\=1/g' /etc/sysctl.conf
sudo /sbin/sysctl -p/etc/sysctl.conf

echo "Load some basic IPtables rules for forwarding"
sudo iptables-restore < conf/iptables.rules
sudo iptables-save > conf/iptables.rules

echo "System Services enabled, the agent will take care of the rest"
sudo service nomad-hotspot start
