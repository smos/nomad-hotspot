Hi There

Open Source Wifi Travel Router based on Raspberry Pi OS (lite) with web UI.

So this is my attempt at an Open Source Wifi Travel Router, most have limited features, or dropped support.
Other motivations include
- The camping site is still billing *per device* in 2022.
- You can strategically place the device so you don't need to perform yoga to access a webpage.
- Tunnel all the traffic over OpenVPN because the only wireless network they offer is not protected.

Hardware Requirements
- A Raspberry Pi 3B+ or later (for now, or older with 2 usb wifi adapters)
- A USB wifi adapter as to connect to Wifi networks (USB extension cables work a treat for those difficult locations, or insulation/racks etc. !)
	- The Edimax ac1200 USB3.0 mini has a Realtek 8822bu chipset and is fast, but needs a driver.
	- USB3 sticks with the Mediatek 76x2 chipset are supported natively, but not as fast
- The Onboard wifi adapter becomes the AP, as you are most likely close by, limits speed to ~70mbit. Works reliably.
	- Ofcourse you can use another USB adapter if that has reliable Wifi AP mode.

Installation
1. Write a SD card with Raspberry Pi OS lite
	- Configure SSH access
	- Configure User name
	- Setup with your current Wireless network for easy SSH access
2. Login into the Pi via SSH or using the console
	- sudo apt -y install git
	- git clone https://github.com/smos/nomad-hotspot.git
	- cd nomad-hotspot
	- ./bootstrap.sh
3. Connect to wireless network "Nomad-Hotspot" with password "OnTheRoadAgain".
	- You can change this later.

What it does
- Has a 5Ghz AP named Nomad-Hotspot
- Performs DHCP on eth0 and wlan1
- IP forwarding with outbound NAT on eth0, wlan1, wlan2 and tun0
- DHCP Server on wlan0 (onboard)
- Webserver configuration works for Wireless Client network list.
- Webserver does show OpenVPN config. Should be more-or-less ExpressVPN compatible.
- Has a refreshing UI with icons showing state

To get things going.

- In case you want to uninstall you can run the uninstall.sh script which should restore the previous configuration.

Changing the configuration manually
The agent monitors the eth0 and wlan1 connections, and the configuration files under the conf directory.
As you change the configuration files it will compare them and replace as needed and then reload services as needed.

Startup script included and installs systemd service file
You can have a look at the agent and webserver process by attaching their screen session nomad-hotspot or nomad-webserver. You can detach screen with crtl-a-d
