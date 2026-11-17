<?php
include "../../config.php";

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="icon" type="image/png" href="../../favicon.png">
  <title>
    Sign-Up
  </title>
  <!--     Fonts and icons     -->
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
  <!-- Nucleo Icons -->
  <link href="../assets/css/nucleo-icons.css" rel="stylesheet" />
  <link href="../assets/css/nucleo-svg.css" rel="stylesheet" />
  <!-- Font Awesome Icons -->
  <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
  <link href="../assets/css/nucleo-svg.css" rel="stylesheet" />
  <!-- CSS Files -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.1/dist/css/bootstrap.min.css" integrity="sha384-zCbKRCUGaJDkqS1kPbPd7TveP5iyJE0EjAuZQTgFLD2ylzuqKfdKlfG/eSrtxUkn" crossorigin="anonymous">

  <link id="pagestyle" href="../assets/css/argon-dashboard.css?v=2.0.3" rel="stylesheet" />
  <script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="vendor/jquery/jquery-3.2.1.min.js"></script>

  <link rel="stylesheet" href="pr.css">
  <style>
    .password-strength {
      box-shadow: 0 4px 5px 0 rgba(0, 0, 0, 0.14), 0 1px 10px 0 rgba(0, 0, 0, 0.12), 0 2px 4px -1px rgba(0, 0, 0, 0.3);
    }

    .js-hidden {
      display: none;
    }
  </style>
</head>

<?php 
error_reporting(0);
$refUsername = '';
if(isset($_GET['referrer'])){


  $refU = filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_GET['referrer']))) ), FILTER_SANITIZE_STRIPPED);
  $checkSql = mysqli_query($con, "SELECT username FROM users WHERE username = '$refU'");
  if (mysqli_num_rows($checkSql) == 1) {
    $userDet = mysqli_fetch_assoc($checkSql);
    $refUsername = $userDet['username'];
  }else{
    echo "<script>alert('INVALID REFERAL ID')</script>";
  }
}
if (isset($_POST['reg'])) {

  $name = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['name']))) ), FILTER_SANITIZE_STRIPPED));
  $email = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['email']))) ), FILTER_SANITIZE_STRIPPED));
  $username = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['username']))) ), FILTER_SANITIZE_STRIPPED));
  $phone = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['phone']))) ), FILTER_SANITIZE_STRIPPED));
  
  $refer = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['refer']))) ), FILTER_SANITIZE_STRIPPED));
  $pass = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['pass']))) ), FILTER_SANITIZE_STRIPPED));
  $repeat_pass = trim(filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_POST['repeat_pass']))) ), FILTER_SANITIZE_STRIPPED));
  if (empty($name)) {
    $_SESSION['reg_msg'] = 'Full Name is Required';
    goto end;
  }elseif (empty($email)) {
    $_SESSION['reg_msg'] = 'Email is Required';
    goto end;
  }elseif (str_word_count($name) < 2) {
    $_SESSION['reg_msg'] = 'Name must be fullname';
    goto end;
  }elseif (empty($phone)) {
    $_SESSION['reg_msg'] = 'Phone Number is Required';
    goto end;
  }elseif (strlen($phone) != 11) {
    $_SESSION['reg_msg'] = 'Invalid Phone Number';
    goto end;
  }elseif (empty($username)) {
    $_SESSION['reg_msg'] = 'Username is Required';
    goto end;
  }elseif (strlen($username) < 5) {
    $_SESSION['reg_msg'] = 'Username must not less than 5 in length';
    goto end;
  }elseif (str_word_count($username) != 1) {
    $_SESSION['reg_msg'] = 'Username must not contain space';
    goto end;
  }elseif (empty($pass)) {
    $_SESSION['reg_msg'] = 'Password is Required';
    goto end;
  }elseif (empty($repeat_pass)) {
    $_SESSION['reg_msg'] = 'Repeat Password is Required';
    goto end;
  }elseif ($pass != $repeat_pass) {
    $_SESSION['reg_msg'] = "Passwords aren't Matched";
    goto end;
  }elseif (strlen($pass) < 8) {
    $_SESSION['reg_msg'] = "Passwords length must be at least 8";
    goto end;
  }else{
    if (mysqli_num_rows(mysqli_query($con, "SELECT id FROM users WHERE email='$email'")) != 0) {
      $_SESSION['reg_msg'] = "Email has already been used";
      goto end;
    }elseif (mysqli_num_rows(mysqli_query($con, "SELECT id FROM users WHERE username='$username'")) != 0) {
      $_SESSION['reg_msg'] = "Username has already been used";
      goto end;
    }elseif (mysqli_num_rows(mysqli_query($con, "SELECT id FROM users WHERE phone='$phone'")) != 0) {
      $_SESSION['reg_msg'] = "Phone Number has already been used";
      goto end;
    }elseif (!empty($refer) && mysqli_num_rows(mysqli_query($con, "SELECT id FROM users WHERE username='$refer'")) == 0) {
      $_SESSION['reg_msg'] = "INVALID REFERRER ID";
      goto end;
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
    $config = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM config"));
    $email_st = $config['email_st'];
    $st = 'verified';
    // if($email_st == 'false'){
    //     $st = 'verified';
    // }
    if (mysqli_query($con, "INSERT INTO users (name, email, username, phone, refBy, regDate, password, reserveBank, accountReference, token, refs, compRef, status) VALUES ('$name', '$email', '$username', '$phone', '$refer', '$current_date', '$password', '$genAccDet', '$accountReference', '$token', '[]', '[]', '$st')")) {

      $_SESSION['reg_s_msg'] = "Registered Successfully";
      
      $adm = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM adm"));
    //   if($email_st == 'true'){
    //     try {
    //       $siteName = $config['site_name'];
    //     $siteLink = $config['site_link'];
    //     $to = $email;
    //     $ciphering = "AES-128-CTR";
    //     $iv_length = openssl_cipher_iv_length($ciphering);
    //     $options = 0;
    //     $encryption_iv = '0987654321098765';
    //     $encryption_key = "THISISTHEENCRYPTKEY";
    //     $enc_str = urlencode(openssl_encrypt($username, $ciphering, $encryption_key, $options, $encryption_iv));
    //       $subject = "9JASTORES - Email Verification";
    //       $message = "Thank you for registering at ${siteName}. <br> Your account has been registered successfully! kindly click on the link below to verify your email.  <br> <b> If you are unable to click on the link, just copy it and paste it in browser. <b>  <br> Thanks.<br><br><br> <a href='https://${siteLink}/verify?q=${enc_str}' style='color:blue'>http://${siteLink}/verify?q=${enc_str}</a>";     
    //       $headers = "MIME-Version: 1.0" . "\r\n";
    //       $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    //       $headers .= 'From: <support@9jastores.ng>' . "\r\n";
    //       $headers .= 'Cc: support@9jastores.ng' . "\r\n";
    //       mail($to,$subject,$message,$headers);
    //   } catch (Exception $e) {
    //     $myfile = fopen("errorFile.txt", "a") or die("Unable to open file!");
    //     fwrite($myfile, '\r\n '.$current_date.': '.$e.'\r\n');
    //     fclose($myfile);
    //   }
    // }
      
      
    }else{
      $_SESSION['reg_msg'] = "An error ocurred while registering";
    }
  }
  
}
end:
?>

<body class="">
  <?php 
  
  if (isset($_SESSION['reg_msg']) && !empty($_SESSION['reg_msg'])) {
    ?>
    <script>
      Swal.fire("<?= $_SESSION["reg_msg"] ?>",'','error').then(()=>{
        history.back()
      })
    </script>
    <?php
    $_SESSION['reg_msg'] = '';
  }
  if (isset($_SESSION['reg_s_msg']) && !empty($_SESSION['reg_s_msg'])) {
    ?>
    <script>
      Swal.fire("<?= $_SESSION["reg_s_msg"] ?>",'Presss OK button to go to login page!','success').then(()=>{
        window.location.replace("../login");
      })
    </script>
    <?php
    $_SESSION['reg_s_msg'] = '';
  }
  ?>
  <!-- Navbar -->
  <nav class="navbar navbar-expand-lg position-absolute top-0 z-index-3 w-100 shadow-none my-3 navbar-transparent mt-4">
    <div class="container">
      <a class="navbar-brand font-weight-bolder ms-lg-0 ms-3 text-white" href="../../">
        <?=$config['site_name'] ?>
      </a>
      <button class="navbar-toggler shadow-none ms-2" type="button" data-bs-toggle="collapse" data-bs-target="#navigation" aria-controls="navigation" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon mt-2">
          <span class="navbar-toggler-bar bar1"></span>
          <span class="navbar-toggler-bar bar2"></span>
          <span class="navbar-toggler-bar bar3"></span>
        </span>
      </button>
      <div class="collapse navbar-collapse" id="navigation">
        <ul class="navbar-nav mx-auto">
          <li class="nav-item">
            <a class="nav-link d-flex align-items-center me-2 active" aria-current="page" href="../../">
              <i class="fa fa-chart-pie opacity-6  me-1"></i>
              Homepage
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link me-2" href="../login">
              <i class="fas fa-key opacity-6  me-1"></i>
              Login
            </a>
          </li>
        </ul>
        <ul class="navbar-nav d-lg-block d-none">
          <li class="nav-item">
            <a href="../../" class="btn btn-sm mb-0 me-1 bg-gradient-light">Home-Page</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>
  <!-- End Navbar -->
  <main class="main-content  mt-0">
    <div class="page-header align-items-start min-vh-50 pt-5 pb-11 m-3 border-radius-lg" style="background-image: url('https://raw.githubusercontent.com/creativetimofficial/public-assets/master/argon-dashboard-pro/assets/img/signup-cover.jpg'); background-position: top;">
      <span class="mask bg-gradient-dark opacity-6"></span>
      <div class="container">
        <div class="row justify-content-center">
            <center><img width="200" src="../../logo.png" alt=""></center>
          <div class="col-lg-5 text-center mx-auto">
            <h1 class="text-white mb-2 mt-5">Welcome!</h1>
            <p class="text-lead text-white">A Journey of a Thousand Miles Begins With a Single Step.</p>
          </div>
        </div>
      </div>
    </div>
    <div class="container">
      <div class="row mt-lg-n10 mt-md-n11 mt-n10 justify-content-center">
        <div class="col-xl-4 col-lg-5 col-md-7 mx-auto">
          <div class="card z-index-0">
            <div class="card-header text-center pt-4">
              <h5>Sign-Up</h5>
            </div>
            
            <div class="card-body">
              <form role="form" method="POST">
                <div class="mb-3">
                  <input class="form-control" autocomplete="off" type="text" name="refer" <?=$refUsername != '' ? "value='{$refUsername}' readonly" : "value='Admin' disabled" ?> placeholder="Referrer Username">
                </div>
                <div class="mb-3">
                  <input class="form-control" type="text" name="name" placeholder="Full Name" required>
                  <!-- <input type="email" class="form-control" placeholder="Email" aria-label="Email"> -->
                </div>
                <div class="mb-3">
                  <input class="form-control" type="email" name="email" placeholder="Email addess" required>
                </div>
                <div class="mb-3">
                  <input class="form-control" type="text" name="username" placeholder="Username" required>
                </div>
                <div class="mb-3">
                  <input class="form-control" type="number" maxlength="11" name="phone" placeholder="Phone Number" required>
                </div>

               <!--  <div class="mb-3">
                  <input class="form-control" type="password" name="pass" placeholder="Password" id="id_password" required>
                  <i class="far fa-eye" id="togglePassword" style="margin-left: 10px; cursor: pointer;"></i>
                </div>

                <div class="mb-3">
                  <input class="form-control" type="password" name="repeat_pass" id="id_password2" placeholder="Repeat Password" required>
                  <i class="far fa-eye" id="togglePassword2" style="margin-left: 10px; cursor: pointer;"></i>
                </div> -->


                <div class="form-group">
                  <label for="password-input">Password</label>
                  <div class="input-group">
                    <input style="width: 70%;" class="password-strength__input form-control" type="password" name="pass" id="id_password" aria-describedby="passwordHelp" placeholder="Enter password"/>
                    <span class="input-group-text">
                      <i class="far fa-eye" id="togglePassword" 
                      style="cursor: pointer"></i>
                    </span>
                  </div><small class="form-text text-muted mt-2" id="passwordHelp">Add 9 charachters or more, lowercase letters, uppercase letters, numbers and symbols to make the password really strong!</small>
                </div>

                <div class="input-group">
                  <input type="password" class="form-control passwordCh" id="id_password2" name="repeat_pass" placeholder="Repeat Password" value="">
                  <span class="input-group-text">
                    <i class="far fa-eye" id="togglePassword2" 
                    style="cursor: pointer"></i>
                  </span>
                </div>






                <div class="form-check form-check-info text-start">
                  <input class="form-check-input" type="checkbox" value="" id="flexCheckDefault" checked>
                  <label class="form-check-label" for="flexCheckDefault">
                    I agree the <a href="javascript:;" class="text-dark font-weight-bolder">Terms and Conditions</a>
                  </label>
                </div>
                <div class="text-center">
                  <button type="submit" name="reg" class="btn bg-gradient-dark w-100 my-4 mb-2">Sign up</button>
                </div>
                <p class="text-sm mt-3 mb-0">Already have an account? <a href="../login" class="text-dark font-weight-bolder">Sign in</a></p>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <footer class="footer py-5">
    <div class="container">

      <div class="row">
        <div class="col-8 mx-auto text-center mt-1">
          <p class="mb-0 text-secondary">
           © <script>
            document.write(new Date().getFullYear())
          </script>
        </p>
      </div>
    </div>
  </div>
</footer>


</script>
<script src="../assets/js/core/popper.min.js"></script>
<script src="../assets/js/core/bootstrap.min.js"></script>
<script src="../assets/js/plugins/perfect-scrollbar.min.js"></script>
<script src="../assets/js/plugins/smooth-scrollbar.min.js"></script>


<script>
  var win = navigator.platform.indexOf('Win') > -1;
  if (win && document.querySelector('#sidenav-scrollbar')) {
    var options = {
      damping: '0.5'
    }
    Scrollbar.init(document.querySelector('#sidenav-scrollbar'), options);
  }
</script>

<script async defer src="https://buttons.github.io/buttons.js"></script>

<script src="../assets/js/argon-dashboard.min.js?v=2.0.3"></script>
<script>
  const togglePassword = document.querySelector('#togglePassword');
  const password = document.querySelector('#id_password');

  togglePassword.addEventListener('click', function (e) {
    // toggle the type attribute
    const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
    password.setAttribute('type', type);
    // toggle the eye slash icon
    this.classList.toggle('fa-eye-slash');
  });
  const togglePassword2 = document.querySelector('#togglePassword2');
  const password2 = document.querySelector('#id_password2');

  togglePassword2.addEventListener('click', function (e) {
    // toggle the type attribute
    const type2 = password2.getAttribute('type') === 'password' ? 'text' : 'password';
    password2.setAttribute('type', type2);
    // toggle the eye slash icon
    this.classList.toggle('fa-eye-slash');
  });
</script>
<script src="https://code.jquery.com/jquery-3.2.1.slim.min.js" integrity="sha384-KJ3o2DKtIkvYIK3UENzmM7KCkRr/rE9/Qpg6aAZGJwFDMVNA/GpGFF93hXpG5KkN" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.12.9/dist/umd/popper.min.js" integrity="sha384-ApNbgh9B+Y1QKtv3Rn7W3mgPxhU9K/ScQsAP7hUibX39j7fakFPskvXusvfa0b4Q" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/js/bootstrap.min.js" integrity="sha384-JZR6Spejh4U02d8jOt6vLEHfe/JQGiRRSQQxSfFWpi1MquVdAyjUar5+76PVCmYl" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
<script type="text/javascript" src="pr.js"></script>
<script type="text/javascript">
// $('#id_password2').PassRequirements();
// $('#id_password2').passwordRulesValidator();
$('#id_password').password();
</script>
</body>

</html>