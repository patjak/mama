<?php

define("MAMA_PATH", "/usr/share/mama");

require_once(MAMA_PATH."/src/machine.php");

// Here you can overwrite the default delays and timeouts
// Machine::$stop_timeout = 30;

// Include code for your power and relay controls here
require_once(MAMA_PATH."/src/mpower.php");
require_once(MAMA_PATH."/src/usbrly08.php");

// Configure power and relay controls here

// Power control devices
new MpowerCtlDev("mpower1", 6, "192.168.2.2");
new MpowerCtlDev("mpower2", 6, "192.168.2.3");
new MpowerCtlDev("mpower3", 6, "192.168.2.4");

// Relay control devices
new UsbrlyCtlDev("usbrly1", 8, "/dev/ttyACM0");
new UsbrlyCtlDev("usbrly2", 8, "/dev/ttyACM1");

?>
