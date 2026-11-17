<?php

if (isset($_POST['check'])) {
	session_start();
	$prft = intval($_SESSION['config']['cable_profit']);
	$id = $_POST['cablename'];

	if ($id == 1) {
		die(json_encode($gotv));
	}elseif ($id == 2) {
		die(json_encode($dstv));
	}elseif ($id == 3) {
		die(json_encode($startime));
	}
}

if (isset($_POST['checkCable'])) {
	session_start();
	$iuc = $_POST['iuc'];
	$cable = $_POST['cable'];
	$sCable = '';
	if ($cable == 1) {
		$sCable = 'GOTV';
	}elseif ($cable == 2) {
		$sCable = 'DSTV';
	}elseif ($cable == 3) {
		$sCable = 'STARTIMES';
	}

	// $curl = curl_init();

	// curl_setopt_array($curl, array(
	// 	CURLOPT_URL => 'http://localhost/app.vtudata/api_admin/api/cableVerify',
	// 	CURLOPT_RETURNTRANSFER => true,
	// 	CURLOPT_ENCODING => '',
	// 	CURLOPT_MAXREDIRS => 10,
	// 	CURLOPT_TIMEOUT => 0,
	// 	CURLOPT_FOLLOWLOCATION => true,
	// 	CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
	// 	CURLOPT_CUSTOMREQUEST => 'POST',
	// 	CURLOPT_POSTFIELDS => array('decoder_name' => $sCable,'card_number' => $iuc),
	// 	CURLOPT_HTTPHEADER => array(
	// 		'Authorization: Token '.$_SESSION["config"]["api_token"]
	// 	),
	// ));

	// $response = curl_exec($curl);

	// curl_close($curl);



	$curl = curl_init();

	curl_setopt_array($curl, array(
		CURLOPT_URL => "https://$_SERVER[HTTP_HOST]/api_admin/api/cableVerify",
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => '',
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 0,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_POSTFIELDS => array('decoder_name' => $sCable,'card_number' => $iuc),
		CURLOPT_HTTPHEADER => array(
			'Authorization: Token e7209548ec58fcd8ee3d80731cf32837'
		),
	));

	$response = curl_exec($curl);

	curl_close($curl);




	$response = curl_exec($curl);

	curl_close($curl);
	$res = json_decode($response);
	if ($res->error) {
		die(json_encode([false, $res->desc]));
	}elseif($res->error == false){
		die(json_encode([true, $res->name]));
	}else{
		die(json_encode([false, 'Something Went Wrong']));
	}
}


if (isset($_POST['getcable'])) {
	session_start();
// card_number
// service_id
// decoder_name
	$prft = intval($_SESSION['config']['cable_profit']);
	
	$amt;
	include "../../conn.php";
	$email = $_SESSION["data"]["email"];
	$balDet = mysqli_fetch_assoc(mysqli_query($con, "SELECT bal, refBy FROM users WHERE email = '$email'"));
	$iuc = $_POST['iuc'];
	$cable = $_POST['cable'];
	$plan = $_POST['plan'];
	$name = $_POST['name'];
	$sCable;
	if ($cable == '1') {
		$sCable = 'GOTV';
	}elseif ($cable == '2') {
		$sCable = 'DSTV';
	}elseif ($cable == '3') {
		$sCable = 'STARTIMES';
	}
	$cbq = mysqli_query($con, "SELECT * FROM cables WHERE id = '$plan'");
	$cb = mysqli_fetch_assoc($cbq);
	if (mysqli_num_rows($cbq) != 1) {
		die('455');
	}
	$amt = $cb['amount'];
	$pl = $cb['plan_id'];
	$pl_n = $cb['name'];
	if (empty($amt)) {
		die('455');
	}
	if (intval($balDet['bal']) < intval($amt)) {
		die('223');
	}

	// $curl = curl_init();

	// curl_setopt_array($curl, array(
	// 	CURLOPT_URL => 'http://localhost/app.vtudata/api_admin/api/cableBuy',
	// 	CURLOPT_RETURNTRANSFER => true,
	// 	CURLOPT_ENCODING => '',
	// 	CURLOPT_MAXREDIRS => 10,
	// 	CURLOPT_TIMEOUT => 0,
	// 	CURLOPT_FOLLOWLOCATION => true,
	// 	CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
	// 	CURLOPT_CUSTOMREQUEST => 'POST',
	// 	CURLOPT_POSTFIELDS => array('card_number' => $iuc,'service_id' => $pl,'decoder_name' => $sCable),
	// 	CURLOPT_HTTPHEADER => array(
	// 		'Authorization: Token '.$_SESSION["config"]["api_token"]
	// 	),
	// ));

	// $response = curl_exec($curl);

	// curl_close($curl);

	$curl = curl_init();

	curl_setopt_array($curl, array(
		CURLOPT_URL => "https://$_SERVER[HTTP_HOST]/api_admin/api/cableBuy",
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => '',
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 0,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_POSTFIELDS => array('card_number' => $iuc,'service_id' => $pl,'decoder_name' => $sCable),
		CURLOPT_HTTPHEADER => array(
			'Authorization: Token e7209548ec58fcd8ee3d80731cf32837'
		),
	));

	$response = curl_exec($curl);

	curl_close($curl);

	// $response = '{"error": false, "desc": "SSSS"}';


	$res = json_decode($response);
	$t = time();
	$ref = md5($t);
	$username = $username = $_SESSION["data"]["username"];
	if (isset($res->error) && $res->error || !isset($res->error)) {
		die(isset($res->error) ? $res->desc : '5557678');
	}elseif(isset($res->error) && $res->error == false){
		$amt = strval(intval($amt));
		$userT = mysqli_fetch_assoc(mysqli_query($con, "SELECT type FROM users WHERE email = '$email'"));
		$cable_c = mysqli_fetch_array(mysqli_query($con, "SELECT * FROM cable_config"));
		$usertype = $userT['type'];
		$r = $cable_c[$usertype];
		$new_amt = 0;
		if ($cable_c['method'] == 'percentage') {
			$r = intval($r);
			$new_amt = ($r/100) * intval($amt);
		}elseif($cable_c['method'] == 'discount'){
			$r = intval($r);
			$new_amt = 0 - $r;
		}
		
		// $profit = intval($prft);
		// $oldPro = mysqli_fetch_assoc(mysqli_query($con, "SELECT profit FROM config"));
		// $newPro = strval(intval($oldPro['profit']) + $profit);
		$oldBal = strval($balDet['bal']);
		$newBal = strval(intval($oldBal) -(intval($amt) - $new_amt));
		$amt_paid = strval(intval($amt) - $new_amt);
		$cable_det = json_encode(array('cable' => $sCable, 'card' => $iuc, 'plan' => $pl_n, 'amount' => $amt, 'name' => $name, 'date' => $current_date, 'status' => 'success'));
		mysqli_query($con, "UPDATE users SET bal = '$newBal' WHERE email = '$email'");
		mysqli_query($con, "INSERT INTO transactions (type, t_desc, ref, status, amount, oldBal, newBal, email, username, date_time, amount_paid) VALUES ('Cable Payment',  '$cable_det', '$ref', 'success', '$amt', '$oldBal', '$newBal', '$email', '$username', '$current_date', '$amt_paid')");
		if(!empty($balDet['refBy'])){
            $ref_username = $balDet['refBy'];
            $refQ = mysqli_query($con, "SELECT commission FROM users WHERE username = '$ref_username'");
            if(mysqli_num_rows($refQ) == 1){
                $ref_data = mysqli_fetch_assoc($refQ);
                $ref_bunus = $ref_data['commission'];
                $new_ref_bonus = strval(floatval($ref_data['commission']) + 1);
                mysqli_query($con, "UPDATE users SET commission = '$new_ref_bonus' WHERE username = '$ref_username'");
                mysqli_query($con, "INSERT INTO transactions (type, ref, status, amount, oldBal, newBal, username, date_time) VALUES ('REFERRAL COMMISSION', '$ref', 'success', '1', '$ref_bunus', '$new_ref_bonus', '$ref_username', '$current_date')");
            }
        }
		// mysqli_query($con, "UPDATE config SET profit = '$newPro'");
		die('200');
	}
	
}

































?>