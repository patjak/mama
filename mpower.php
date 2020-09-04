<?php
define("MPOWER_ID", "12345678901234567890123456789012");
define("MPOWER_USER", "patrik");
define("MPOWER_PASS", "ubnt");

class MpowerCtlDev extends CtlDev {
	private function login()
	{
		$ip = $this->ident;
		$id = MPOWER_ID;
		$user = MPOWER_USER;
		$pass = MPOWER_PASS;

		$ch = curl_init("http://".$ip."/login.cgi");
	
		$post = ['username' => $user,
		 	'password' => $pass];

		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		curl_setopt($ch, CURLOPT_COOKIE, "AIROS_SESSIONID=".$id);

		$resp = curl_exec($ch);
		curl_close($ch);

		return $resp;
	}

	private function logout()
	{
		$ip = $this->ident;
		$id = MPOWER_ID;

		$ch = curl_init("http://".$ip."/logout.cgi");
		curl_setopt($ch, CURLOPT_COOKIE, "AIROS_SESSIONID=".$id);
		curl_exec($ch);
		curl_close($ch);
	}

	private function get_json()
	{
		$ip = $this->ident;
		$id = MPOWER_ID;
		$user = MPOWER_USER;
		$pass = MPOWER_PASS;

		$ch = curl_init("http://".$ip."/sensors");

		if ($ch === false)
			return $ch;

		curl_setopt($ch, CURLOPT_COOKIE, "AIROS_SESSIONID=".$id);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$resp = curl_exec($ch);
		curl_close($ch);

		if ($resp == "") {
			$this->login();
			$resp = $this->get_json();
		}

		return $resp;
	}

	public function set_power($slot, $power)
	{
		$ip = $this->ident;
		$id = MPOWER_ID;

		$ch = curl_init("http://".$ip."/sensors/".$slot."&output=".$power);

		if ($ch === false)
			return $ch;

		$data = json_encode(array('power' => $power));

		curl_setopt($ch, CURLOPT_COOKIE, "AIROS_SESSIONID=".$id);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		$resp = curl_exec($ch);
		curl_close($ch);

		if ($resp == "") {
			$this->login();
			$resp = $this->set_power($slot, $power);
		}
	}

	public function get_sensors($slot)
	{
		// FIXME: Move this out of here
		$ip = $this->ident;
		$id = MPOWER_ID;
		$user = MPOWER_USER;
		$pass = MPOWER_PASS;

		$json = $this->get_json($ip, $id, $user, $pass);
		// $this->logout($ip, $id);

		$obj = json_decode($json, true);
		$sensor = $obj['sensors'][$slot - 1];

		$data = array(
			"output (bool)" => $sensor['output'],
			"power (W)" => $sensor['power'],
			"voltage (V)" => $sensor['voltage'],
			"current (I)" => $sensor['current']
		);

		return $data;
	}

	public function get_sensor($slot, $key)
	{
		$json = $this->get_json($ip, $id, $user, $pass);
		$obj = json_decode($json, true);
		$sensor = $obj['sensors'][$slot - 1];

		return $sensor[$key];
	}

	public function set_data($slot, $key, $value)
	{
		// curl -X PUT -d output=0 -b "AIROS_SESSIONID=01234567890123456789012345678901" 10.0.0.1/sensors/1
		// FIXME: Move this out of here
		$ip = $this->ident;
		$id = MPOWER_ID;
		$user = MPOWER_USER;
		$pass = MPOWER_PASS;

		$ch = curl_init("http://".$ip."/sensors/".$slot);

		if ($ch === false)
			return $ch;

		$post = [$key => $value];

		curl_setopt($ch, CURLOPT_COOKIE, "AIROS_SESSIONID=".$id);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$resp = curl_exec($ch);
		curl_close($ch);

		if ($resp == "") {
			$this->login();
			$resp = $this->set_data($slot, $key, $value);
		}

		return $resp;

	}
}

?>
