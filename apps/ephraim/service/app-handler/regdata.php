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
<table class="tg">
<thead>
<tr>
<th class="tg-black">ID</th>
<th class="tg-black">Name</th>
<th class="tg-black">Email</th>
<th class="tg-black">Contact No</th>
<th class="tg-black">NIC Number</th>
<th class="tg-black"></th>
</tr>
</thead>
<tbody>
    <?php foreach($data as $key=>$value):?>
    <tr>
        <td><?=$value->id?></td>
        <td><?=$value->name?></td>
        <td><?=$value->email?></td>
        <td>	
        <a href="tel:<?=$value->contactno?>"><?=$value->contactno?></a></td>
        <td><?=$value->id_number?></td>
        <td><a href="https://www.davvag.com/components/ephraim/app-handler/service/RegxForm?ref=<?=$value->id?>">Download</a></td>
    </tr>

    <?php endforeach;?>
</tbody>
</table>
</body>
</html>