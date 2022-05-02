#!/bin/bash

#raspi-config automation from here https://forums.raspberrypi.com/viewtopic.php?t=21632
echo "Enable I2C for HyperPixel 4"
sudo raspi-config nonint do_i2c 1

echo "Install basic X neccessities"
sudo apt-get install xserver-xorg xinit lxde-core lxterminal lxappearance lightdm 

echo "Enable Gui Boot with autologin"
sudo raspi-config nonint do_boot_behaviour B4

#sudo apt-get purge xscreensaver


sudo sed -i 's/\[all\]/\[all\]\ndtoverlay=vc4-kms-dpi-hyperpixel4\n/g' /boot/config.txt

sudo useradd -m kiosk

echo "Change auto login user to kiosk"
sudo sed -i "s/autologin-user=$LOGNAME/autologin-user=kiosk/g" /etc/lightdm/lightdm.conf
sudo sed -i "s/--autologin $LOGNAME/--autologin kiosk/g" /etc/systemd/system/getty@tty1.service.d/autologin.conf
