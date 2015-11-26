# openmediavault-docker-br
This is a collection of scripts that will allow the creation of macvlan bridging to docker containers. In essence this makes it possible to add IP's from the same network as the host machine to the running containers, removing the need of NAT etc.

This is achieved by creating an insserv script that can be started automatically when the host starts, which in turns configures macvlan interfaces on both the host and configured containers. All configuration is done in an xml-file to make the scripts as dynamic as possible. Please note that the insserv scripts are strictly written for OpenMediaVault running on Debian Wheezy, but could easily be adopted to other init script versions.

Included are the following files:
* setup.sh  Installs the required files in their proper places. Also installs xmlstarlet and nsenter if they are not present on the host.
* docker-bridge.xml Holds all settings required by the scripts. Each parameter is explained below.
* docker-logparser.php  PHP script that continously reads the docker log to detect if a container has been started/stopped and then reassigns the proper IP to the container.
* openmediavault-docker-br Insserv script that starts the logparser and also configures bridging interface on the host.

Howto install:
* Clone this repository
* Edit the xml file with your parameters
* Run setup.sh as root
* Optionally run "update-rc.d openmediavault-docker-br defaults" to make changes persistent over host reboots
* Optionally run "service openmediavault-docker-br start" to start the service immediately

Parameters:
* logfile:  Location of the docker logfile.
* iface:  Name of the network interface to bridge with on the host system.
* gw: Gateway to use for the container. Should be the same as the host is using.
* bridgename: Name of the bridge to create on the host machine. Can be anything, but should be kept short.
* hostip: IP of the bridge to create on the host system. Should be an unused IP.
* container->name: Name of the container where to create a bridge.
* conatiner->ip: IP to configure on the container bridge. Should include network size(e.g. /24) of the network where the IP belongs.
* command->exec: Command to execute at start/stop of container.
* command->target: Should be either "host" or "container", which defines where the command should be executed.
* NOTE that if no command is to be executed the complete "command" tag must be deleted, but the start/stopcommands tags should be left.
