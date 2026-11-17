<?php 

include 'header.php';
$email = $data['email'];
if (isset($_GET['withdraw'])) {
    $refUser = filter_var(strip_tags(mysqli_real_escape_string($con,trim(preg_replace('/[\t\n\r\s]+/', ' ', $_GET['withdraw']))) ), FILTER_SANITIZE_STRIPPED);
    $allRefs = json_decode($data['refs']);
    $completedRefs = json_decode($data['compRef']);
    if (!in_array($refUser, $allRefs)) {
        echo "<script>Swal.fire('This user is not included in your referrer list', '', 'error')</script>";
    }else{
        if (in_array($refUser, $completedRefs)) {
            echo "<script>Swal.fire('This bonus has already been withdrawn', '', 'error')</script>";
        }else{
            array_push($completedRefs, $refUser);
            $bonus = $config['refBonus'];
            $username = $data['username'];
            $userBonus = mysqli_fetch_assoc(mysqli_query($con, "SELECT bonus, bal FROM users WHERE username = '$username'"));
            $oldBal = $userBonus['bal'];
            $new_bonus = strval(intval($userBonus['bonus']) + intval($bonus));
            $new_bal = strval(intval($userBonus['bal']) + intval($bonus));
            $ref = "{$bonus} Bonus withdrawn from user {$refUser}";
            $encRef = json_encode($completedRefs);
            mysqli_query($con, "UPDATE users SET bonus = '$new_bonus', compRef = '$encRef', bal = '$new_bal' WHERE username = '$username'");
            $hist = mysqli_query($con, "INSERT INTO transactions (type, ref, status, amount, oldBal, newBal, email, date_time) VALUES ('BONUS WITHDRAWAL', '$ref', 'success', '$bonus', '$oldBal', '$new_bal', '$email', '$current_date')");
            $data['compRef'] = $encRef;
            echo "<script>Swal.fire('Withdrawal Successful', '', 'success')</script>";
        }
    }
}
?>
<!-- Content -->

<div class="container-fluid">

    <!-- Animated -->
    <!-- <div class="animated fadeIn"> -->

        <!-- Widgets  -->
        <div class="row">
            <div class="col-lg-3 my-2">
                <div class="card">
                    <div class="card-body">
                        <div class="stat-widget-five">
                            <div class="stat-icon dib flat-color-1">
                                <i class="pe-7s-cash"></i>
                            </div>
                            <div class="stat-content">
                                <div class="text-left dib">
                                    <div class="stat-text">₦ <span class="count"><?php 
                                    $tRef = json_decode($data['refs']);
                                    $tCRef = json_decode($data['compRef']);
                                    $bns = count(array_diff($tRef, $tCRef)) * intval($config['refBonus']);
                                    echo $bns;
                                     ?></span></div>
                                    <div class="stat-heading">Referral Bonus Earned</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 my-2">
                <div class="card">
                    <div class="card-body">
                        <div class="stat-widget-five">
                            <div class="stat-icon dib flat-color-2">
                                <i class="pe-7s-users"></i>
                            </div>
                            <div class="stat-content">
                                <div class="text-left dib">
                                    <div class="stat-text"><span class="count"><?=count(json_decode($data['refs'])) ?></span></div>
                                    <div class="stat-heading">Total Number of Referrals </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 my-2">
                <div class="card" onclick="window.location.href = 'referral' ">
                    <div class="card-body">
                        <div class="stat-widget-five">
                            <div class="stat-icon dib flat-color-4">
                                <i class="pe-7s-cart "></i>
                            </div>
                            <div class="stat-content">
                                <div class="text-left dib">
                                    <div class="stat-text">₦ <span class="count"><?=$data['commission'] ?></span></div>
                                    <div class="stat-heading">Commission </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 my-2">
                <div class="card" onclick="window.location.href = 'referral' ">
                    <div class="card-body">
                        <div class="stat-widget-five">
                            <div class="stat-icon dib flat-color-4">
                                <i class="pe-7s-magnet "></i>
                            </div>
                            <div class="stat-content">
                                <div class="text-left dib">
                                    <div class="stat-text"><span class="count"><?=count(json_decode($data['compRef'])) ?></span></div>
                                    <div class="stat-heading">Total Number of Withdrawals</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <!-- /Widgets -->

        <div class="row">
            <!-- <div class="col">
                <div class="card">
                    <button type="button" class="btn btn-link btn-sm card-body text-secondary"><i class="ti ti-bar-chart"></i>&nbsp; Transfer All to Wallet ₦ </button>
                </div>
            </div> -->
            <div class="col-md-6 my-2" onclick="textCopy()">
                <div class="card">
                    <div class="card-body text-secondary"><i class="ti ti-link"></i>&nbsp; http://<?=$config['site_link'] ?>/registration?referrer=<?=$data['username'] ?></div>
                </div>
            </div>
        </div>
        <button class="btn btn-danger mt-3">WITHDRAW COMMISSION</button>
        <div class="orders">
            <div class="row">
                <div class="col-xl-12">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="box-title">Total Referrals </h4>
                        </div>
                        <div class="card-body--">
                            <div class="table-responsive">
                                <table class="table ">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Name</th>
                                            <th>Phone Number</th>
                                            <th>Date Registered</th>
                                            <th>Bonus</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $refs = json_decode($data['refs']);
                                        $n = 0;
                                        foreach (array_reverse($refs) as $user) {
                                            $userDet = mysqli_fetch_assoc(mysqli_query($con, "SELECT id, name, phone, regDate, type FROM users WHERE username = '$user'"));
                                            $n++;
                                            ?>
                                            <tr>
                                                <td> <?=$n ?> </td>
                                                <td> <?=$userDet['name'] ?> </td>
                                                <td> <?=$userDet['phone'] ?> </td>
                                                <td> <?=$userDet['regDate'] ?> </td>
                                                <td> ₦<?=$config['refBonus'] ?> </td>
                                                <td>
                                                    <button class="btn btn-<?=$userDet['type'] == 'user1' ? 'danger' : 'success' ?>"> <?=$userDet['type'] == 'user1' ? 'NOT UPGRADED' : 'UPGRADED' ?> </button>
                                                </td>
                                                <td>
                                                    <btn class="btn btn-<?php 
                                                    if($userDet['type'] == 'user1'){
                                                        echo 'secondary';
                                                        }else{
                                                            $cRef = json_decode($data['compRef']);
                                                            if(in_array($user, $cRef)){
                                                                echo 'secondary';
                                                                }else{
                                                                    echo 'primary';
                                                                }
                                                            }
                                                        ?>">

                                                        <?php 
                                                        if($userDet['type'] == 'user1'){
                                                            echo 'Withdraw';
                                                        }else{
                                                            $cRef = json_decode($data['compRef']);
                                                            if(in_array($user, $cRef)){
                                                                echo 'Withdrawn';
                                                            }else{
                                                                echo "<a class='text-white' href='?withdraw={$user}'>Withdraw</a>";
                                                            }
                                                        }
                                                    ?> </btn>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div> 
                </div> 







            </div>
            <!-- .animated -->
        </div>
        <!-- /.content -->

        <script type="text/javascript">

            function textCopy() {
                var text = "http://<?=$config['site_link'] ?>/registration?referrer=<?=$data['username'] ?>";
                var textArea = document.createElement("textarea");
                textArea.value = text;

                textArea.style.top = "0";
                textArea.style.left = "0";
                textArea.style.position = "fixed";

                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();

                try {
                    var successful = document.execCommand('copy');
                    var msg = successful ? 'Link Copied' : 'Unable to copy';
                    Swal.fire(msg);
                    console.log('Fallback: Copying text command was ' + msg);
                } catch (err) {
                    console.error('Fallback: Oops, unable to copy', err);
                }

                document.body.removeChild(textArea);
            }
            function copyTextToClipboard(text) {
              if (!navigator.clipboard) {
                fallbackCopyTextToClipboard(text);
                return;
            }
            navigator.clipboard.writeText(text).then(function() {
                console.log('Async: Copying to clipboard was successful!');
            }, function(err) {
                console.error('Async: Could not copy text: ', err);
            });
        }

        var copyBobBtn = document.querySelector('.js-copy-bob-btn'),
        copyJaneBtn = document.querySelector('.js-copy-jane-btn');

        copyBobBtn.addEventListener('click', function(event) {
          copyTextToClipboard('Bob');
      });


        copyJaneBtn.addEventListener('click', function(event) {
          copyTextToClipboard('Jane');
      });
  </script>
  <?php 
  include 'footer.php';

?>