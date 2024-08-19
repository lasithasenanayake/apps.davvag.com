<html>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
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
    margin:10px;
}
</style>
<body>


<table class="tg"><thead>
  <tr>
    <th class="tg-black" colspan="3"><h1>Registration Pass</h1></th>
    
  </tr>
</thead>
<tbody>
<tr>
    <td class="tg-0pky-name">Registration ID</td>
    <td class="tg-0pky-value" colspan="2">
      
        <?=$data->id."-".$data->profileid."-".$data->regdate?>
    </td>
  </tr>
  <tr>
    <td class="tg-0pky-name">FULL NAME</td>
    <td class="tg-0pky-value" colspan="2">
      
        <?=$data->name?>
    </td>
  </tr>
  <tr>
    <td class="tg-0pky-name">NIC NUMBER OR PASSPORT NO </td>
    <td class="tg-0pky-value" colspan="2"> 
    
      <?php
      echo $data->id_number;
      ?>
      
  </td>
  </tr>
  <tr>
    
    <td class="tg-0pky-name">Details</td>
    <td class="tg-0pky-value" colspan="2"><?$res=SOSSData::Query("attr_projects","ID:".$data->projectid);
    if(count($res->result)>0){
      echo $res->result[0]->reg_complete;
    }
    ?></td>
  </tr>
  
  <tr>
    <td class="tg-0pky-name">Medical Health Condition</td>
    <td class="tg-0pky-value" colspan="2"> <?=$data->medicremarks?></td>
  </tr>
  <tr>
    <td class="tg-0pky-name">Contact Person</td>
    <td class="tg-0pky-value" colspan="2"><?php $res=SOSSData::Query("profile","id:".$data->referelid);
    if(count($res->result)>0)
      echo $res->result[0]->name ."(".$res->result[0]->contactno.")";
    else{
      echo "Lasitha (0719285741)";
    }

    ?>  </td>
  </tr>
  <tr>
    <td class="tg-0pky-name">Signature</td>
    <td class="tg-0pky-value" colspan="2"> </td>
  </tr>
  <tr>
    <td class="tg-0pky-name">Guardian Signature  (Only for Under 18)</td>
    <td class="tg-0pky-value" colspan="2"> </td>
  </tr>
</tbody>
</table>
<h2>Please Print this and bring with you</h2>
</body>
</html>