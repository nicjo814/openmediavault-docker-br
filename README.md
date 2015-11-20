# openmediavault-docker-br
This is a collection of scripts that will allow the creation of macvlan bridging to docker containers. In essence this makes it possible to add IP's from the same network as the host machine to the running containers, removing the need of NAT etc.

This is achieved by creating an insserv script that if desired can be started automatically when the host starts, which in turns configures macvlan interfaces on both the host and in desired containers. All configuration is done in an xml-file to make the scripts as dynamic as possible. Please note that the insserv scripts are strictly written for Debian Wheezy, but could easily be adopted to other init script versions.

Included are the following files:
* setup.sh  Installs the required files in their proper places. Also installs xmlstarlet and nsenter if they are not present on the host.
* docker-bridge.xml Holds all settings required by the scripts. Each parameter is explained below.
* docker-logparser.php  PHP script that continously reads the docker log to detect if a container has been started/stopped and then reassigns the proper IP to the container.
* openmediavault-docker-br Insserv script that starts the logparser and also configures bridging interface on the host.
