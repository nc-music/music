#!/usr/bin/env bash
#
# Nextcloud Music app
#
# This file is licensed under the Affero General Public License version 3 or
# later. See the COPYING file.
#
# @author Morris Jobke
# @author Gaurav Narula
# @author Pauli Järvinen <pauli.jarvinen@gmail.com>
# @copyright Morris Jobke 2015
# @copyright Gaurav Narula 2016
# @copyright Pauli Järvinen 2018 - 2026
#


# this downloads test data from github that is then moved to the data folder
# and then can be scanned by the Nextcloud filescanner

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <path to Nextcloud user dir>"
    exit 1
fi

url="https://github.com/paulijar/music/files/2364060/testcontent.zip"

if [ ! -d /tmp/downloadedData ];
then
    mkdir -p /tmp/downloadedData
fi

cd /tmp/downloadedData

name=`echo $url | cut -d "/" -f 8`
if [ ! -f "$name" ];
then
    echo "Downloading $name ..."
    wget $url -q --no-check-certificate -O $name
    if [ $? -ne 0 ];
    then
        sleep 5
        wget $url --no-check-certificate -O $name
        if [ $? -ne 0 ];
        then
            sleep 5
            wget $url --no-check-certificate -O $name
            if [ $? -ne 0 ];
            then
                exit 1
            fi
        fi
    fi
else
    echo "$name is already available"
fi

# extract
unzip -o $name -d .

# go back to the old folder
cd -

mkdir -p $1/files/music
cp -r /tmp/downloadedData/* $1/files/music
