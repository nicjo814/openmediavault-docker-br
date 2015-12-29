#!/usr/bin/php -q
<?php
$settingsfile="/etc/docker-bridge.xml";
$oldsettingsfile="/tmp/docker-bridge.xml";
$nsenter = "/usr/local/bin/nsenter";

function setip($name, $settings)
{
    $iface = $settings["iface"];
    $gw = $settings["gw"];
    $c_ary = $settings["c_ary"];
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
        if (strcmp($command["target"], "host") === 0) {
            exec($command["exec"]);
        } else {
            exec("docker exec $name " . $command["exec"]);
        }
    }
    return 0;
}

function delip($name, $settings)
{
    $iface = $settings["iface"];
    $gw = $settings["gw"];
    $c_ary = $settings["c_ary"];
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
        if (strcmp($command["target"], "host") === 0) {
            exec($command["exec"]);
        } else {
            exec("docker exec $name ". $command["exec"]);
        }
    }
    return 0;
}

function get_settings($settingsfile)
{
    $settings = array();
    $data = file_get_contents($settingsfile);
    $xml = simplexml_load_string($data);
    $settings["logfile"] = $xml->logfile;
    $settings["gw"] = $xml->gw;
    $settings["iface"] = $xml->iface;
    $c_ary = array();
    foreach ($xml->containers->container as $container) {
        $name = (string)$container->name;
        $ip = (string)$container->ip;
        $startcmd = array();
        $stopcmd = array();
        if (count((array)$container->startcommands) > 0) {
            foreach ($container->startcommands->command as $command) {
                if ((strcmp((string)$command->target, "host") === 0) || (strcmp((string)$command->target, "container") === 0)) {
                    if (!(strcmp((string)$command->exec, "") === 0)) {
                        array_push($startcmd, array(
                            "exec" => (string)$command->exec,
                            "target" => (string)$command->target
                        ));
                    }
                }
            }
        }
        if (count((array)$container->stopcommands) > 0) {
            foreach ($container->stopcommands->command as $command) {
                if ((strcmp((string)$command->target, "host") === 0) || (strcmp((string)$command->target, "container") === 0)) {
                    if (!(strcmp((string)$command->exec, "") === 0)) {
                        array_push($stopcmd, array(
                            "exec" => (string)$command->exec,
                            "target" => (string)$command->target
                        ));
                    }
                }
            }
        }
        $c_ary[$name] = array(
            "ip" => $ip,
            "startcmd" => $startcmd,
            "stopcmd" => $stopcmd
        );
    }
    $settings["c_ary"] = $c_ary;
    return($settings);
}

function read_log($settings)
{
    $logfile = $settings["logfile"];
    $c_ary = $settings["c_ary"];

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
                        setip($key, $settings);
                    }
                } elseif (preg_match('/^.*\/v[\d]{1}\.[\d]{2}\/containers\/(.*)\/start.*$/', $line, $matches)) {
                    foreach ($c_ary as $key => $val) {
                        if (strcmp($key, $matches[1]) === 0) {
                            setip($key, $settings);
                            break;
                        }
                    }
                } elseif (preg_match('/^.*\/v[\d]{1}\.[\d]{2}\/containers\/(.*)\/restart\?t\=([\d]+).*$/', $line, $matches)) {
                    foreach ($c_ary as $key => $val) {
						if (strcmp($key, $matches[1]) === 0) {
							$t_o = (int)$matches[2]+5;
							sleep($t_o);
                            setip($key, $settings);
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
        $settings = get_settings($settingsfile);
        read_log($settings);
    } elseif (strcmp($argv[1], "stop") === 0) {
        //Get parameters from oldsettingsfile
        $settings = get_settings($oldsettingsfile);
        foreach ($settings["c_ary"] as $name => $val) {
            delip($name, $settings);
        }
        exit(0);
    } elseif (strcmp($argv[1], "restart") === 0) {
        //Get parameters from oldsettingsfile
        $settings = get_settings($oldsettingsfile);
        foreach ($settings["c_ary"] as $name => $val) {
            delip($name, $settings);
        }

        //Get parameters from settingsfile
        $settings = get_settings($settingsfile);
        foreach ($settings["c_ary"] as $name => $val) {
            setip($name, $settings);
        }
        read_log($settings);
    }
}

?>
