#!/bin/bash
echo $1
echo $2
[[ $1 ]] || {
  echo "Usage: fix-profile.sh [path to callgrind file]"
  exit 1
}

/bin/egrep -v '^(cfl|positions|creator)' $1 | /bin/sed -e 's/^-//;1s/1/0.9.6/' > $2
