#!/bin/bash

# Hi, let's get some requirements out of the way

echo "Installing some software requirements"
PACKAGES="hostapd dnsmasq arping php-cli openvpn screen php-curl iptables-persistent stunnel4 lldpd whois"

for PACKAGE in $PACKAGES; do
	sudo DEBIAN_FRONTEND=noninteractive apt -y install $PACKAGE
done

echo "Save original configuration files"
mkdir -p orig
cd ~/nomad-hotspot/orig
if [ ! -f "/etc/dnsmasq.conf" ]; then
	sudo cp -a /etc/dnsmasq.conf orig/
fi
if [ ! -f "/etc/dhcpcd.conf" ]; then
	sudo cp -a /etc/dhcpcd.conf orig/
fi
if [ ! -f "/etc/hostapd/hostapd.conf" ]; then
	sudo cp -a /etc/hostapd/hostapd.conf orig/
fi
if [ ! -f "/etc/wpa_supplicnat/wpa_supplicant.conf" ]; then
	sudo cp -a /etc/wpa_supplicant/wpa_supplicant.conf orig/
fi

cd ~/nomad-hotspot
echo "Copy templates to the conf directory if they do not exist"
cp -pn templates/* conf/

echo "Changing Hostname"
sudo hostnamectl set-hostname nomad-hotspot
sudo sed -i 's/raspberrypi/nomad-hotspot/g' /etc/hosts

HIGH=`iwlist wlan0 freq | awk '/Channel [0-9]+ :/ { print $2}' | sort -ru | head -n1`
if [ "$HIGH" -lt 32 ]; then
	echo "Highest wlan0 channel "$HIGH", adjust hostapd.conf"
	sed -i 's/channel=48/channel=11/g' conf/hostapd.conf
	sed -i 's/hw_mode=a/hw_mode=g/g' conf/hostapd.conf
fi

echo "Enable system services required for Wifi AP and DHCP Server"
sudo systemctl unmask hostapd
sudo systemctl enable hostapd
sudo systemctl unmask dnsmasq
sudo systemctl enable dnsmasq

echo "Install the nomad-hotspot service unit"
sed -i "s/pi/$LOGNAME/g" install/nomad-hotspot.service
sudo cp install/nomad-hotspot.service /etc/systemd/system/nomad-hotspot.service
sudo systemctl unmask nomad-hotspot.service
sudo systemctl enable nomad-hotspot.service
sudo systemctl start nomad-hotspot.service

echo "Fixup user in dhcpcd.conf"
sed -i "s/controlgroup pi/controlgroup $LOGNAME/g" conf/dhcpcd.conf

echo "Increase WPA supplicant logging"
sudo sed -i 's/supplicant -B -c/supplicant -dd -B -c/g' /lib/dhcpcd/dhcpcd-hooks/10-wpa_supplicant

echo "Enable IP forwarding"
sudo sed -i 's/^#net\.ipv4\.ip_forward\=0/net\.ipv4\.ip_forward\=1/g' /etc/sysctl.conf
sudo /sbin/sysctl -p /etc/sysctl.conf

echo "Enable PCIe tune, thnx Jeff Geerling"
sudo sed -i 's/fsck.repair=yes rootwait/pci=pcie_bus_perf rootwait/g' /boot/cmdline.txt

echo "Load some basic IPtables rules for forwarding"
sudo iptables-restore conf/iptables.v4
sudo ip6tables-restore conf/iptables.v6
sudo service netfilter-persistent save

echo "Disable Openvpn per default"
sudo systemctl stop openvpn.service
sudo systemctl disable openvpn.service

echo "System Services enabled, the agent will take care of the rest"
echo "Rebooting now, should come up with wireless network "Nomad-Hotspot""
sudo reboot
