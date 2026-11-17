<?php 
session_start();
if (!isset($_SESSION['username'])) {
	header('location:logout');
}
if (isset($_POST['topUp'])) {
	include '../../conn.php';
	$phone = filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['phone']))) ), FILTER_SANITIZE_STRIPPED);
	$data_plan = filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['data_plan'])))), FILTER_SANITIZE_STRIPPED);
	$net = filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['network']))) ), FILTER_SANITIZE_STRIPPED);
	$data_type = filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['topUp']))) ), FILTER_SANITIZE_STRIPPED);

	if (empty($phone) || empty($net) || empty($data_plan) || empty($data_type)) {
		die('404');
	}
	$real_pin = $_SESSION['data']['pin'];
	$pin = mysqli_real_escape_string($con, $_POST['pin']);
	if ($real_pin != $pin) {
		die('329');
	}
	$email = $_SESSION['data']['email'];
	
	$data = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM users WHERE email = '$email'"));
	$usertype = $data['type'].'_price';
	$net_id = 0;
	if (strtolower($net) == 'mtn') {
		$net_id = 1;
	}elseif(strtolower($net) == 'airtel'){
		$net_id = 2;
	}elseif(strtolower($net) == 'glo'){
		$net_id = 3;
	}elseif(strtolower($net) == '9mobile'){
		$net_id = 4;
	}else{
		die('4041');
	}
	if ($net_id == 0) {
		die('4042');
	}
	// die($data_plan);
	$pq = mysqli_query($con, "SELECT * FROM data_plans WHERE id = '$data_plan'");
	if (mysqli_num_rows($pq) != 1) {
		die('4043');
	}
	$datPlan = mysqli_fetch_assoc($pq);


	$cost = '';

	$amount = $datPlan[$usertype];

	$data_vol = $datPlan['plan_size'].$datPlan['plan_volume'];
	
	$data_id = $datPlan['id'];

	$oldPro = mysqli_fetch_assoc(mysqli_query($con, "SELECT profit FROM config"));

	$data_type_new = $datPlan['plan_type'];

	if ($data_type == 'sme') {
		$data_type_new = 'SME';
	}elseif ($data_type == 'cg') {
		$data_type_new = 'CORPORATE-GIFTING';
		
	}elseif ($data_type == 'gf') {
		$data_type_new = 'GIFTING';
	}
	

	$new_net = strtoupper($net);
	$data_desc = "${new_net} ${data_type_new} DATA of ${data_vol}";
	if ($data_id == 0) {
		die('4044');
	}else{
		$username = $_SESSION['data']['username'];
		$email = $_SESSION['data']['email'];
		$t = time();
		$ref = md5($t.$email);
		$userBal = mysqli_fetch_assoc(mysqli_query($con, "SELECT bal FROM users WHERE email = '$email'"));
		$config = mysqli_fetch_assoc(mysqli_query($con, "SELECT day_spent_amount FROM config"));
		$daySpentAmount = $config['day_spent_amount'];
		function beginTop($phone, $lev){
			global $daySpentAmount;
			global $data;
			global $new_net;
			global $ref;
			global $net_id;
			global $con;
			global $email;
			global $data_id;
			global $amount;
			global $cost;
			global $data_vol;
			global $data_desc;
			global $current_date;
			global $data_type;
			global $username;
			$spent = 0;
			$t = time();
			


			if (isset(json_decode($data['day_spent'])->time)) {
				$dayspent = json_decode($data['day_spent']);

				if (($t - $dayspent->time) < 86400) {
					if ($dayspent->spent > intval($daySpentAmount)) {
						if ($data['kyc_validate'] != 'true') {
							die('732');
						}
					}
					$spent = $dayspent->spent;
					$t = $dayspent->time;
				}
			}
			
			$oldPro = mysqli_fetch_assoc(mysqli_query($con, "SELECT profit FROM config"));
			$userBal = mysqli_fetch_assoc(mysqli_query($con, "SELECT bal, refBy FROM users WHERE email = '$email'"));
			
			$curl = curl_init();

			curl_setopt_array($curl, array(
				CURLOPT_URL => "https://$_SERVER[HTTP_HOST]/api_admin/api/dataPurchase.php",
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => '',
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => 'POST',
				CURLOPT_POSTFIELDS => array('plan_id' => $data_id,'phone_number' => $phone,'network_id' => $net_id, 'username' => $data['username']),
				CURLOPT_HTTPHEADER => array(
					'Authorization: Token 0987654321'
					// 'Authorization: Token e7209548ec58fcd8ee3d80731cf32837'
				),
			));

			$response = curl_exec($curl);
			file_put_contents('TST', $response);
			curl_close($curl);

			
			// mysqli_query($con, "INSERT INTO error (det) VALUES ('$response')");
			$myfile = fopen("errorFile.txt", "a") or die("Unable to open file!");
			fwrite($myfile, '\r\n '.$current_date.': '.$response.'\r\n');
			fclose($myfile);
			
			
			$resp = json_decode($response);
			$amt = strval($amount);
			$bal = strval($userBal['bal']);
			// $ref = "DATA TOP UP OF {$data_vol} to {$phone}";
			
			if(isset($resp->error) && $resp->error == true){
			
				$bal = strval($userBal['bal']);
				if ($resp->desc == 'balance not enough' || $resp->desc == 'insufficient balance' || $resp->desc == 'Invalid Authorization Token') {
					mysqli_query($con, "INSERT INTO transactions (type, t_desc, ref, status, amount, oldBal, newBal, email, date_time, channel, t_id, username, amount_paid) VALUES ('$data_desc', '$data_desc', '$ref', 'failed', '$amt', '$bal', '$bal', '$email', '$current_date', '$new_net', '$data_id', '$username', '0')");
					die('552');
				}elseif ($resp->desc == 'Plan currently not available') {
					mysqli_query($con, "INSERT INTO transactions (type, t_desc,  ref, status, amount, oldBal, newBal, email, date_time, channel, t_id, username, amount_paid) VALUES ('$data_desc', '$data_desc', '$ref', 'failed', '$amt', '$bal', '$bal', '$email', '$current_date', '$new_net', '$data_id', '$username', '0')");
					die('558');
				}elseif($resp->desc == 'Invalid phone_number'){
					die('403');
				}
				
			}elseif(isset($resp->error) &&$resp->error == false){
			    $rtr = '';
			    if(isset($resp->rtr)) $rtr = $resp->rtr;
				$amt = strval($amount);
				$oldBal = strval($userBal['bal']);
				$newBal = strval(intval($oldBal) - intval($amt));
				
				$spent = $spent + intval($amount);
				$new_spent = json_encode(array('time' => $t, 'spent' => $spent));
				mysqli_query($con, "UPDATE users SET bal = '$newBal', day_spent = '$new_spent' WHERE email = '$email'");
				$new_data_desc = $data_desc." to ${phone}";
				mysqli_query($con, "INSERT INTO transactions (type, t_desc, ref, status, amount, oldBal, newBal, email, date_time, channel, t_id, username, amount_paid, rtr) VALUES ('$new_data_desc', '$new_data_desc', '$ref', 'success', '$amt', '$oldBal', '$newBal', '$email', '$current_date', '$new_net', '$data_id', '$username', '$amt', '$rtr')");
				if(!empty($userBal['refBy'])){
            $ref_username = $userBal['refBy'];
            $refQ = mysqli_query($con, "SELECT commission FROM users WHERE username = '$ref_username'");
            if(mysqli_num_rows($refQ) == 1){
                $ref_data = mysqli_fetch_assoc($refQ);
                $ref_bunus = $ref_data['commission'];
                $new_ref_bonus = strval(floatval($ref_data['commission']) + 1);
                mysqli_query($con, "UPDATE users SET commission = '$new_ref_bonus' WHERE username = '$ref_username'");
                mysqli_query($con, "INSERT INTO transactions (type, ref, status, amount, oldBal, newBal, username, date_time) VALUES ('REFERRAL COMMISSION', '$ref', 'success', '1', '$ref_bunus', '$new_ref_bonus', '$ref_username', '$current_date')");
            }
        }
				// $dif = intval($amount) - intval($cost);
				// $newPro = strval(intval($oldPro['profit']) + $dif);
				// mysqli_query($con, "UPDATE config SET profit = '$newPro'");
				if ($lev) {
					die('200');
				}
			}else{
				$new_data_desc = $data_desc." to ${phone}";
				mysqli_query($con, "INSERT INTO transactions (type, t_desc, ref, status, amount, oldBal, newBal, email, date_time, channel, t_id, username, amount_paid) VALUES ('$data_desc', '$new_data_desc', '$ref', 'failed', '$amt', '$bal', '$bal', '$email', '$current_date', '$new_net', '$data_id', '$username', '0')");
				// mysqli_query($con, "INSERT INTO error (det) VALUES ('$response')");
				die('533');
			}
		}

		$amt = strval($amount);
		$bal = strval($userBal['bal']);
		
		
		if (strlen($phone) > 11) {
			$phoness = explode(',', $phone);
			$pn = count($phoness);
			if ((intval($amount) * $pn) > intval($userBal['bal'])) {
				mysqli_query($con, "INSERT INTO transactions (type, t_desc, ref, status, amount, oldBal, newBal, email, date_time, channel, t_id, username, amount_paid) VALUES ('$data_desc', 'INSUFFICIENT FUND', '$ref', 'failed', '$amt', '$bal', '$bal', '$email', '$current_date', '$new_net', '$data_id', '$username', '0')");
				die('302');
			}elseif($data['kyc_validate'] != 'true' && (intval($amount) * $pn) > $daySpentAmount){
                die('732');
            }
			$n = $pn-1;
			foreach ($phoness as $key => $new_phone) {
				beginTop($new_phone, $key === $n ? true : false);
			}
		}else{
			if (intval($amount) > intval($userBal['bal'])) {
				mysqli_query($con, "INSERT INTO transactions (type, t_desc, ref, status, amount, oldBal, newBal, email, date_time, channel, t_id, username, amount_paid) VALUES ('$data_desc', 'INSUFFICIENT FUND', '$ref', 'failed', '$amt', '$bal', '$bal', '$email', '$current_date', '$new_net', '$data_id', '$username', '0')");
				die('302');
			}elseif($data['kyc_validate'] != 'true' && intval($amount) > $daySpentAmount){
                die('732');
            }
			beginTop($phone, true);
			
		}
		
	}
	

}


?>