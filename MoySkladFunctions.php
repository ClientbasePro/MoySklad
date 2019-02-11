<?php

  // Интеграция CRM Clientbase и МойСклад (MoySklad)
  // https://ClientbasePro.ru
  // https://online.moysklad.ru/api/posap/1.0/doc/index.html
  
require_once 'common.php';

    // функции

    // возвращает массив - список торговых точек
	// https://online.moysklad.ru/api/posap/1.0/doc/index.html#pos_begin-%D1%82%D0%BE%D1%87%D0%BA%D0%B0-%D0%BF%D1%80%D0%BE%D0%B4%D0%B0%D0%B6-%D1%82%D0%BE%D1%87%D0%BA%D0%B0-%D0%BF%D1%80%D0%BE%D0%B4%D0%B0%D0%B6
function MoySklad_GetRetailstores() {
  $headers = array('Content-Type: application/json', 'Authorization: Basic '.base64_encode(MOYSKLAD_LOGIN.':'.MOYSKLAD_PASSWORD));        
  $curl = curl_init();
  curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_URL, MOYSKLAD_URL.'/admin/retailstore');
  $stores = json_decode(curl_exec($curl), true);    
  curl_close($curl);
  return $stores;
}


    // возвращает массив - токен (token) и кассир (uid) торговой точки $retailstoreId
	// https://online.moysklad.ru/api/posap/1.0/doc/index.html#pos_begin-%D0%BF%D1%80%D0%B8%D0%B2%D1%8F%D0%B7%D0%BA%D0%B0-%D1%82%D0%BE%D1%87%D0%BA%D0%B8,-%D0%BF%D0%BE%D0%BB%D1%83%D1%87%D0%B5%D0%BD%D0%B8%D0%B5-%D1%82%D0%BE%D0%BA%D0%B5%D0%BD%D0%B0-%D0%BF%D0%BE%D0%BB%D1%83%D1%87%D0%B5%D0%BD%D0%B8%D0%B5-token
function MoySklad_GetRetailstoreToken($retailstoreId='') {
  if (!$retailstoreId) {
	$stores = MoySklad_GetRetailstores();
	if ($retailstoreId=$stores['rows'][0]['id']) 1;
	else return false;
  } 
  $headers = array('Content-Type: application/json', 'Authorization: Basic '.base64_encode(MOYSKLAD_LOGIN.':'.MOYSKLAD_PASSWORD));        
  $curl = curl_init();
  curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_URL, MOYSKLAD_URL.'/admin/attach/'.$retailstoreId);
  curl_setopt($curl, CURLOPT_POST, true);    
  $tmp = json_decode(curl_exec($curl), true);    
  curl_close($curl);
  return $tmp;
}


    // открытие смены торговой точки $retailstoreId, 
    // вх.данные - $tmp массив из токена и кассира торговой точки (чтобы не делать лишний запрос для их получения) ('token'=>***, 'uid'=>***)
    // возвращает массив 'cbId' => ID КБ, 'moyskladId' => ID МойСклад
	// https://online.moysklad.ru/api/posap/1.0/doc/index.html#pos_doc-%D0%BE%D0%BF%D0%B5%D1%80%D0%B0%D1%86%D0%B8%D0%B8-%D1%81%D0%BE-%D1%81%D0%BC%D0%B5%D0%BD%D0%B0%D0%BC%D0%B8-%D0%BE%D1%82%D0%BA%D1%80%D1%8B%D1%82%D1%8C-%D1%81%D0%BC%D0%B5%D0%BD%D1%83
function MoySklad_OpenShift($tmp='',$retailstoreId='') {
    // ищем статус последней открытой смены
  $row = sql_fetch_assoc(data_select_field(SHIFTS_FIELD_TABLE, 'id, f'.SHIFTS_FIELD_STAGE.' AS stage, f'.SHIFTS_FIELD_SYNCID.' AS syncId', "status=0 AND f".SHIFTS_FIELD_SYNCID."!='' AND f".SHIFTS_FIELD_DATE." LIKE '".date("Y-m-d ")."%' ORDER BY add_time DESC LIMIT 1"));
  if ('открыта'==$row['stage']) return array('cbId'=>$row['id'], 'moyskladId'=>$row['syncId']);    
  $syncId = MakeRandom('syncId');    // syncId смены
    // ищем текущий статус смены по этой кассе
  $row = sql_fetch_assoc(data_select_field(SHIFTS_FIELD_TABLE, 'id, f'.SHIFTS_FIELD_STAGE.' AS stage', "status=0 AND f".SHIFTS_FIELD_SYNCID."='".$syncId."' ORDER BY add_time DESC LIMIT 1"));
  if ('открыта'==$row['stage']) return array('cbId'=>$row['id'], 'moyskladId'=>$syncId);
  if ($row['id'] && !$row['stage']) $shiftId = $row['id'];
  else $shiftId = data_insert(SHIFTS_FIELD_TABLE, EVENTS_ENABLE, array('f'.SHIFTS_FIELD_SYNCID=>$syncId));
    // выполняем запрос к МойСклад, в случае успеха обновляем статус записи $shiftId
  if (!$tmp) $tmp = MoySklad_GetRetailstoreToken($retailstoreId);
  if (!$tmp['token'] || !$tmp['uid']) return false;
  $headers = array('Content-Type: application/json', 'Lognex-Pos-Auth-Token: '.$tmp['token'], 'Lognex-Pos-Auth-Cashier-Uid: '.$tmp['uid']);        
  $data = '';
  $data['name'] = str_pad($shiftId,10,0,STR_PAD_LEFT);
  $data['openmoment'] = date("Y-m-d H:i:s");
  $data['retailShift']['meta']['href'] = MOYSKLAD_URL.'/entity/retailshift/syncid/'.$syncId;
  $curl = curl_init();
  curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($curl, CURLOPT_HEADER, true);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_URL, MOYSKLAD_URL.'/rpc/openshift/');
  curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
  curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
  $response = curl_exec($curl);
  $r = explode("\r\n", $response);
  if (false!==strpos($r[0], 'HTTP/1.1 204')) data_update(SHIFTS_FIELD_TABLE, EVENTS_ENABLE, array('f'.SHIFTS_FIELD_STAGE=>'открыта'), "id='".$shiftId."' AND f".SHIFTS_FIELD_STAGE."!='открыта' LIMIT 1");
  curl_close($curl);
  return array('cbId'=>$shiftId, 'moyskladId'=>$syncId);
}


    // закрытие смены $syncId торговой точки $retailstoreId, возвращает результат закрытия
	// https://online.moysklad.ru/api/posap/1.0/doc/index.html#pos_doc-%D0%BE%D0%BF%D0%B5%D1%80%D0%B0%D1%86%D0%B8%D0%B8-%D1%81%D0%BE-%D1%81%D0%BC%D0%B5%D0%BD%D0%B0%D0%BC%D0%B8-%D0%B7%D0%B0%D0%BA%D1%80%D1%8B%D1%82%D1%8C-%D1%81%D0%BC%D0%B5%D0%BD%D1%83
function MoySklad_CloseShift($tmp='',$retailstoreId='',$syncId='') {
    // если $syncId не передан, ищем в КБ по последней открытой смене
  if (!$syncId) {
    $row = sql_fetch_assoc(data_select_field(SHIFTS_FIELD_TABLE, 'f'.SHIFTS_FIELD_SYNCID.' AS syncId', "status=0 AND f".SHIFTS_FIELD_SYNCID."!='' AND f".SHIFTS_FIELD_STAGE."='открыта' AND f".SHIFTS_FIELD_DATE." LIKE '".date("Y-m-d ")."%' ORDER BY add_time DESC LIMIT 1"));
    if ($row['syncId']) $syncId = $row['syncId'];
    else return false;   
  }
    // ищем текущий статус смены по этой кассе
  $row = sql_fetch_assoc(data_select_field(SHIFTS_FIELD_TABLE, 'id, f'.SHIFTS_FIELD_STAGE.' AS stage', "status=0 AND f".SHIFTS_FIELD_SYNCID."='".$syncId."' ORDER BY add_time DESC LIMIT 1"));
  if ('закрыта'==$row['stage']) return true;
    // выполняем запрос к МойСклад, в случае успеха обновляем статус записи $shiftId
  if (!$tmp) $tmp = MoySklad_GetRetailstoreToken($retailstoreId);
  if (!$tmp['token'] || !$tmp['uid']) return false;
  $headers = array('Content-Type: application/json', 'Lognex-Pos-Auth-Token: '.$tmp['token'], 'Lognex-Pos-Auth-Cashier-Uid: '.$tmp['uid']);        
  $data = '';
  $data['closemoment'] = date("Y-m-d H:i:s");
  $data['retailShift']['meta']['href'] = MOYSKLAD_URL.'/entity/retailshift/syncid/'.$syncId;
  $curl = curl_init();
  curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($curl, CURLOPT_HEADER, true);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_URL, MOYSKLAD_URL.'/rpc/closeshift/');
  curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
  curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
  $response = curl_exec($curl);
  $r = explode("\r\n", $response);
  if (false!==strpos($r[0], 'HTTP/1.1 204')) data_update(SHIFTS_FIELD_TABLE, EVENTS_ENABLE, array('f'.SHIFTS_FIELD_STAGE=>'закрыта'), "id='".$row['id']."' LIMIT 1");
  curl_close($curl);
  return $response;
}


    // получение списка товаров
	// https://online.moysklad.ru/api/posap/1.0/doc/index.html#pos_data-%D1%82%D0%BE%D0%B2%D0%B0%D1%80%D1%8B-%D0%B8-%D1%83%D1%81%D0%BB%D1%83%D0%B3%D0%B8-%D1%82%D0%BE%D0%B2%D0%B0%D1%80%D1%8B-%D0%B8-%D1%83%D1%81%D0%BB%D1%83%D0%B3%D0%B8
function MoySklad_GetAssortment($tmp='',$retailstoreId='') {
  if (!$tmp) $tmp = MoySklad_GetRetailstoreToken($retailstoreId);
  if (!$tmp['token'] || !$tmp['uid']) return false;
  $headers = array('Content-Type: application/json', 'Lognex-Pos-Auth-Token: '.$tmp['token'], 'Lognex-Pos-Auth-Cashier-Uid: '.$tmp['uid']);
  $curl = curl_init();
  curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_URL, MOYSKLAD_URL.'/entity/assortment');
  $assortment = json_decode(curl_exec($curl), true);    
  curl_close($curl);
  return $assortment;
}


    // получение id товара из МойСклад по названию, признак $toCreate (bool) - создавать ли новый
function MoySklad_GetProductId( $tmp='',$retailstoreId='',$productName='',$toCreate=false) {
  if (!$productName) return false;
  if (!$tmp) $tmp = MoySklad_GetRetailstoreToken($retailstoreId);
  if (!$tmp['token'] || !$tmp['uid']) return false;
  $assortment = MoySklad_GetAssortment($tmp,$retailstoreId);
  foreach ($assortment['rows'] as $product) if ($product['name']==$productName) return $product['id'];
  if ($toCreate) return MoySklad_CreateProduct($tmp, $retailstoreId, $productName);
  return false;
}


    // создание товара с именем $productName и ценой $price, возвращает id товара
	// https://online.moysklad.ru/api/posap/1.0/doc/index.html#pos_stuff-%D1%81%D0%BE%D0%B7%D0%B4%D0%B0%D0%BD%D0%B8%D0%B5-%D1%82%D0%BE%D0%B2%D0%B0%D1%80%D0%BE%D0%B2-%D1%81%D0%BE%D0%B7%D0%B4%D0%B0%D0%BD%D0%B8%D0%B5-%D1%82%D0%BE%D0%B2%D0%B0%D1%80%D0%B0
function MoySklad_CreateProduct($tmp='',$retailstoreId='',$productName='',$price=0) {
  if (!$productName) return false;    
  if (!$tmp) $tmp = MoySklad_GetRetailstoreToken($retailstoreId);
  if (!$tmp['token'] || !$tmp['uid']) return false;
  if ($u=MoySklad_GetProductId($tmp,$retailstoreId,$productName)) return $u;
  $headers = array('Content-Type: application/json', 'Lognex-Pos-Auth-Token: '.$tmp['token'], 'Lognex-Pos-Auth-Cashier-Uid: '.$tmp['uid']);
  $data = '';    
  $data['name'] = $productName;
  $syncId = MakeRandom('syncId');    // syncId товара
  $data['meta']['href'] = MOYSKLAD_URL.'/entity/product/syncid/'.$syncId;
  if ($price) $data['price'] = $price;    
  $curl = curl_init();
  curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($curl, CURLOPT_HEADER, true);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_URL, MOYSKLAD_URL.'/entity/product');
  curl_setopt($curl, CURLOPT_POST, true);
  curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
  $response = curl_exec($curl);    
  curl_close($curl);
  return MoySklad_GetProductId($tmp, $retailstoreId, $productName);
}


    // создание продажи (упрощённое)
    // возвращает массив ID КБ и ID МойСклад
	// https://online.moysklad.ru/api/posap/1.0/doc/index.html#pos_doc-%D0%BF%D1%80%D0%BE%D0%B4%D0%B0%D0%B6%D0%B8-%D0%BF%D1%80%D0%BE%D0%B4%D0%B0%D0%B6%D0%B8-%D0%B2-%D1%81%D0%BC%D0%B5%D0%BD%D0%B5
function MoySklad_CreateSale($tmp='',$retailstoreId='',$saleId=0,$price=0,$cashSum=0,$noCashSum=0,$phone='',$email='',$product='') {
  if (!$saleId || !$price || !$product) return false;
  if (!$tmp) $tmp = MoySklad_GetRetailstoreToken($retailstoreId);
  if (!$tmp['token'] || !$tmp['uid']) return false;
  $headers = array('Content-Type: application/json', 'Lognex-Pos-Auth-Token: '.$tmp['token'], 'Lognex-Pos-Auth-Cashier-Uid: '.$tmp['uid']);
  $data = '';
  $syncId = MakeRandom('syncId');    // syncId продажи
  $data['meta']['href'] = MOYSKLAD_URL.'/entity/retaildemand/syncid/'.$syncId;
  $openShift = MoySklad_OpenShift($tmp,$retailstoreId);
  $data['retailShift']['meta']['href'] = MOYSKLAD_URL.'/entity/retailshift/syncid/'.$openShift['moyskladId'];
  $data['name'] = str_pad($saleId,9,0,STR_PAD_LEFT);
  $data['moment'] = date("Y-m-d H:i:s");
  $p['quantity'] = 1;
  $p['price'] = $price;
  $p['assortment']['meta']['href'] = MOYSKLAD_URL.'/entity/product/'.MoySklad_GetProductId($tmp,$retailstoreId,$product,1);
  $p['assortment']['meta']['mediaType'] = 'application/json';
  $data['positions'][] = $p;
  if (!$cashSum && !$noCashSum) $cashSum = $price;    // если не указана разбивка на нал/безнал, то считаем всю сумму наличными 
  if ($cashSum) $data['cashSum'] = $cashSum;
  if ($noCashSum) $data['noCashSum'] = $noCashSum;
  $data['cheque']['online'] = true;
  if ($phone) $data['cheque']['phone'] = $phone;
  if ($email) $data['cheque']['email'] = $email;
  $curl = curl_init();
  curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($curl, CURLOPT_HEADER, true);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_URL, MOYSKLAD_URL.'/entity/retaildemand');
  curl_setopt($curl, CURLOPT_POST, true);
  curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
  $response = curl_exec($curl);
  $r = explode("\r\n", $response); 
  if (false!==strpos($r[0], 'HTTP/1.1 201 Created')) {
    $saleId = data_insert(ONLINESALES_TABLE, EVENTS_ENABLE, array('f'.ONLINESALES_FIELD_PAYID=>$saleId, 'f'.ONLINESALES_FIELD_SYNCID=>$syncId, 'f'.ONLINESALES_FIELD_NUMBER=>$data['name'], 'f'.ONLINESALES_FIELD_SHIFTID=>$openShift['cbId'], 'f'.ONLINESALES_FIELD_PRODUCT=>$product, 'f'.ONLINESALES_FIELD_PRICE=>$price/100, 'f'.ONLINESALES_FIELD_CASH=>$cashSum/100, 'f'.ONLINESALES_FIELD_PHONE=>$phone, 'f'.ONLINESALES_FIELD_EMAIL=>$email));
    return array('cbId'=>$saleId, 'moyskladId'=>$syncId);
  }
  curl_close($curl);    
  return false;
}


?>