Hi There

So this is my attempt at an Open Source Wifi Travel Router, most have limited features, or dropped support.
Other motivations include
- The reception is only proper from Y because building X is in the way when you are at Z
- The camping site is still billing *per device* in 2020.
- You can strategically place the device so you don't need to perform yoga to access a webpage. See 1.
- Tunnel all the traffic over OpenVPN because the only wireless network they offer is not protected.

Requirements
- You start with a Raspberry Pi 3B+ or later (for now, or older with 2 usb wifi adapters)
- You add a USB wifi adapter as the client (extension cables work a treat for those difficult locations!)
- The Onboard wifi adapter becomes the AP, as you are most likely close by

Software Features planned, in more or less my order
- OpenVPN Client for tunneling traffic over untrusted open Wifi networks
- Add a webserver for configuration
- Add service and logging to syslog
Nice to have?
- Perform Multi-Wan Failover
- Integrate pi-hole into dnsmasq

Where it is now
- Has a 5Ghz AP Nomad-Hotspot with passphrase OnTheRoadAgain
- Performs DHCP on eth0 and wlan1
- IP forwarding with outbound NAT on eth0 and wlan1
- DHCP Server on wlan0 (onboard)

To get things going.

- Check out the git repository in the pi home directory
git clone https://github.com/databeestje/nomad-hotspot.git
- Change into the nomad-hotspot directory with "cd ~/nomad-hotspot" and run "sudo ./bootstrap.sh"
- In case you want to uninstall you can run the uninstall.sh script which should restore the previous configuration.

Changing the configuration manually
The agent monitors the eth0 and wlan1 connections, and the configuration files under the conf directory.
As you change the configuration files it will compare them and replace as needed and then reload services as needed.

Startup
cd ~/nomad-hotspot
php agent.php

