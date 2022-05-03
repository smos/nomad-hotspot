#!/bin/bash

#raspi-config automation from here https://forums.raspberrypi.com/viewtopic.php?t=21632
echo "Enable I2C for HyperPixel 4"
sudo raspi-config nonint do_i2c 1

echo "Install basic X neccessities"
sudo apt-get -y install xserver-xorg xinit lxde-core lxterminal lxappearance lightdm unclutter xdotool

echo "Enable Gui Boot with autologin"
sudo raspi-config nonint do_boot_behaviour B4

#sudo apt-get purge xscreensaver
if [ "`grep hyperpixel /boot/config.txt`" = "" ];then
	sudo sed -i 's/\[all\]/\[all\]\ndtoverlay=vc4-kms-dpi-hyperpixel4\n/g' /boot/config.txt
fi

if [ ! -d "/home/kiosk" ]; then
	sudo useradd -m kiosk
fi

echo "Change auto login user to kiosk"
sudo sed -i "s/autologin-user=$LOGNAME/autologin-user=kiosk/g" /etc/lightdm/lightdm.conf
sudo sed -i "s/--autologin $LOGNAME/--autologin kiosk/g" /etc/systemd/system/getty@tty1.service.d/autologin.conf

echo "Install Chromium Browser"
sudo apt -y install chromium-browser

echo "Copy chromium profile to Kiosk user"
sudo cp -a install/kiosk/chromium.tgz /home/kiosk/
sudo rm -rf /home/kiosk/chromium
sudo rm -rf /home/kiosk/chromium-default
sudo -i -u kiosk tar -xzf chromium.tgz
sudo -u kiosk mv /home/kiosk/chromium /home/kiosk/chromium-default

echo "Copy loop script to Kiosk user"
sudo cp -a install/kiosk/kiosk.sh /home/kiosk/kiosk.sh

echo "Copy lxsession autostart to Kiosk user"
sudo cp -a install/kiosk/autostart /home/kiosk/.config/lxsession/LXDE/autostart

echo "Copy xscreensaver config to Kiosk user"
sudo cp -a install/kiosk/xscreensaver /home/kiosk/.xscreensaver

echo "Install requirements for webscreensaver"
# https://github.com/lmartinking/webscreensaver
sudo apt -y install python3 python3-gi gir1.2-webkit2-4.0 gir1.2-gtk-3.0

echo "Copy webscreensaver to /usr/lib/xscreensaver"
sudo cp -a install/kiosk/webscreensaver /usr/local/bin/webscreensaver

sudo apt -y autoremove
