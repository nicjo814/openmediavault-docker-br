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
    $c_ary[$name] = array(
        "ip" => $ip);
}

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
    if (strcmp($name, "freepbx") === 0) {
        exec("$nsenter -t " . $pid[0] . " -n killall asterisk");
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
                echo $line;
            } //TODO Look at start/restart of containers
            flush();
        }
        $lastpos = ftell($f);
        fclose($f);
    }
}

/*
$file='/home/user/youfile.txt';
$lastpos = 0;
while (true) {
    usleep(300000); //0.3 s
    clearstatcache(false, $file);
    $len = filesize($file);
    if ($len < $lastpos) {
        //file deleted or reset
        $lastpos = $len;
    }
    elseif ($len > $lastpos) {
        $f = fopen($file, "rb");
        if ($f === false)
            die();
        fseek($f, $lastpos);
        while (!feof($f)) {
            $buffer = fread($f, 4096);
            echo $buffer;
            flush();
        }
        $lastpos = ftell($f);
        fclose($f);
    }
}
 */

/*
$file = new SplFileObject("/var/log/docker.log");
while (true) {
    if (!$file->eof()) {
        echo $file->fgets();
    } else {
        sleep(10);
    }
}
$file = null;
 */

?>
