#!/bin/bash

LYNX=`which lynx`
if [ $LYNX == "" ]; then
	sudo apt install lynx -qq
fi

lynx http://172.17.88.1:8000

