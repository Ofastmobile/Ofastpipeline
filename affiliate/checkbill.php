<?php
// error_reporting(0);
if (isset($_POST['checkbill'])) {
	session_start();
	$mn = $_POST['meter_num'];
	$d = strtolower($_POST['disco']);
	$mt = strtoupper($_POST['meter_type']);
	$curl = curl_init();
// service_id
// type
// meter_number
	curl_setopt_array($curl, array(
		CURLOPT_URL => "https://$_SERVER[HTTP_HOST]/api_admin/api/electricityVerify",
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => '',
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 0,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_POSTFIELDS => array('service_id' => $d,'meter_number' => $mn, 'type' => $mt),
		CURLOPT_HTTPHEADER => array(
			'Authorization: Token e7209548ec58fcd8ee3d80731cf32837'
// 			.$_SESSION["config"]["api_token"]
		),
	));

	$response = curl_exec($curl);

	curl_close($curl);
	$res = json_decode($response);

	$out = [];
	if ($res->error == false) {
		array_push($out, true);
		array_push($out, $res->name);
		array_push($out, $res->address);
	}else{
		array_push($out, false);
		array_push($out, $res->desc);
	}
	die(json_encode($out));
}

if (isset($_POST['tobill'])) {
	session_start();
	$mn = $_POST['meter_num'];
	$mt = strtoupper($_POST['meter_type']);
	$amt  = $_POST['amount'];
	$name  = $_POST['mname'];
	$addrs  = $_POST['addrs'];
	$phone  = $_POST['phone'];
	// $prft = $_SESSION['config']['bill_profit'];
	$amount = intval($amt);
	$d_name = $_POST['disc'];
	// echo $d_name;
	if (empty(trim($mn)) || empty(trim($mt)) || empty(trim($amt))) {
		die('400');
	}
	if ($amount < 50) {
		die('423');
	}
	
	include "../../conn.php";
	$email = $_SESSION["data"]["email"];
	$balDet = mysqli_fetch_assoc(mysqli_query($con, "SELECT bal FROM users WHERE email = '$email'"));

	$bal = $balDet['bal'];
	if (intval($bal) < intval($amt)) {
		die('004');
	}else{
		$meterType = '';
		switch ($mt) {
			case 'PREPAID':
			$meterType = "Prepaid";
			break;
			case 'POSTPAID':
			$meterType = "Postpaid";
			break;
			default:
			$meterType = '';
			break;
		}
		if ($meterType == '') {
			die('332');
		}
		

		//Authorization
// phone_number
// meter_number
// service_id
// amount
// type
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => "https://$_SERVER[HTTP_HOST]/api_admin/api/electricityBuy",
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => array('meter_number' => $mn,'service_id' => $d_name,'amount' => $amount,'phone_number' => $phone, 'type' => $mt),
			CURLOPT_HTTPHEADER => array(
				'Authorization: Token e7209548ec58fcd8ee3d80731cf32837'
				// .$_SESSION["config"]["api_token"]
			),
		));

		$response = curl_exec($curl);

		curl_close($curl);


// $response = '{"error": false, "token": "23445678799998765"}';
		$t = time();
		$ref = md5($t);
		$res = json_decode($response);
		$username = $username = $_SESSION["data"]["username"];

		if (isset($res->error) && $res->error == false ) {
			$amt = strval($amount);
			$userT = mysqli_fetch_assoc(mysqli_query($con, "SELECT type, refBy FROM users WHERE email = '$email'"));
			$bill_c = mysqli_fetch_array(mysqli_query($con, "SELECT * FROM bill_config"));
			$usertype = $userT['type'];
			$r = $bill_c[$usertype];
			$new_amt = 0;
			if ($bill_c['method'] == 'percentage') {
				$r = intval($r);
				$new_amt = ($r/100) * intval($amount);
			}elseif($bill_c['method'] == 'discount'){
				$r = intval($r);
				$new_amt = 0 - $r;
			}
			// $profit = intval($prft);
			// $oldPro = mysqli_fetch_assoc(mysqli_query($con, "SELECT profit FROM config"));
			// $newPro = strval(intval($oldPro['profit']) + $profit);
			$oldBal = strval($balDet['bal']);
			$newBal = strval(intval($oldBal) - (intval($amt) - $new_amt));
			$tp = "Bill Payment ({$res->token})";

			$amt_paid = strval(intval($amt) - $new_amt);

			$bill_det = json_encode(array('name' => $name, 'disco' => strtoupper($d_name), 'meter' => $mn, 'amount' => $amt, 'type' => $mt, 'phone' => $phone, 'date' => $current_date, 'token' => $res->token, 'status' => 'success', 'address' => $addrs));
			mysqli_query($con, "UPDATE users SET bal = '$newBal' WHERE email = '$email'");
			mysqli_query($con, "INSERT INTO transactions (type, t_desc, ref, status, amount, oldBal, newBal, email, username, date_time, amount_paid) VALUES ('$tp', '$bill_det', '$ref', 'success', '$amt', '$oldBal', '$newBal', '$email', '$username', '$current_date', '$amt_paid')");
			// mysqli_query($con, "UPDATE config SET profit = '$newPro'");
			if(!empty($userT['refBy'])){
            $ref_username = $userT['refBy'];
            $refQ = mysqli_query($con, "SELECT commission FROM users WHERE username = '$ref_username'");
            if(mysqli_num_rows($refQ) == 1){
                $ref_data = mysqli_fetch_assoc($refQ);
                $ref_bunus = $ref_data['commission'];
                $new_ref_bonus = strval(floatval($ref_data['commission']) + 1);
                mysqli_query($con, "UPDATE users SET commission = '$new_ref_bonus' WHERE username = '$ref_username'");
                mysqli_query($con, "INSERT INTO transactions (type, ref, status, amount, oldBal, newBal, username, date_time) VALUES ('REFERRAL COMMISSION', '$ref', 'success', '1', '$ref_bunus', '$new_ref_bonus', '$ref_username', '$current_date')");
            }
        }
			die(json_encode([200, $res->token]));
		}else{
			die($res->desc);
			
		}

	}

}
?>