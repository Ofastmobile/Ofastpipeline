<?php 
include_once 'header.php';

if (isset($_GET['cancel'])) {
  $id = $_GET['cancel'];
  $user_t = mysqli_fetch_assoc(mysqli_query($con, "SELECT * FROM transactions WHERE id = '$id'"));
  $usern = $user_t['username'];
  $uemail = $user_t['email'];
  $amt = $user_t['amount_paid'];
  $the_user = mysqli_fetch_assoc(mysqli_query($con, "SELECT bal FROM users WHERE username = '$usern' OR email = '$uemail'"));
  $new_bal = intval($the_user['bal']) + intval($amt);
  if (mysqli_query($con, "UPDATE users SET bal = '$new_bal' WHERE username = '$usern' OR email = '$uemail'")) {
    mysqli_query($con, "UPDATE transactions SET status = 'cancelled' WHERE id = '$id'");
    echo '<script>alert("Cancelled Successfully")</script>';
  }

}



if (isset($_GET['t'])) {
  $t = $_GET['t'];
  if ($t == 'data') {
    $trans = mysqli_query($con, "SELECT * FROM transactions WHERE type LIKE '%DATA%' ORDER BY id DESC LIMIT 800");
  }elseif ($t == 'airtime') {
    $trans = mysqli_query($con, "SELECT * FROM transactions WHERE type LIKE '%AIRTIME%' ORDER BY id DESC LIMIT 800");
  }elseif ($t == 'wallet') {
    $trans = mysqli_query($con, "SELECT * FROM transactions WHERE type LIKE '%WALLET%' ORDER BY id DESC LIMIT 800");
  }elseif ($t == 'cable') {
    $trans = mysqli_query($con, "SELECT * FROM transactions WHERE  type LIKE '%CABLE%' ORDER BY id DESC LIMIT 800");
  }elseif ($t == 'electric') {
    $trans = mysqli_query($con, "SELECT * FROM transactions WHERE type LIKE '%BILL%' ORDER BY id DESC LIMIT 800");
  }elseif($t == 'all'){
    $trans = mysqli_query($con, "SELECT * FROM transactions ORDER BY id DESC LIMIT 2000");
  }
}else {
  if (isset($_GET['arc'])) {
    if (isset($_GET['s'])) {
      define('D', $_GET['s']);
      // 2022-07-26
      $ns = substr(D, 8).'/'.substr(D, 5, 2).'/'.substr(D, 0, 4);
      $s = $ns;
      echo $s;
      $trans = mysqli_query($con, "SELECT * FROM transactions WHERE date_time LIKE '%$s%' ORDER BY id DESC");
    }elseif (isset($_GET['n'])) {
      $n = intval($_GET['n']);
      $trans = mysqli_query($con, "SELECT * FROM transactions ORDER BY id DESC LIMIT $n");
    }
  }else{
    $trans = mysqli_query($con, "SELECT * FROM transactions ORDER BY id DESC LIMIT 800");
  }
}









?>
<style>

  tr td:last-child {
    width: 1%;
    white-space: nowrap;
  }
</style>
<link rel="stylesheet" href="//cdn.datatables.net/1.12.1/css/jquery.dataTables.min.css">
<div class="content-wrapper">
  <div class="row">
   <div class="col">
    <a href="?" class="btn btn-danger mx-1 mb-1">ALL</a>
    <a href="?t=data" class="btn btn-danger mx-1 mb-1">DATA ONLY</a>
    <a href="?t=airtime" class="btn btn-danger mx-1 mb-1">AIRTIME ONLY</a>
    <a href="?t=wallet" class="btn btn-danger mx-1 mb-1">WALLET ONLY</a>
    <a href="?t=cable" class="btn btn-danger mx-1 mb-1">CABLE TV ONLY</a>
    <a href="?t=electric" class="btn btn-danger mx-1 mb-1">ELECTRICITY ONLY</a>
    <!-- <a href="?t=all" class="btn btn-danger mx-1 mb-1">SHOW ARCHIVE</a> -->
   
    <button class="btn btn-danger mx-1 mb-1" onclick="arcBtn()" id="arcBtn">ARCHIVE</button>
    <div id="theArc" style="display: none">
      <div style="border: 1px solid gray; margin: 20px; border-radius: 5px; padding: 15px">
      <form method="get">
        <span>Number of Archive</span>
        <input type="text" name="n" placeholder="Number of Archive" class="input-control">
        <input type="submit" name="arc" value="Check">
      </form>
    </div>
      <div style="border: 1px solid gray; margin: 20px; border-radius: 5px; padding: 15px">
      <form method="get">
        <span>Searach For a Particular DATE</span>
        <input type="date" min="1997-01-01" max="2030-12-31" name="s" placeholder="Searach For a Particular DATE" class="input-control">
        <input type="submit" name="arc" value="Search">
      </form>
    </div>
    </div>


  </div>
  <div class="col-lg-12 grid-margin stretch-card">
    <div class="card">
      <div class="card-body">
        <h4 class="card-title">Transactions</h4>
        <p class="card-description">
          <code>ALL Trasactions</code>
        </p>
        <div class="table-responsive">
          <table class="table" id="tbs">
            <thead>
              <tr>
                <th>ID</th>
                <th>Desc</th>
                <th>Username/Email</th>
                <th>Channel</th>
                <th>Amount</th>
                <th>Amount Paid</th>
                <th>Amount_Before</th>
                <th>Amount_After</th>
                <th>Date & Time</th>
                <th>Status</th>
                <th>Action</th>
                <th>Ref</th>
                <th>Realtime Response</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php 

              while ($t = mysqli_fetch_assoc($trans)) {


               ?>
               <tr>
                <td><?=$t['id']?></td>
                <td><?php if(str_contains(strtolower($t['type']), 'bill') || str_contains(strtolower($t['type']), 'electricity')) {echo 'BILL PAYMENT';}elseif(str_contains(strtolower($t['type']), 'cable')){echo 'CABLE SUBSCRIPTION';}else{echo $t['t_desc'];} ?></td>
                <td><?= $t['username'] == '' ? $t['email'] : $t['username'] ?></td>
                <td><?=$t['channel']?></td>
                <td>₦<?=parseAmt($t['amount'])?></td>
                <td>₦<?=parseAmt($t['amount_paid'])?></td>
                <td>₦<?=parseAmt($t['oldBal'])?></td>
                <td>₦<?=parseAmt($t['newBal'])?></td>
                <td><?=$t['date_time']?></td>
                <td>
                  <?php 
                  if ($t['status'] == 'success') {
                    ?>
                    <label class="badge badge-success">Success</label>
                    <!-- <span class="badge badge-sm bg-gradient-success">Success</span> -->
                    <?php
                  }elseif($t['status'] == 'pending' || $t['status'] == 'proccessing'){
                   ?>
                   <label class="badge badge-primary">Pending</label>
                   <!-- <span class="badge badge-sm bg-gradient-primary">pending</span> -->
                   <?php
                 }elseif($t['status'] == 'failed'){
                   ?>
                   <label class="badge badge-danger">Failed</label>
                   <!-- <span class="badge badge-sm bg-gradient-primary">pending</span> -->
                   <?php
                 }else{
                   ?>
                   <label class="badge badge-danger"><?=$t['status']?></label>
                   <!-- <span class="badge badge-sm bg-gradient-danger">failed</span> -->
                   <?php
                 }
                 ?>

               </td>
               <td>
                 <label class="badge badge-warning" title="VERIFY" onclick="Swal.fire({
                  title: 'Are you sure?',
                  text: `You are cancelling this transaction, the user will be refunded!`,
                  icon: 'question',
                  showCancelButton: true,
                  cancelButtonColor: '#d33',
                  cancelButtonText: 'No!',
                  confirmButtonText: 'Yes, Cancel'
                }).then((res)=>{
                  if (res.isConfirmed) {
                    window.location.href = `?cancel=<?=$t['id'] ?>`
                  }else{
                    Swal.fire('Cancelled', '', 'info')
                  }
                })" >Cancel</label>
              </td>
              <td><?=strlen($t['ref']) > 32 ? md5($t['ref']) : $t['ref'] ?></td>
              <td><?=$t['rtr'] ?></td>
              <td>


                <?php if (str_contains(strtolower($t['type']), 'bill') && isset($t['t_desc']) && !empty($t['t_desc'])){ $t_det = json_decode($t['t_desc']); ?>

                <!-- Button trigger modal -->
                <button type="button" class="btn bg-gradient-primary" data-bs-toggle="modal" data-bs-target="#t<?=$t['id']?>">
                  VIEW DETAILS
                </button>

                <!-- Modal -->
                <div class="modal fade" id="t<?=$t['id']?>" tabindex="-1" role="dialog" aria-labelledby="t<?=$t['id']?>Label" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title" id="t<?=$t['id']?>Label">BILL TRANSACTION DETAIL</h5>
                      </div>
                      <div class="modal-body">
                        <div class="table-responsive">
                          <table class="table">
                            <tr>
                              <th>Name: </th>
                              <td><?=$t_det->name; ?></td>
                            </tr>
                            <tr>
                              <th>Disco: </th>
                              <td><?=$t_det->disco; ?></td>
                            </tr>
                            <tr>
                              <th>Meter: </th>
                              <td><?=$t_det->meter; ?></td>
                            </tr>
                            <tr>
                              <th>Amount: </th>
                              <td><?=$t_det->amount; ?></td>
                            </tr>
                            <tr>
                              <th>Type: </th>
                              <td><?=$t_det->type; ?></td>
                            </tr>
                            <tr>
                              <th>Token: </th>
                              <td><?=$t_det->token; ?></td>
                            </tr>
                            <tr>
                              <th>Phone: </th>
                              <td><?=$t_det->phone; ?></td>
                            </tr>
                            <tr>
                              <th>Address: </th>
                              <td><?=$t_det->address; ?></td>
                            </tr>
                            <tr>
                              <th>Date: </th>
                              <td><?=$t_det->date; ?></td>
                            </tr>
                            <tr>
                              <th>Status: </th>
                              <td><?=$t_det->status; ?></td>
                            </tr>
                          </table>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn bg-gradient-secondary" data-bs-dismiss="modal">Close</button>
                        
                      </div>
                    </div>
                  </div>
                </div>
                
              <?php }elseif(str_contains(strtolower($t['type']), 'cable') && isset($t['t_desc']) && !empty($t['t_desc'])){ $t_det = json_decode($t['t_desc']); ?>
              <!-- Button trigger modal -->
              <button type="button" class="btn bg-gradient-primary" data-bs-toggle="modal" data-bs-target="#t<?=$t['id']?>">
                VIEW DETAILS
              </button>
              <!-- Modal -->
              <div class="modal fade" id="t<?=$t['id']?>" tabindex="-1" role="dialog" aria-labelledby="t<?=$t['id']?>Label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title" id="t<?=$t['id']?>Label">CABLE TRANSACTION DETAIL</h5>
                    </div>
                    <div class="modal-body">
                      <div class="table-responsive">
                        <table class="table">
                          <tr>
                            <th>Name: </th>
                            <td><?=$t_det->name; ?></td>
                          </tr>
                          <tr>
                            <th>Cable: </th>
                            <td><?=$t_det->cable; ?></td>
                          </tr>
                          <tr>
                            <th>Card: </th>
                            <td><?=$t_det->card; ?></td>
                          </tr>

                          <tr>
                            <th>Plan: </th>
                            <td><?=$t_det->plan; ?></td>
                          </tr>
                          <tr>
                            <th>Amount: </th>
                            <td>₦<?=$t_det->amount; ?></td>
                          </tr>
                          <tr>
                            <th>Date: </th>
                            <td><?=$t_det->date; ?></td>
                          </tr>
                          <tr>
                            <th>Status: </th>
                            <td><?=$t_det->status; ?></td>
                          </tr>
                        </table>
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn bg-gradient-secondary" data-bs-dismiss="modal">Close</button>

                    </div>
                  </div>
                </div>
              </div>
            <?php } ?>


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
</div>

<!-- content-wrapper ends -->
<?php 
include_once 'footer.php';

?>
<script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
<script src="//cdn.datatables.net/1.12.1/js/jquery.dataTables.min.js"></script>
<script>
  $(document).ready(function() {
    $('#tbs').DataTable({
      order: [[0, 'desc']],
    });
  });

function arcBtn() {
  $('#theArc').slideToggle();
}

</script>