#!/bin/sh
if [ -z $1 ]
then
  echo "Usage: package.sh <tag>"
else
	mkdir package_tmp
	rm webgrind-$1.zip
	cd package_tmp
	git clone https://github.com/jokkedk/webgrind.git
	cd webgrind
	git checkout $1
	cd ..
	git clone https://github.com/jokkedk/webgrind.wiki.git webgrind/docs
	rm -rf webgrind/.git webgrind/docs/.git webgrind/package.sh webgrind/bin/.gitignore
	zip -r ../webgrind-$1.zip webgrind
	cd ..
	rm -rf package_tmp
fi
