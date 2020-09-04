<?php

class UsbrlyCtlDev extends CtlDev {

	public function push($slot, $sec)
	{
		$str = "usb-rly08 ".$this->ident." ".$slot." ".$sec * 1000;
		shell_exec($str);
	}
}

?>
