#!/bin/sh

echo "Let's restore the original configuration files"

cd ~/nomad-hotspot/orig
for FILE in `ls `; do
	case FILE in
		dnsmasq.conf)
			echo "Found dnsmasq";
			sudo cp -a dnsmasq.conf /etc/dnsmasq.conf
			sudo DEBIAN_FRONTEND=noninteractive apt -y purge dnsmasq
			break;;
		dhcpcd.conf)
			echo "Found dhcpcd";
			sudo cp -a dhcpcd.conf /etc/dhcpcd.conf
			# Don't remove, just copy original config back
			#sudo DEBIAN_FRONTEND=noninteractive apt -y purge dhcpcd
			break;;
		hostapd.conf)
			echo "Found hostapd";
			sudo cp -a hostapd.conf /etc/hostapd/hostapd.conf
			sudo DEBIAN_FRONTEND=noninteractive apt -y purge hostapd
			break;;
		*)
			echo "Found file "$FILE" that I have no idea what to do with, sorry."
			break;;
	esac
done

echo "Removing IP forwarding Sysctl, don't forget to reboot"
sudo rm -f /etc/sysctl/sysctl-routed-ap.conf

echo "Removing OpenVPN client configuration"
sudo rm -f /etc/openvpn/client/nomad.conf

echo "Removing Firewall rules"
sudo DEBIAN_FRONTEND=noninteractive apt purge -y netfilter-persistent iptables-persistent
