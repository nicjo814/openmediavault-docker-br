#!/usr/bin/php -q
<?php
$logfile="/var/log/docker.log";
$settingsfile="/etc/docker-bridge.xml";
$nsenter = "/usr/local/bin/nsenter";
$data = file_get_contents($settingsfile);
$xml = simplexml_load_string($data);

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
var_dump($c_ary);

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

$len = filesize($logfile);
$f = fopen($logfile, "rb");
if ($f === false)
    die();
$lastpos = $len;

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

?>
