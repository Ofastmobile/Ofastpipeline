<?php

if (isset($_POST['topup'])) {
  include '../../config.php';
  $net_id = 0;
  $main_net =$_POST['network'];
  if ($_POST['network'] == 'MTN') {
    $net_id = 1;
  }elseif ($_POST['network'] == 'GLO') {
    $net_id = 3;
  }elseif ($_POST['network'] == 'AIRTEL') {
    $net_id = 2;
  }elseif ($_POST['network'] == '9MOBILE') {
    $net_id = 4;
  }

  if ($net_id != 0) {
    $amount = mysqli_real_escape_string($con, $_POST['amount']);
    $type = strtolower(mysqli_real_escape_string($con, $_POST['type']));

    $pin = mysqli_real_escape_string($con, $_POST['pin']);
    if ($data['pin'] != $pin) {
      die('329');
    }
    $real_amt = $amount;
    $username = $_SESSION['username'];
    // $air_p = intval($_SESSION['config']['airtime_discount']);
     // = strval(intval($amount) - ((intval($air_p)/100) * intval($amount)));
    $email = $_SESSION['data']['email'];
    $t = time();
    $ref = md5($t.$email);
    $userBal = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM users WHERE email = '$email'"));
    if (intval($amount) > intval($userBal['bal'])) {

      $amt = strval($amount);
      $bal = strval($userBal['bal']);
      mysqli_query($con, "INSERT INTO transactions (type, t_desc, ref, status, amount, oldBal, newBal, email, date_time, channel, username) VALUES ('Airtime Top Up', 'INSUFFICIENT FUND', '$ref', 'failed', '$amt', '$bal', '$bal', '$email', '$current_date', '$main_net', '$username')");
      die('303');
    }elseif(intval($amount) < 100){
      die('350');
    }else{
      

      $username = $_SESSION['data']['username'];
      $max_air_day = $data['kyc_validate'] == 'true' ? $config['max_airtime_v'] : $config['max_airtime_u'];
      $phone = strval(mysqli_real_escape_string($con, $_POST['phone']));
      $config = mysqli_fetch_assoc(mysqli_query($con, "SELECT day_spent_amount FROM config"));
      $daySpentAmount = $config['day_spent_amount'];

      function buy($phone, $lev, $type){
        global $daySpentAmount;
        global $data;
        global $username;
        global $amount;
        global $net_id;
        global $con;
        global $email;
        global $current_date;
        global $main_net;
        $userBal = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM users WHERE email = '$email'"));
        global $real_amt;
        global $ref;
        // global $userBal;
        $spent = 0;
        $t = time();
        $airtime_conf = mysqli_fetch_array(mysqli_query($con, "SELECT * FROM airtime_config WHERE network_id = '$net_id'"));
        $usertype = $userBal['type'].'_'.$type;
        $r = $airtime_conf[$usertype];
        $new_amt = 0;
        if ($airtime_conf['method'] == 'percentage') {
          $r = intval($r);
          $new_amt = ($r/100) * intval($amount);
        }elseif($airtime_conf['method'] == 'discount'){
         $r = intval($r);
         $new_amt = $r;
       }
       $new_amt = intval($amount) - $new_amt;
       $userBal = mysqli_fetch_assoc(mysqli_query($con, "SELECT bal, refBy FROM users WHERE email = '$email'"));

       if (isset(json_decode($data['day_spent'])->time)) {
        $dayspent = json_decode($data['day_spent']);
        $t_now = time();
        if (($t_now - $dayspent->time) < 86400) {
          if ($dayspent->spent  > intval($daySpentAmount)) {
            if ($data['kyc_validate'] != 'true') {
              die('732');
            }
          }
          $spent = $dayspent->spent;
          $t = $dayspent->time;
        }
      }
      if (isset(json_decode($data['max_airtime'])->time)) {
        $max_air = json_decode($data['max_airtime']);
        $t_now = time();
        if (($t_now - $max_air->time) < 86400) {
          if ($max_air->spent  > intval($max_air_day)) {
            if ($data['kyc_validate'] != 'true') {
              die('732');
            }
          }
          $a_spent = $max_air->spent;
          $a_t = $max_air->time;
        }
      }else{
        $a_spent = 0;
        $a_t = time();
      }

      
      $curl = curl_init();

      curl_setopt_array($curl, array(
        CURLOPT_URL => "https://$_SERVER[HTTP_HOST]/api_admin/api/airtime.php",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array('phone_number' => $phone,'network_id' => $net_id,'amount' => $amount, 'type' => $type),
        CURLOPT_HTTPHEADER => array(
          'Authorization: Token e7209548ec58fcd8ee3d80731cf32837'
        ),
      ));

      $response = curl_exec($curl);

      curl_close($curl);
      // $response = '{"error":false}';
      $response = strval($response);
        // mysqli_query($con, "INSERT INTO error (det) VALUES ('$response')");
      $myfile = fopen("errorFile.txt", "a") or die("Unable to open file!");
      fwrite($myfile, '\r\n '.$current_date.': '.$response.'\r\n');
      fclose($myfile);
      
      $resp = json_decode($response);

      $amt = strval($amount);
      $bal = strval($userBal['bal']);
      $new_t = strtoupper($type);
      $t_desc = "{$main_net} {$new_t} AIRTIME TOP UP OF {$amt} to {$phone}";
      if((isset($resp->error) && $resp->error == true) || !isset($resp->error)){
        $bal = strval($userBal['bal']);
        if (isset($resp->desc) && $resp->desc == 'balance not enough') {
          mysqli_query($con, "INSERT INTO transactions (type, t_desc, ref, status, amount, oldBal, newBal, email, date_time, channel, username, amount_paid) VALUES ('$t_desc', '$t_desc', '$ref', 'failed', '$amt', '$bal', '$bal', '$email', '$current_date', '$main_net', '$username', '0')");
          die('319');
        }else if(isset($resp->desc) && $resp->desc == 'AIRTIME NOT AVAILABLE AT THE MOMENT'){
          mysqli_query($con, "INSERT INTO transactions (type, t_desc, ref, status, amount, oldBal, newBal, email, date_time, channel, username, amount_paid) VALUES ('$t_desc', '$t_desc', '$ref', 'failed', '$amt', '$bal', '$bal', '$email', '$current_date', '$main_net', '$username', '0')");
          die('408');
        }else{
          mysqli_query($con, "INSERT INTO transactions (type, t_desc, ref, status, amount, oldBal, newBal, email, date_time, channel, username, amount_paid) VALUES ('$t_desc', '$t_desc', '$ref', 'failed', '$amt', '$bal', '$bal', '$email', '$current_date', '$main_net', '$username', '0')");
          die('504');
        }
      }elseif(isset($resp->error) && $resp->error == false){
          // $paid = $resp->paid_amount;
        $amt = $real_amt;
          // $profit = intval($amt) - intval($paid);
        $oldPro = mysqli_fetch_assoc(mysqli_query($con, "SELECT profit FROM config"));
          // $newPro = strval(intval($oldPro['profit']) + $profit);
        $oldBal = strval($userBal['bal']);
        $newBal = strval(intval($oldBal) - intval($new_amt));
        $spent = $spent + intval($amt);
        $a_spent = $a_spent + intval($amt);
        $new_spent = json_encode(array('time' => $t, 'spent' => $spent));
        $new_air = json_encode(array('time' => $a_t, 'spent' => $a_spent));
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
        mysqli_query($con, "UPDATE users SET bal = '$newBal', day_spent = '$new_spent', max_airtime = '$newBal' WHERE email = '$email'");

        mysqli_query($con, "INSERT INTO transactions (type, t_desc, ref, status, amount, oldBal, newBal, email, date_time, channel, username, amount_paid) VALUES ('$t_desc', '$t_desc', '$ref', 'success', '$amt', '$oldBal', '$newBal', '$email', '$current_date', '$main_net', '$username', '$new_amt')");
          // mysqli_query($con, "UPDATE config SET profit = '$newPro'");
        if ($lev) {
          die('202');
        }
      }
    }

    if (strlen($phone) > 11) {
      $phoness = explode(',', $phone);
      $pn = count($phoness);
      if ((intval($amount) * $pn) > intval($userBal['bal'])) {
        die('303');
      }elseif($data['kyc_validate'] != 'true' && (intval($amount) * $pn) > $daySpentAmount){
          die('732');
      }
      $n = $pn-1;
      foreach ($phoness as $key => $new_phone) {
        buy($new_phone, $key === $n ? true : false, $type);
      }
    }else{
      if (intval($amount) > intval($userBal['bal'])) {
        die('303');
      }elseif($data['kyc_validate'] != 'true' && intval($amount) > $daySpentAmount){
          die('732');
      }
      buy($phone, true, $type);
    }
  }
}else{
  die('521');
}
}


?>
