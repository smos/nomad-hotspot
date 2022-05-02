#!/bin/bash

if [ "$1" == 'on' ]; then
  tvservice -p;
 # fbset -depth 8;
 # fbset -depth 16;
 # chvt 6;
 # chvt 7;
  echo 'Switched Screen ON!'
fi

if [ "$1" == 'off' ]; then
  tvservice -o
  echo 'Switched Screen OFF!'
fi
