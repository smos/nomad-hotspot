Hi There

Open Source Wifi Travel Router based on Raspberry Pi OS (lite) with web UI.
WebUI demo on YouTube
https://youtu.be/EVa4HDayCnA

So this is my attempt at a basic Open Source Wifi Travel Router, to scratch my personal itch.

Other motivations include
- The camping site is still billing *per device* in 2022.
- You can strategically place the device so you don't need to perform yoga to access a webpage.
- Tunnel all the traffic over OpenVPN because the only wireless network they offer is not protected.

Hardware Requirements
- A Raspberry Pi 3B+ or later (for now, or older with 2 usb wifi adapters, needs AP mode support)
- A USB wifi adapter as to connect to Wifi networks (USB extension cables work a treat for those difficult locations, or insulation/racks etc. !)
	- The Edimax ac1200 USB3.0 mini has a Realtek 8822bu chipset and is fast, but needs a driver.
		There is a script to install the dkms driver.
	- USB3 sticks with the Mediatek 76x2 chipset are supported natively, but not as fast.
		Also, cheap
- The Onboard wifi adapter becomes the AP, as you are most likely close by the Pi. Limits speed to ~70mbit. Works reliably.
	- Ofcourse you can use another USB adapter if that has reliable Wifi AP mode, or external antenna support.

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
	- Browse to http://172.17.88.1:8000
	- Click the Wireless icon to connect to a wireless network

Optional

4. Setup a Kiosk screen on the HyperPixel4 if you have it
	- run "./setupkiosk.sh" and it should set everything up. Might need a 2nd run after a reboot if the browser doesn't launch. The user profile was probably not complete at that point in time.
	- See the install/kiosk directory for some pointers
	- Raspberry case here: https://www.thingiverse.com/thing:5383201 which also shows screensaver UI
	- Has a on screen keyboard for basic data entry to connect a wireless network, otherwise use browser from phone.


What it does
- Has a 5Ghz AP named Nomad-Hotspot
- Performs DHCP on eth0 and wlan1
- IP forwarding with outbound NAT on eth0, wlan1, wlan2 and tun0
- DHCP Server on wlan0 (onboard)
- Webserver configuration works for Wireless Client network list.
- Webserver does show OpenVPN config. Should be more-or-less ExpressVPN compatible.
- Has a refreshing UI with icons showing state

- In case you want to uninstall you can run the uninstall.sh script which should restore the previous configuration.

Changing the configuration manually
The agent monitors the eth0 and wlan1 connections, and the configuration files under the conf directory.
As you change the configuration files it will compare them and replace as needed and then reload services as needed.

Startup script included and installs systemd service file
You can have a look at the agent and webserver process by attaching their screen session nomad-hotspot or nomad-webserver. You can detach screen with crtl-a-d.
Added a procmon.sh script for easy access on limited terminals like tablets. Logs now also accessible via webui.

The Kiosk Part

The kiosk profile launches a chromium-browser in a loop and has a virtual keyboard that comes on when requested. Or it should.
That way you can connect to a protected wifi network if you also have the Hyperpixel screen.
You can go into the kiosk profile using a "sudo -u kiosk -i"
You can "killall kiosk.sh" to kill the loop
You can "killall chromium-browser" to stop the browser
Set the environment with "DISPLAY=:0" and "export DISPLAY"
If you install x11vnc, you can launch x11vnc under the kiosk user to get the display.

Case for the Pi and Screen
I used the following case from Thingiverse as the source. https://www.thingiverse.com/thing:4095591 and
made a few changes so it can have a slide cover for the display for transport and night dimming reasons.
https://www.thingiverse.com/thing:5383201
Turns out the cover doesn't quite slide on the back with the power plug in. Oh well, next version I guess.
