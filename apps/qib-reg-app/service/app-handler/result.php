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
    <th class="tg-black" colspan="5"><h1>SCHOLARSHIP ADMISSION IQ Test Result</h1></th>
    
  </tr>
</thead>
<tbody>
  
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
    <td class="tg-0pky-name">INDEX NUMBER</td>
    <td class="tg-0pky-value" colspan="2"> 
    <table style="width: 360Px;">
      <tr>
      <?=$this->drawBox(12,$data->index_number)?>
      </tr>
    </table>
  </td>
  </tr>
  <tr>
    <td class="tg-0pky-name">IQ Result</td>
    <td class="tg-0pky-value" colspan="2"><?=$data->iq_result?></td>
  </tr>
  <tr>
    <td class="tg-0pky-name">EXAM DATE</td>
    <td class="tg-0pky-value" colspan="2">16<sup>th</sup> June 2024</td>
  </tr>
  <tr>
    <td class="tg-0pky-name">COUNTRY</td>
    <td class="tg-0pky-value" colspan="2">Sri Lanka</td>
  </tr>
  <tr>
    <td class="tg-0pky-name">EXAM CENTER</td>
    <td class="tg-0pky-value" colspan="2"><?=$data->center?></td>
  </tr>
  
</tbody>
</table>
<p>This is a Golden Opportunity for you to showcase your IQ knowledge to world by 
World Recognized UK Based International Education Scholar Center.
You can get a UK verified Internationally recognized certificate and QR code
to add it in your CVs or for future endeavor</p>
</body>
</html>