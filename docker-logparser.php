#!/usr/bin/php -q
<?php
$settingsfile="/etc/docker-bridge.xml";
$oldsettingsfile="/tmp/docker-bridge.xml";
$nsenter = "/usr/local/bin/nsenter";

function setip($name)
{
    global $iface;
    global $gw;
    global $c_ary;
    global $nsenter;

    exec("docker inspect --format '{{ .State.Pid }}' $name", $pid, $res);
    if ($res === 1) {
        return 0;
    }
    $ip = $c_ary[$name]["ip"];
    exec("ip link add " . $name . "-0 link " . $iface . " type macvlan mode bridge");
    exec("ip link set netns " . $pid[0] . " " . $name . "-0");
    exec("$nsenter -t " . $pid[0] . " -n ip link set " . $name . "-0 up");
    exec("$nsenter -t " . $pid[0] . " -n ip route del default");
    exec("$nsenter -t " . $pid[0] . " -n ip addr add " . $ip . " dev " . $name . "-0");
    exec("$nsenter -t " . $pid[0] . " -n ip route add default via " . $gw . " dev " . $name . "-0");
    foreach ($c_ary[$name]["startcmd"] as $command) {
        exec($command);
    }
    return 0;
}

function delip($name)
{
    global $iface;
    global $gw;
    global $c_ary;
    global $nsenter;

    exec("docker inspect --format '{{ .State.Pid }}' $name", $pid, $res);
    if ($res === 1) {
        return 0;
    }
    //First get the IP of eth0 in the Docker container
    exec("$nsenter -t " . $pid[0] . " -n ifconfig eth0 | grep \"inet addr\" | awk -F: '{print $2}' | awk '{print $1}'", $oldip);
    preg_match('/^([\d]+\.[\d]+\.[\d]+\.)[\d+]+$/', $oldip[0], $matches);
    $oldgw = $matches[1] . "1";

    //Remove the previously configured IP and reset the default gateway
    $ip = $c_ary[$name]["ip"];    
    exec("$nsenter -t " . $pid[0] . " -n ip addr del " . $ip . " dev " . $name . "-0");
    exec("$nsenter -t " . $pid[0] . " -n ip route del default");
    exec("$nsenter -t " . $pid[0] . " -n ip link set " . $name . "-0 down");
    exec("$nsenter -t " . $pid[0] . " -n ip link del " . $name . "-0");
    exec("$nsenter -t " . $pid[0] . " -n ip route add default via " . $oldgw . " dev eth0");
    foreach ($c_ary[$name]["stopcmd"] as $command) {
        exec($command);
    }
    return 0;
}

function read_log()
{
    global $logfile;
    global $c_ary;

    //Make sure we skip to the end of the logfile before starting to read from it
    //to avoid reading "old" data.
    $len = filesize($logfile);
    $f = fopen($logfile, "rb");
    if ($f === false)
        die();
    $lastpos = $len;

    //Start reading from the logfile
    while (true) {
        usleep(300000); //0.3 s
        clearstatcache(false, $logfile);
        $len = filesize($logfile);
        if ($len < $lastpos) {
            //file deleted or reset
            $lastpos = $len;
        } elseif ($len > $lastpos) {
            $f = fopen($logfile, "rb");
            if ($f === false)
                die();
            fseek($f, $lastpos);
            while (!feof($f)) {
                $line = fgets($f);
                if (preg_match('/^.*Loading containers: done.*$/', $line)) {
                    foreach ($c_ary as $key => $val) {
                        setip($key);
                    }
                } elseif (preg_match('/^.*\/v[\d]{1}\.[\d]{2}\/containers\/(.*)\/start.*$/', $line, $matches)) {
                    foreach ($c_ary as $key => $val) {
                        if (strcmp($key, $matches[1]) === 0) {
                            setip($key);
                            break;
                        }
                    }
                } elseif (preg_match('/^.*\/v[\d]{1}\.[\d]{2}\/containers\/(.*)\/restart.*$/', $line, $matches)) {
                    foreach ($c_ary as $key => $val) {
                        if (strcmp($key, $matches[1]) === 0) {
                            setip($key);
                            break;
                        }
                    }
                }
                flush();
            }
            $lastpos = ftell($f);
            fclose($f);
        }
    }
}

if (isset($argv[1])) {
    if (strcmp($argv[1], "start") === 0) {
        //Get parameters from settingsfile
        $data = file_get_contents($settingsfile);
        $xml = simplexml_load_string($data);
        $logfile=$xml->logfile;
        $gw = $xml->gw;
        $iface = $xml->iface;
        $c_ary = array();
        foreach ($xml->containers->container as $container) {
            $name = (string)$container->name;
            $ip = (string)$container->ip;
            $startcmd = array();
            $stopcmd = array();
            if (count((array)$container->startcommands) > 0) {
                foreach ($container->startcommands->command as $command) {
                    if (!(strcmp((string)$command, "") === 0)) {
                        array_push($startcmd, (string)$command);
                    }
                }
            }
            if (count((array)$container->stopcommands) > 0) {
                foreach ($container->stopcommands->command as $command) {
                    if (!(strcmp((string)$command, "") === 0)) {
                        array_push($stopcmd, (string)$command);
                    }
                }
            }
            $c_ary[$name] = array(
                "ip" => $ip,
                "startcmd" => $startcmd,
                "stopcmd" => $stopcmd
            );
        }
        read_log();
    } elseif (strcmp($argv[1], "stop") === 0) {
        //Get parameters from oldsettingsfile
        $data = file_get_contents($oldsettingsfile);
        $xml = simplexml_load_string($data);
        $logfile=$xml->logfile;
        $gw = $xml->gw;
        $iface = $xml->iface;
        $c_ary = array();
        foreach ($xml->containers->container as $container) {
            $name = (string)$container->name;
            $ip = (string)$container->ip;
            $startcmd = array();
            $stopcmd = array();
            if (count((array)$container->startcommands) > 0) {
                foreach ($container->startcommands->command as $command) {
                    if (!(strcmp((string)$command, "") === 0)) {
                        array_push($startcmd, (string)$command);
                    }
                }
            }
            if (count((array)$container->stopcommands) > 0) {
                foreach ($container->stopcommands->command as $command) {
                    if (!(strcmp((string)$command, "") === 0)) {
                        array_push($stopcmd, (string)$command);
                    }
                }
            }
            $c_ary[$name] = array(
                "ip" => $ip,
                "startcmd" => $startcmd,
                "stopcmd" => $stopcmd
            );
        }
        foreach ($c_ary as $name => $val) {
            delip($name);
        }
        exit(0);
    } elseif (strcmp($argv[1], "restart") === 0) {
        //Get parameters from oldsettingsfile
        $data = file_get_contents($oldsettingsfile);
        $xml = simplexml_load_string($data);
        $logfile=$xml->logfile;
        $gw = $xml->gw;
        $iface = $xml->iface;
        $c_ary = array();
        foreach ($xml->containers->container as $container) {
            $name = (string)$container->name;
            $ip = (string)$container->ip;
            $startcmd = array();
            $stopcmd = array();
            if (count((array)$container->startcommands) > 0) {
                foreach ($container->startcommands->command as $command) {
                    if (!(strcmp((string)$command, "") === 0)) {
                        array_push($startcmd, (string)$command);
                    }
                }
            }
            if (count((array)$container->stopcommands) > 0) {
                foreach ($container->stopcommands->command as $command) {
                    if (!(strcmp((string)$command, "") === 0)) {
                        array_push($stopcmd, (string)$command);
                    }
                }
            }
            $c_ary[$name] = array(
                "ip" => $ip,
                "startcmd" => $startcmd,
                "stopcmd" => $stopcmd
            );
        }
        foreach ($c_ary as $name => $val) {
            delip($name);
        }
    
        //Get parameters from settingsfile
        $data = file_get_contents($settingsfile);
        $xml = simplexml_load_string($data);
        $logfile=$xml->logfile;
        $gw = $xml->gw;
        $iface = $xml->iface;
        $c_ary = array();
        foreach ($xml->containers->container as $container) {
            $name = (string)$container->name;
            $ip = (string)$container->ip;
            $startcmd = array();
            $stopcmd = array();
            if (count((array)$container->startcommands) > 0) {
                foreach ($container->startcommands->command as $command) {
                    if (!(strcmp((string)$command, "") === 0)) {
                        array_push($startcmd, (string)$command);
                    }
                }
            }
            if (count((array)$container->stopcommands) > 0) {
                foreach ($container->stopcommands->command as $command) {
                    if (!(strcmp((string)$command, "") === 0)) {
                        array_push($stopcmd, (string)$command);
                    }
                }
            }
            $c_ary[$name] = array(
                "ip" => $ip,
                "startcmd" => $startcmd,
                "stopcmd" => $stopcmd
            );
        }
        foreach ($c_ary as $name => $val) {
            setip($name);
        }
        read_log();
    }
}

?>
