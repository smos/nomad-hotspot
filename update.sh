#!/bin/bash
echo pulling from git repo, you need internet.
git fetch && git rebase origin


sudo apt -qq install lldpd
sudo apt -qq install whois
sudo apt -qq install dnsdiag
sudo apt -qq install ieee-data

# starting with Debian 12 we are moving to Network manager
#sudo apt remove dhcpd

echo "Enable PCIe tune, thnx Jeff Geerling"
sudo sed -i 's/fsck.repair=yes rootwait/fsck.repair=yes pci=pcie_bus_perf rootwait/g' /boot/cmdline.txt
echo "Requires a reboot"

echo restarting agent
./killagent.sh
