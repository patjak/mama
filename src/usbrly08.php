<?php

require_once(MAMA_PATH."/src/ctldev.php");

class UsbrlyCtlDev extends CtlDev {

	public function push($slot, $sec)
	{
		$str = "usb-rly08 ".$this->private_data." ".$slot." ".$sec * 1000;
		shell_exec($str);
	}
}

?>
