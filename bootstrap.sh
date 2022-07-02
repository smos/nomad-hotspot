#!/bin/sh

# Hi, let's get some requirements out of the way

echo "Installing some software requirements"
sudo DEBIAN_FRONTEND=noninteractive apt -y install hostapd dnsmasq arping php-cli openvpn screen php-curl iptables-persistent stunnel4

echo "Save original configuration files"
cd ~/nomad-hotspot
if [ ! -f "dnsmasq.conf" ]; then
	sudo cp -a /etc/dnsmasq.conf orig/
fi
if [ ! -f "dhcpcd.conf" ]; then
	sudo cp -a /etc/dhcpcd.conf orig/
fi
if [ ! -f "hostapd.conf" ]; then
	sudo cp -a /etc/hostapd/hostapd.conf orig/
fi

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
sudo systemctl enable nomad-hotspot.service

echo "Fixup user in dhcpcd.conf"
sed -i "s/controlgroup pi/controlgroup $LOGNAME/g" conf/dhcpcd.conf

echo "Enable IP forwarding"
sudo sed -i 's/net\.ipv4\.ip_forward\=0/net\.ipv4\.ip_forward\=1/g' /etc/sysctl.conf
sudo /sbin/sysctl -p/etc/sysctl.conf

echo "Load some basic IPtables rules for forwarding"
sudo iptables-restore < conf/iptables.v4
sudo iptables-save > conf/iptables.v4

echo "System Services enabled, the agent will take care of the rest"
echo "Rebooting now, should come up with wireless network "Nomad-Hotspot""
sudo reboot
