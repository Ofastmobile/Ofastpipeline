<?php 
include 'header.php';
$trans;
$username = $data['username'];
$email = $data['email'];
if (isset($_GET['t'])) {
  $t = $_GET['t'];
  if ($t == 'data') {
    $trans = mysqli_query($con, "SELECT * FROM transactions WHERE (email = '$email' OR username = '$username')  AND type LIKE '%DATA%' ORDER BY id DESC LIMIT 1000");
  }elseif ($t == 'airtime') {
    $trans = mysqli_query($con, "SELECT * FROM transactions WHERE (email = '$email' OR username = '$username') AND type LIKE '%AIRTIME%' ORDER BY id DESC LIMIT 1000");
  }elseif ($t == 'wallet') {
    $trans = mysqli_query($con, "SELECT * FROM transactions WHERE (email = '$email' OR username = '$username') AND type LIKE '%WALLET%' ORDER BY id DESC LIMIT 1000");
  }elseif ($t == 'cable') {
    $trans = mysqli_query($con, "SELECT * FROM transactions WHERE (email = '$email' OR username = '$username') AND type LIKE '%CABLE%' ORDER BY id DESC LIMIT 1000");
  }elseif ($t == 'electric') {
    $trans = mysqli_query($con, "SELECT * FROM transactions WHERE (email = '$email' OR username = '$username') AND type LIKE '%BILL%' ORDER BY id DESC LIMIT 1000");
  }elseif($t == 'all'){
    $trans = mysqli_query($con, "SELECT * FROM transactions WHERE (email = '$email' OR username = '$username') ORDER BY id DESC LIMIT 3000");
  }
}else {
  $trans = mysqli_query($con, "SELECT * FROM transactions WHERE (email = '$email' OR username = '$username') ORDER BY id DESC LIMIT 1000");
}

?>
<link rel="stylesheet" href="//cdn.datatables.net/1.12.1/css/jquery.dataTables.min.css">
<style>
  tr td:last-child {
    width: 1%;
    white-space: nowrap;
  }
</style>
<div class="" style="margin-top: 50px;" >

</div>
</div>
<div class="container-fluid">
  <div class="row">
    <div class="col">
      <a href="?" class="btn btn-danger">ALL</a>
      <a href="?t=data" class="btn btn-danger">DATA ONLY</a>
      <a href="?t=airtime" class="btn btn-danger">AIRTIME ONLY</a>
      <a href="?t=wallet" class="btn btn-danger">WALLET ONLY</a>
      <a href="?t=cable" class="btn btn-danger">CABLE TV ONLY</a>
      <a href="?t=electric" class="btn btn-danger">ELECTRICITY ONLY</a>
      <!--<a href="?t=data-card" class="btn btn-danger">DATA CARD ONLY</a>-->
      <!--<a href="?t=all" class="btn btn-danger">SHOW ARCHIVE</a>-->
    </div>
    <div class="col-12 ">
      <div class="card mb-4 px-2">
        <div class="card-header pb-0">
          <h6>TRANSACTIONS</h6>
        </div>
        <div class="card-body px-0 pt-0 pb-2">
          <div class="table-responsive p-0">
            <table class="table align-items-center mb-0 " id="trs">
              <thead>
                <tr>
                  <th class="text-uppercase  text-xxs font-weight-bolder opacity-7 ps-2">ID</th>
                  <th class="text-uppercase  text-xxs font-weight-bolder opacity-7 ps-2">Desc</th>
                  <th class="text-uppercase  text-xxs font-weight-bolder opacity-7 ps-2">Channel</th>
                  <th class="text-uppercase  text-xxs font-weight-bolder opacity-7 ps-2">Amount</th>
                  <th class="text-uppercase  text-xxs font-weight-bolder opacity-7 ps-2">Amount Paid</th>
                  <th class="text-uppercase  text-xxs font-weight-bolder opacity-7 ps-2">Amount Before</th>
                  <th class="text-uppercase  text-xxs font-weight-bolder opacity-7 ps-2">Amount After</th>
                  <th class="text-uppercase  text-xxs font-weight-bolder opacity-7 ps-2">Date & Time</th>
                  <th class="text-center text-uppercase  text-xxs font-weight-bolder opacity-7">Status</th>
                  <th class="text-uppercase  text-xxs font-weight-bolder opacity-7 ps-2">Receipt</th>
                  <th class="text-uppercase  text-xxs font-weight-bolder opacity-7 ps-2">Final Status</th>
                  <th class="text-uppercase  text-xxs font-weight-bolder opacity-7 ps-2">RESPONSE</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                // $tt = mysqli_query($con, "SELECT * FROM transactions ORDER BY id DESC LIMIT 100");
                while ($t = mysqli_fetch_assoc($trans)) {


                 ?>
                 

                 
                 <tr>
                  <td><?=$t['id']?></td>
                  <td >
                    <div class="d-flex px-2 py-1">
                      <div class="d-flex flex-column justify-content-center">
                        <h6 class="mb-0 text-sm"><?=$t['type'] ?></h6>

                        <p class="text-xs  mb-0"><?=strlen($t['ref']) > 32 ? md5($t['ref']) : $t['ref'] ?></p>
                      </div>
                    </div>
                  </td>
                  <td>


                    <?php if (str_contains(strtolower($t['type']), 'bill') && isset($t['t_desc']) && !empty($t['t_desc'])){ $t_det = json_decode($t['t_desc']); ?>

                    <!-- Button trigger modal -->
                    <button type="button" class="btn bg-gradient-primary  text-white" data-toggle="modal" data-target="#t<?=$t['id']?>">
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
                                  <td>₦<?=$t_det->amount; ?></td>
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
                            <button type="button" class="btn bg-gradient-secondary" data-dismiss="modal">Close</button>

                          </div>
                        </div>
                      </div>
                    </div>

                  <?php }elseif(str_contains(strtolower($t['type']), 'cable') && isset($t['t_desc']) && !empty($t['t_desc'])){ $t_det = json_decode($t['t_desc']); ?>
                  <!-- Button trigger modal -->
                  <button type="button" class="btn bg-gradient-primary text-white" data-toggle="modal" data-target="#t<?=$t['id']?>">
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
                          <button type="button" class="btn bg-gradient-secondary" data-dismiss="modal">Close</button>

                        </div>
                      </div>
                    </div>
                  </div>
                <?php }else{ ?>
                  <p class="text-xs font-weight-bold mb-0"><?=$t['channel'] ?></p>
                <?php } ?>


              </td>
              <td>
                <p class="text-xs font-weight-bold mb-0">₦<?= parseAmt($t['amount']) ?></p>
              </td>
              <td>
                <p class="text-xs font-weight-bold mb-0">₦<?= $t['amount_paid'] ?></p>
              </td>
              <td>
                <p class="text-xs font-weight-bold mb-0">₦<?=$t['oldBal']  ?></p>
              </td>
              <td>
                <p class="text-xs font-weight-bold mb-0">₦<?=$t['newBal'] ?></p>
              </td>
              <td class="align-middle text-center">
                <span class=" text-xs font-weight-bold"><?=$t['date_time'] ?></span>
              </td>
              <td class="align-middle text-center text-sm">

                <?php 
                if ($t['status'] == 'success') {
                  ?>
                  <span class="badge badge-sm bg-gradient-success">SUCCESS</span>
                  <?php
                }elseif($t['status'] == 'pending' || $t['status'] == 'proccessing'){
                 ?>
                 <span class="badge badge-sm bg-gradient-primary">pending</span>
                 <?php
               }else{
                 ?>
                 <span class="badge badge-sm bg-gradient-danger">failed</span>
                 <?php
               }

               ?>
             </td>
             
             <td>
              <?php if (str_contains($t['type'], 'AIRTIME') || str_contains($t['type'], 'DATA') || str_contains($t['type'], 'CARD')): ?>
              <a href="invoice?tid=<?=$t['id']?>" title="RECEIPT" class='btn btn-warning'>VIEW RECEIPT</a>
            <?php else: ?>
              <a href="receipt?tid=<?=$t['id']?>" title="RECEIPT" class='btn btn-warning'>VIEW RECEIPT</a>
            <?php endif ?>
          </td>
          <td><?= $t['final_st'] ?></td>
          <td><?= str_contains($t['rtr'], ' balance') ?  explode('Your', $t['rtr'])[0] : $t['rtr']?></td>
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

<script>




  let dis = <?=$_SESSION['config']['airtime_discount'] ?>;
  const mtnEx = "<?=$config['mtnEx']?>";
  const airtelEx = "<?=$config['airtelEx']?>";
  const gloEx = "<?=$config['gloEx']?>";
  const mobileEx = "<?=$config['mobileEx']?>";

  function checknumber(num){
    var mtnNumbers = mtnEx.split(',')
    mtnNumbers.forEach((item)=>{
      if(item.substring(0,4) === num.substring(0,4)){
        console.log('MTN')
        return 'MTN';
      }
    })

    var gloNumbers = gloEx.split(',')
    gloNumbers.forEach((item)=>{
      if(item.substring(0,4) === num.substring(0,4)){
        console.log('GLO')
        return 'GLO';
      }
    })

    var airtelNumbers = airtelEx.split(',')
    airtelNumbers.forEach((item)=>{
      if(item.substring(0,4) === num.substring(0,4)){
        console.log('AIRTEL')
        return 'AIRTEL';
      }
    })

    var mobileNumbers = mobileEx.split(',')
    mobileNumbers.forEach((item)=>{
      if(item.substring(0,4) === num.substring(0,4)){
        console.log('9MOBILE')
        return '9MOBILE';
      }
    })
  }

  function validateNumber(num, net){
    let final = false;
    if (net === 'MTN') {
      var mtnNumbers = mtnEx.split(',')
      mtnNumbers.forEach((item)=>{
        if(item.substring(0,4) === num.substring(0,4) && net === 'MTN'){
          final = true;
        }
      })
    }

    if (net === 'GLO') {
      var gloNumbers = gloEx.split(',')
      gloNumbers.forEach((item)=>{
        if(item.substring(0,4) === num.substring(0,4) && net === 'GLO'){
          final = true;
        }
      })
    }

    if (net === 'AIRTEL') {
      var airtelNumbers = airtelEx.split(',')
      airtelNumbers.forEach((item)=>{
        if(item.substring(0,4) === num.substring(0,4) && net === 'AIRTEL'){
          final = true;
        }
      })
    }

    if (net === '9MOBILE') {
      var mobileNumbers = mobileEx.split(',')
      mobileNumbers.forEach((item)=>{
        if(item.substring(0,4) === num.substring(0,4) && net === '9MOBILE'){
          final = true;
        }
      })
    }

    return final;
  }




  function buy(net, amount, phone, type){

    const phones = phone.split(',');
    var pin = '';

    function buyNow(){
      Swal.fire({
        title: 'Are you sure to continue?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: "Yes, I'm Sure",
        cancelButtonText: 'No'
      }).then((res)=>{
        if (res.isConfirmed) {
         (<?=$data['pin'] == '' ? 0 : 1 ?>) ? Swal.fire({
          title: 'Your PIN',
          input: 'number',
          inputAttributes: {
            autocapitalize: 'off'
          },
          showCancelButton: true,
          confirmButtonText: 'GO',
          showLoaderOnConfirm: true,
          preConfirm: (npin) => {
            return npin;
          },
          allowOutsideClick: () => !Swal.isLoading()
        }).then((result) => {
          if (result.isConfirmed) {

            pin = result.value;
            SlickLoader.setText("Loading...","Please Wait");
            SlickLoader.enable();
            $.post('asyncAir', {topup: 'topup', network: net, amount: amount, phone: phone, pin: pin, type}, (r, s)=>{
              SlickLoader.disable();
              console.log(s)
              console.log(r)
              if (r == '303') {
                Swal.fire('Insufficient Wallet Balance', '', 'error');
              }else if (r == '350') {
                Swal.fire('Amount Lower Than  ₦100', '', 'error');
              }else if (r == '311') {
                Swal.fire('Invalid Phone Number', '', 'error')
              }else if (r == '329') {
                Swal.fire('Wrong PIN', 'Please check and try again', 'error')
              }else if (r == '319') {
                Swal.fire('TECHNICAL ERROR', 'Error 319 | Will be fixed soon', 'error');
              }else if (r == '202') {
                Swal.fire('Successful', `Airtime top-up of ${amount} to ${phone} is successful`, 'success');
              }else if (r == '344') {
                Swal.fire('INVALID PHONE NUMBER', `Invalid ${net} Number`, 'error');
              }else if (r == '504') {
                Swal.fire('UNKNOWN ERROR', 'Error 504 | Please contact the admin if the error persist', 'error');
              }else{
                Swal.fire('TECHNICAL ERROR', `Error 521 | Will be fixed soon | ${r}`, 'error');
              }
            })
          }
        }) : Swal.fire('PIN not set', 'Please set your transaction PIN. Press OK to go to PIN reset page', 'info').then((ress)=>{
          if (ress.isConfirmed) {
            window.location.href = 'pin_manage';
          }
        })

      }else{
        Swal.fire('Cancelled', '', 'info')
      }
    })

    }
    function beginBuy(){
      return net === '' ? Swal.fire('Please Choose Network', '', 'error') : (amount === '' || amount === 0) ? Swal.fire('Invalid Amount', '', 'error') : (phone === '' || phone.length !== 11) ? Swal.fire('Invalid Phone Number', '', 'error') : validateNumber(phone, net) ? buyNow() : Swal.fire(`This is not a valid ${net} Number`, 'Please Check and Try Again', 'error') ;
    }
    if (phone.length > 11) {
      phones.forEach((item, i)=>{
        if (item.length != 11) {
          Swal.fire(`The Phone Number at the Position ${i+1} is not valid`, '', 'error');
          stopHere();
        }else if (!validateNumber(item, net)) {
          Swal.fire(`The Phone Number at the Position ${i+1} is not a valid ${net} Number`, '', 'error');
          stopHere();
        }
      });
      return net === '' ? Swal.fire('Please Choose Network', '', 'error') : (amount === '' || amount === 0) ? Swal.fire('Invalid Amount', '', 'error') : buyNow();
    }else{
      return beginBuy();
    }

    console.log(pin);
  }

  function CheckNumeric(e) {
    if ((e.which < 48 || e.which > 57) & e.which != 8 && e.which != 44) {
      e.preventDefault();
      return false;
    }

  }    





  function topup(net, dtyp, data_size, phone){
    // let net = document.getElementById('net').value;
    // let dtyp = document.getElementById('dtyp').value;
    // let data_size = document.getElementById('data_size').value;
    // let phone = document.getElementById('phone').value;
    function beginTop(){
      Swal.fire({
        title: 'Are you sure to continue?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: "Yes, I'm Sure",
        cancelButtonText: 'No'
      }).then((res)=>{
        if (res.isConfirmed) {
          (<?=$data['pin'] == '' ? 0 : 1 ?>) ? Swal.fire({
            title: 'Your PIN',
            input: 'number',
            inputAttributes: {
              autocapitalize: 'off'
            },
            showCancelButton: true,
            confirmButtonText: 'GO',
            showLoaderOnConfirm: true,
            preConfirm: (npin) => {
              return npin;
            },
            allowOutsideClick: () => !Swal.isLoading()
          }).then((result) => {
            if (result.isConfirmed) {
              pin = result.value;
              SlickLoader.setText("Loading...","Please Wait");
              SlickLoader.enable();
              $.post('topUp', {network: net, data_plan: data_size, phone: phone, topUp: dtyp, pin: pin}, (r)=>{
                SlickLoader.disable();
                if (r == '200') {
                  Swal.fire('Data Top Up Successful', `${net.toUpperCase()} ${dtyp.toUpperCase()} ${dPlan} TO ${phone} IS SUCCESSFUL`, 'success')
                }else if(r == '404'){
                  Swal.fire('Something is missing','','error')
                }else if(r == '302'){
                  Swal.fire('INSUFFICIENT FUND','','error')
                }else if(r == '009'){
                  Swal.fire('Invalid Phone Number','','error')
                }else if (r == '329') {
                  Swal.fire('Wrong PIN', 'Please check and try again', 'error')
                }else if (r == '732') {
                  Swal.fire('Daily Limit Exceed', `You have exceeded the daily limit and you must verify your account to continue for today. Press OK to go to Account Verification Page`, 'error').then((ress)=>{
                    if (ress.isConfirmed) {
                      window.location.href = 'kyc';
                    }
                  })
                }else if(r == '552'){
                  Swal.fire('UNKNOWN ERROR', 'Error 504 | Please contact the admin if the error persist', 'error');
                }else if(r == '403'){
                  Swal.fire('INVALID PHONE NUMBER','Please Check and Try Again','error')
                }else if(r == '558'){
                  Swal.fire(`IT SEEMS ${net.toUpperCase()} ${dtyp.toUpperCase()} DATA IS CURRENTLY DOWN`,'Please Try Again Later','error')
                }else{
                  Swal.fire('TECHNICAL ERROR',r,'error')
                }
              })
            }
          }) : Swal.fire('PIN not set', 'Please set your transaction PIN. Press OK to go to PIN reset page', 'info').then((ress)=>{
            if (ress.isConfirmed) {
              window.location.href = 'pin_manage';
            }
          })
          
          
        }else{
          Swal.fire('Cancelled!😔')
        }
      })
    }
    if (net == '') {
      return Swal.fire('Please Choose Network', '', 'error')
    }else if (dtyp == '') {
      return Swal.fire('Please Choose Data Type', '', 'error')
    }else if (data_size == '') {
      return Swal.fire('Please Choose Data Size', '', 'error')
    }else if (phone == '') {
      return Swal.fire('Type Phone Number', '', 'error')
    }else if (phone.length < 11) {
      return Swal.fire('Invalid Phone Number', '', 'error')
    }else if (phone.length > 11) {
      const phones = phone.split(',');
      phones.forEach((item, i)=>{
        // console.log(item)
        var poss = stringifyNumber(i+1);
        if (item.length != 11) {
          Swal.fire(`The Phone Number at the ${poss.toUpperCase()} Position is not valid`, '', 'error');
          mustStopHere();
        }else if (!validateNumber(item, net)) {
          Swal.fire(`The Phone Number at the ${poss.toUpperCase()} Position is not valid ${net.toUpperCase()} Number`, '', 'error');
          mustStopHere();
        }

      });
      beginTop()
      // return Swal.fire('Invalid Phone Number', '', 'error')
    }else{
      if (validateNumber(phone, net)) {
        beginTop();
      }else{
        Swal.fire(`This is not a valid ${net.toUpperCase()} Number`, '', 'error')
      }
    }

  }
  function CheckNumeric(e) {
    if ((e.which < 48 || e.which > 57) & e.which != 8 && e.which != 44) {
      e.preventDefault();
      return false;
    }

  }  
</script>
<?php 
include 'footer.php';
?>
<script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
<script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.11.4/js/jquery.dataTables.js"></script>
<script type="text/javascript">
  $(document).ready(function() {
    $('#trs').DataTable({
      order: [[0, 'desc']],
    });
  });
</script>

