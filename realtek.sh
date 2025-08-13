#!/bin/bash
DKMS=`which dkms`

if [ "$DKMS" == "" ]; then
	sudo apt -y update
	sudo apt -qq install linux-headers bc build-essential dkms 
fi

PI64=`uname -m`

#echo 1 8812au-20210629
echo 2 8821au-20210708
echo 3 8814au
#echo 4 8821cu-20210118
echo 5 88x2bu-20210702
#echo 6 rtl8852bu
echo 7 rtl8852cu

read option

#if [ "$option" = "1" ]; then
#	GITREPO="8812au-20210629"
#fi
if [ "$option" = "2" ]; then
	GITREPO="8821au-20210708"
fi
if [ "$option" = "3" ]; then
	GITREPO="8814au"
fi
#if [ "$option" = "4" ]; then
#	GITREPO="8821cu-20210118"
#fi
if [ "$option" = "5" ]; then
	GITREPO="88x2bu-20210702"
fi
#if [ "$option" = "6" ]; then
#	GITREPO="rtl8852bu"
#fi
if [ "$option" = "7" ]; then
	GITREPO="rtl8852cu-20240510"
fi

if [ "$GITREPO" != "" ]; then
	sudo git clone https://github.com/morrownr/"$GITREPO".git /usr/src/"$GITREPO"
	cd /usr/src/"$GITREPO"
	git pull

	sudo ./install-driver.sh NoPrompt
	sleep 5
	sudo modprobe `echo $GITREPO |  awk -F\- '{print $1}'`
fi
