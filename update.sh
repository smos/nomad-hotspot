#!/bin/bash
echo pulling from git repo, you need internet.
git fetch && git rebase origin


sudo apt install lldpd
sudo apt install wwhois

echo restarting agent
./killagent.sh
