#!/bin/bash

sudo tcpdump -i wlan1 port 67 or port 68 -e -n -vv
