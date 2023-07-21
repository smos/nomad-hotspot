#!/bin/bash
echo pulling from git repo, you need internet.
git fetch && git rebase origin


sudo apt -y install lldpd
sudo apt -y install whois

echo "Enable PCIe tune, thnx Jeff Geerling"
sudo sed -i 's/fsck.repair=yes rootwait/pci=pcie_bus_perf rootwait/g' /boot/cmdline.txt
echo "Requires a reboot"

echo restarting agent
./killagent.sh
