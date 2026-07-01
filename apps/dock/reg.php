<?php 
$nameError = $emailError = $passWordErrr = $fullnameError = $addressError = $cityError = $countryError = $error = "";
$validate=true;
if(isset($_SESSION["regadmin"] )){
    $data=new stdClass();
    foreach ($_POST as $key => $value) {
        $data->{$key}=is_string($value) ? trim($value) : $value;
    }
    if (!isset($data->nationalidcardnumber) && isset($data->xxxxxxx)) {
        $data->nationalidcardnumber = $data->xxxxxxx;
    }
    //var_dump($data);
    //exit();
    if (empty($_POST["email"])) {
        $emailError = "Email is required";
        $validate=false;
      } else {
        $email = $_POST["email"];
        // check if e-mail address is well-formed
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
          $emailError = "Invalid email format";
          $validate=false;
        }
      }

      if (empty($_POST["name"])) {
        $nameError = "Name is required";
        $validate=false;
      }
      if (empty($_POST["address"])) {
        $addressError = "Address is required";
        $validate=false;
      }
      if (empty($_POST["city"])) {
        $cityError = "City is required";
        $validate=false;
      }
      if (empty($_POST["country"])) {
        $countryError = "Country is required";
        $validate=false;
      }
      if (empty($_POST["userfullname"])) {
        $fullnameError = "Name is required";
        $validate=false;
      }
      if (empty($_POST["password"])) {
        $passWordErrr = "Password is required";
        $validate=false;
      } elseif (empty($_POST["confirmpassword"])) {
        $passWordErrr = "Please confirm your password";
        $validate=false;
      } elseif ($_POST["password"] !== $_POST["confirmpassword"]) {
        $passWordErrr = "Passwords do not match";
        $validate=false;
      }
      if(!$validate){
        $error="Validation Error";
        require_once (dirname(__FILE__) . "/pages/signup.php");
        exit();
      }

    if(isset($_POST["requestid"]) && $_POST["requestid"]===$_SESSION["regadmin"]){
        $data->otherdata=new stdClass();
        $data->otherdata->usersname=$data->email;
        $data->otherdata->password=$data->password;

        $r=Auth::NewDomain($data);
        if (isset($r->domain) && $data->domain === $r->domain) {
            unset($_SESSION["regadmin"]);
            header("Location: $redirectUrl");
            exit();
        } else {
            $error = isset($r->message) ? $r->message : "Error Registering.";
            require_once (dirname(__FILE__) . "/pages/signup.php");
            exit();
        }
    }else{
        $error="Unauthorized registration request.";
        require_once (dirname(__FILE__) . "/pages/signup.php");
        exit();
    }
}else{
    $error="Unauthorized registration request.";
    require_once (dirname(__FILE__) . "/pages/signup.php");
    exit();
}



?>
