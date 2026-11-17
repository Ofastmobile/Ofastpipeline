<?php 
include 'header.php';
if (isset($_POST['cost'])) {
  $cost = $_POST['new_cost'];
  if (mysqli_query($con, "UPDATE config SET refBonus = '$cost'")) {
    echo "<script>Swal.fire('Updated Successfully', '', 'success').then(()=>{window.location.replace('configurations')})</script>";
  }else{
    echo "<script>Swal.fire('Unable to Update', '', 'error').then(()=>{window.location.replace('configurations')})</script>";
  }
}
?>

<div class="content">
  <div class="row">
    <div class="col-md-8">
      <div class="card card-chart">
        <div class="card-header">
          <h5 class="card-title">REFERRAL BONUS AMOUNT</h5>
        </div>
        <div class="card-body">
          <form method="post">
            <div class="form-group">
              <input type="text" class="form-control" name="new_cost" value="<?=$config['refBonus'] ?>" required>
            </div>
            <input type="submit" name="cost" value="SAVE" class="btn btn-primary">
          </form>
          
        </div>
        <div class="card-footer">

          <hr />
          <div class="card-stats">

          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php 
include 'footer.php';
?>