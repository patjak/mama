<?php

if (isset($_GET['MAC'])) {
	$mac = $_GET['MAC'];
} else {
	exec("ip -4 neigh | grep ".$client_ip, $mac);
	$mac = explode(" ", $mac[0]);
	if (count($mac) == 6)
		$mac = $mac[4];
	else
		$mac = "";

	if ($mac == "") {
		echo "#!ipxe\n";
		echo "echo No MAC specified";
		echo "shell";
		exit();
	}
}

$server_ip = $_SERVER['SERVER_ADDR'];
$client_ip = $_SERVER['REMOTE_ADDR'];

passthru("mama ipxe ".$mac." ".$server_ip." ".$client_ip);

?>
