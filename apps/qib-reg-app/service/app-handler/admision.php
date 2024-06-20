<html>
<style>
.tb{
  width: 30px;
  height: 30Px;
  border-color:black;
  border-style:solid;
  border-width:1px;
  font-size: small;
}
.tg-0pky-name{
    width: 150Px;
    font-size: large;
}
.tg-0pky-value{
  width: 500Px;
  font-size: small;
}
.tg  {border-collapse:collapse;border-spacing:0;width: 100%;}
.tg td{border-color:black;border-style:solid;border-width:1px;font-family:Arial, sans-serif;font-size:14px;
  overflow:hidden;padding:5px 5px;word-break:normal;}
.tg th{border-color:black;border-style:solid;border-width:1px;font-family:Arial, sans-serif;font-size:14px;
  font-weight:normal;overflow:hidden;padding:10px 5px;word-break:normal;}
.tg .tg-0pky{border-color:inherit;text-align:left;vertical-align:top;width: 150;}
.tg-black{background-color: black;color: white;text-align: center;}
.tg-black-left{background-color: black;color: white;text-align: left;}
@page {
    margin:2px;
}
</style>
<body>


<table class="tg"><thead>
  <tr>
    <th class="tg-black" colspan="5"><h1>SCHOLARSHIP ADMISSION</h1></th>
    
  </tr>
</thead>
<tbody>
  <tr>
    <td class="tg-black-left" colspan="3">IQSL24 0858</td>
    <td class="tg-0pky" rowspan="7">
      <img src="https://scholarships.qibcampus.com/assets/qib-reg-app/side.png" width="150px">

    </td>
  </tr>
  <tr>
    <td class="tg-0pky-name">FULL NAME</td>
    <td class="tg-0pky-value" colspan="2">
      <?php
       $name= explode(" ",$data->name)
      ?>
      <?php if(count($name)<5):?>
      <table style="width:500Px;height: 130Px;">
        <tr>
          <?=$this->drawBox(15,(isset($name[0])?$name[0]:""))?>
        </tr>
        <tr>
          <?=$this->drawBox(15,(isset($name[1])?$name[1]:""))?>
        </tr>
        <tr>
          <?=$this->drawBox(15,(isset($name[2])?$name[2]:""))?>
        </tr>
        <tr>
          <?=$this->drawBox(15,(isset($name[3])?$name[3]:""))?>
        </tr>
        <?php if(count($name)>4):?>
          <tr>
            <?=$this->drawBox(15,(isset($name[4])?$name[4]:""))?>
          </tr>
        <?php endif;?>
      </table>
      <?php else: ?>
        <?=$data->name?>
      <?php endif; ?>
    </td>
  </tr>
  <tr>
    <td class="tg-0pky-name">NIC NUMBER</td>
    <td class="tg-0pky-value" colspan="2"> 
    <table style="width: 360Px;">
      <tr>
      <?=$this->drawBox(12,$data->id_number)?>
      </tr>
    </table>
  </td>
  </tr>
  <tr>
    <td class="tg-0pky-name">EXAM DATE</td>
    <td class="tg-0pky-value" colspan="2">16<sup>th</sup> June 2024</td>
  </tr>
  <tr>
    <td class="tg-0pky-name">EXAM TIME</td>
    <td class="tg-0pky-value" colspan="2">11:30am to 1:30pm</td>
  </tr>
  <tr>
    <?php 
      $examCenter =array(
        "Anuradhapura"=>"Walisinghe Harischandra Maha Vidyalaya <br/>No:A12, Anuradhapura.50000,",
        "Badulla"=>"Badulla Central College <br/>Keppetipola Road,<br/>Badulla.",
        "Batticaloa"=>"Hindu College<br/>Batticaloa,30000",
        "Colombo"=>"Mahanama College<br/>Colombo 03.",
        "Galle"=>"Vidyaloka College<br/>Wakwella Rd,Galle.",
        "Jaffna"=>"St. Patrick's College<br/>Jaffna.",
        "Kandy"=>"Viharamahadevi Girls College<br/>Kandy.",
        "Kalmunai"=>"Zahira College <br/>Road Sainthamaruthu,Kalmunai.32300",
        "Puttalam"=>"Fathima Balika Maha Vidyalaya<br/>Poles Road,Puttalam",
        "Vavuniya"=>"Saivapiragasa ladies college<br/>Urban Council Rd,Vavuniya.",
        "Chilaw"=>"Ananda National College <br/>Chilaw"
      );
      /*<option value="Anuradhapura">Anuradhapura</option>
              <option value="Badulla">Badulla</option>
              <option value="Batticaloa">Batticaloa</option>
              <option value="Chilaw">Chilaw</option>
              <option value="Colombo">Colombo</option>
              <option value="Galle">Galle</option>
              <option value="Jaffna">Jaffna</option>
              <option value="Kalmunai">Kalmunai</option>
              <option value="Kandy">Kandy</option>
              <option value="Vavuniya">Vavuniya</option>*/
    ?>
    <td class="tg-0pky-name">EXAM CENTER</td>
    <td class="tg-0pky-value" colspan="2"><?=isset($examCenter[$data->center])?$examCenter[$data->center]:$data->center?></td>
  </tr>
  <tr>
    <td class="tg-0pky-name">SELECTED COUNTRY</td>
    <td class="tg-0pky-value" colspan="2">
    <table style="width: 450Px;">
      <tr>
        <td colspan="3">Code</td><td colspan="12">Country</td>
      </tr>
      <tr>
      <?=$this->drawBox(3,$data->countrycode)?>
      <?=$this->drawBox(12,$data->country)?>
      </tr>
    </table>
    </td>
  </tr>
</tbody>
</table>
<div style="text-align: center;">
<img src="https://scholarships.qibcampus.com/assets/qib-reg-app/buttom.png.png" width="80%" style="display: block;margin-left: auto;margin-right: auto;">
</div>
<?php
$res=SOSSData::Query("profile","id:".$data->referelid);
$text="AD"; 
if(count($res->result)>0){
  $text.=$res->result[0]->organization!=""?"-".$res->result[0]->organization."-":"";
}
$text=$text."0858";
?>
<h3 style="text-align: center;"><?=$text?></h3>
</body>
</html>