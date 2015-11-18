#!/bin/bash

#Check that the script is run as root
USER=`whoami`
if [ "$USER" != "root" ]; then
    echo "The script needs to be run as root. Exiting."
    exit 1
fi

#Check for docker. Exit if it's not installed.
DOCKER_INSTALLED=`which docker | wc -l`
if [ $DOCKER_INSTALLED -lt 1 ]; then
    echo "Docker not installed. Exiting"
    exit 1
fi

#Check for xmlstarlet. Install it if required.
XML_INSTALLED=`which xmlstarlet | wc -l`
if [ $XML_INSTALLED -lt 1 ]; then
    echo "xmlstarlet not installed. Installing now..."
    apt-get install -y xmlstarlet
fi

#Check for nsenter. Install it if required.
NSENTER_INSTALLED=`which nsenter | wc -l`
if [ $NSENTER_INSTALLED -lt 1 ]; then
    echo "nsenter not installed. Installing now..."
    docker run --rm -v /usr/local/bin:/target jpetazzo/nsenter
fi

#Copy files
cp docker-bridge.xml /etc/docker-bridge.xml
cp docker-logparser.php /usr/local/bin/docker-logparser.php
cp openmediavault-docker-br /etc/init.d/openmediavault-docker-br

#Set permissions
chmod 755 /usr/local/bin/docker-logparser.php
chmod 755 /etc/init.d/openmediavault-docker-br

#Autostart logparser
update-rc.d openmediavault-docker-br defaults

#Start the logparser
service openmediavault-docker-br start
