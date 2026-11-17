<?php
include "../../config.php"; 
// error_reporting(0);
// $refUsername = '';
// if(isset($_GET['referrer'])){


//   $refU = filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_GET['referrer']))) ), FILTER_SANITIZE_STRIPPED);
//   $checkSql = mysqli_query($con, "SELECT username FROM users WHERE username = '$refU'");
//   if (mysqli_num_rows($checkSql) == 1) {
//     $userDet = mysqli_fetch_assoc($checkSql);
//     $refUsername = $userDet['username'];
//   }else{
//     die('INVALID REFERAL ID');
//   }
// }
if (isset($_POST['reg'])) {

  $name = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['name']))) ), FILTER_SANITIZE_STRIPPED));
  $email = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['email']))) ), FILTER_SANITIZE_STRIPPED));
  $username = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['username']))) ), FILTER_SANITIZE_STRIPPED));
  $phone = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['phone']))) ), FILTER_SANITIZE_STRIPPED));
  
  $refer = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['refer']))) ), FILTER_SANITIZE_STRIPPED));
  $pass = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['pass']))) ), FILTER_SANITIZE_STRIPPED));
  // $repeat_pass = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['repeat_pass']))) ), FILTER_SANITIZE_STRIPPED));
  if (empty($name)) {
    die('Full Name is Required');
  }elseif (empty($email)) {
    die('Email is Required');
  }elseif (str_word_count($name) < 2) {
    die('Name must be fullname');
  }elseif (empty($phone)) {
    die('Phone Number is Required');
  }elseif (strlen($phone) != 11) {
    die('Invalid Phone Number');
  }elseif (empty($username)) {
    die('Username is Required');
  }elseif (strlen($username) < 5) {
    die('Username must not less than 5 in length');
  }elseif (str_word_count($username) != 1) {
    die('Username must not contain space');
  }elseif (empty($pass)) {
    die('Password is Required');
  }elseif (strlen($pass) < 8) {
    die('Passwords length must be at least 8');
  }else{
    if (mysqli_num_rows(mysqli_query($con, "SELECT id FROM users WHERE email='$email'")) != 0) {
      die("Email has already been used");
    }elseif (mysqli_num_rows(mysqli_query($con, "SELECT id FROM users WHERE username='$username'")) != 0) {
      die("Username has already been used");
    }elseif (mysqli_num_rows(mysqli_query($con, "SELECT id FROM users WHERE phone='$phone'")) != 0) {
      die("Phone Number has already been used");
    }elseif (!empty($refer) && mysqli_num_rows(mysqli_query($con, "SELECT id FROM users WHERE username='$refer'")) == 0) {
      die("INVALID REFERRER ID");
    }
    $password = password_hash($pass, PASSWORD_DEFAULT);
    $apik = trim(json_decode($config['monnify'])[0]);
    $seck = trim(json_decode($config['monnify'])[2]);
    $apiCon = trim(json_decode($config['monnify'])[1]);
    $au = base64_encode("{$apik}:{$seck}");
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "https://api.monnify.com/api/v1/auth/login/");
    // curl_setopt($ch, CURLOPT_URL, "https://sandbox.monnify.com/api/v1/auth/login/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);

    curl_setopt($ch, CURLOPT_POST, TRUE);

    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      "Content-Type: application/json",
      "Authorization: Basic ".$au
    ));

    $response = curl_exec($ch);
    curl_close($ch);
    $tk = json_decode($response)->responseBody->accessToken;

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "https://api.monnify.com/api/v1/bank-transfer/reserved-accounts");
    // curl_setopt($ch, CURLOPT_URL, "https://sandbox.monnify.com/api/v1/bank-transfer/reserved-accounts");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);

    curl_setopt($ch, CURLOPT_POST, TRUE);
    $tRef = md5($name.$email.time());
    curl_setopt($ch, CURLOPT_POSTFIELDS, "{
      \"accountName\": \"{$name}\",
      \"accountReference\": \"{$tRef}\",
      \"currencyCode\": \"NGN\",
      \"contractCode\": \"{$apiCon}\",
      \"customerName\": \"{$name}\",
      \"customerEmail\": \"{$email}\",
      \"getAllAvailableBanks\": true
    }");

    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      "Content-Type: application/json",
      "Authorization: Bearer ".$tk
    ));

    $response = curl_exec($ch);
    curl_close($ch);
    mysqli_query($con, "INSERT INTO error (det) VALUES ('$response')");

    
// $js = '{"requestSuccessful":true,"responseMessage":"success","responseCode":"0","responseBody":{"contractCode":"1579542348","accountReference":"jsnow1234","accountName":"Tai","currencyCode":"NGN","customerEmail":"twrasaq@gmail.com","customerName":"John Snow Limited","accountNumber":"5000641057","bankName":"Wema bank","bankCode":"035","collectionChannel":"RESERVED_ACCOUNT","reservationReference":"T0SYHTEHG3F9BUH37K59","reservedAccountType":"GENERAL","status":"ACTIVE","createdOn":"2022-05-14 08:10:57.765","incomeSplitConfig":[],"restrictPaymentSource":false}}';
    $accDet = [];
    $res = json_decode($response);
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://api.monnify.com/api/v1/bank-transfer/reserved-accounts/add-linked-accounts/'.$res->responseBody->accountReference,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'PUT',
      CURLOPT_POSTFIELDS =>'{
        "getAllAvailableBanks": false,
        "preferredBanks": ["50515", "232"]
      }',
      CURLOPT_HTTPHEADER => array(
        "Content-Type: application/json",
        "Authorization: Bearer ".$tk
      ),
    ));
// '{
//        "getAllAvailableBanks": false,
//        "preferredBanks": ["50515", "232"]
//      }',
    $new_response = curl_exec($curl);

    curl_close($curl);
    mysqli_query($con, "INSERT INTO error (det) VALUES ('$new_response')");
    $res = json_decode($new_response);
    if ($res->requestSuccessful && $res->responseMessage == 'success') {

      $accDet = $res->responseBody->accounts;

    }
    $accountReference = $res->responseBody->accountReference;
    $genAccDet = json_encode($accDet);
    if (!empty($refer)) {
      $oldUserRefs = mysqli_fetch_assoc(mysqli_query($con, "SELECT refs FROM users WHERE username = '$refer'"));
      $refs = json_decode($oldUserRefs['refs']);
      array_push($refs, $username);
      $newRefs = json_encode($refs);
      mysqli_query($con, "UPDATE users SET refs = '$newRefs' WHERE username = '$refer'");
    }
    

    $token = md5($current_date.$username.'VTU');
    if (mysqli_query($con, "INSERT INTO users (name, email, username, phone, refBy, regDate, password, reserveBank, accountReference, token, refs, compRef) VALUES ('$name', '$email', '$username', '$phone', '$refer', '$current_date', '$password', '$genAccDet', '$accountReference', '$token', '[]', '[]')")) {


      $config = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM config"));
      $adm = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM adm"));
      $input;
      $ciphering = "AES-128-CTR";
      $iv_length = openssl_cipher_iv_length($ciphering);
      $options = 0;
      $encryption_iv = '0987654321098765';
      $encryption_key = "THISISTHEENCRYPTKEY";
      $enc_str = urlencode(openssl_encrypt($username, $ciphering, $encryption_key, $options, $encryption_iv));
      $siteName = $config['site_name'];
      $siteLink = $config['site_link'];
      try {
        $to = $email;
        $subject = "Email Verification";
        $message = "Thank you for registering at ${siteName}. <br> Your account has been registered successfully! kindly click on the link below to verify your email.  <br> <b> If you are unable to click on the link, just copy it and paste it in browser. <b>  <br> Thanks.<br><br><br> <a href='https://${siteLink}/verify?q=${enc_str}' style='color:blue'>http://${siteLink}/verify?q=${enc_str}</a>";

        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";

        $headers .= 'From: <support@9jastores.ng>' . "\r\n";
        $headers .= 'Cc: support@9jastores.ng' . "\r\n";
        mail($to,$subject,$message,$headers);

      } catch (Exception $e) {

      }
      die("success");
      
    }else{
      die("An error ocurred while registering");
    }
  }
  
}

?>
