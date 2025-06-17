<?php
/*
Транспортные функции
	Протокол websocket
		wsDecode($data)
		wsEncode($payload, $type = 'text', $masked = false)

	Обслуживание сокетов
		createSocketServer($host,$port,$connections=1024)
		createSocketClient($host,$port)
		chkSocks($socket=null)
		socketClose($socket)
		socketRead($sockKey,$maxzerocnt=20)

Прикладные функции
	writePrepare($instrumentsDataUpdated)
	writePrepareToSocket($sockKey,$instrumentsDataUpdated=array(),$isNoRealTime=false)
	updInstrumentsData($inInstrumentsData)
	updAISdata($inInstrumentsData)
	chkFreshOfData()
	chkSubscribe()
	updUserParms($sockKey,$params)

*/
// Транспортные функции
// 		Протокол websocket
function wsDecode($data){
/* Возвращает:
$decodedData данные или null если фрейм принят не полностью и нечего декодировать, или 
false -- что-то пошло не так, непонятно, что делать
$type тип данных или null, если данные в нескольких фреймах, и это не первый фрейм
$FIN признак последнего фрейма (TRUE) или FALSE, если фрейм не последний
$tail один или несколько склееных фреймов, оставшихся после выделения первого
*/
$decodedData = null; $tail = null; $FIN = null;

// estimate frame type:
$firstByteBinary = sprintf('%08b', ord($data[0])); 	// преобразование первого байта в битовую строку
$secondByteBinary = sprintf('%08b', ord($data[1])); 	// преобразование второго байта в битовую строку
$opcode = bindec(mb_substr($firstByteBinary, 4, 4,'8bit'));	// последние четыре бита первого байта -- в десятичное число из текста
$payloadLength = ord($data[1]) & 127;	// берём как число последние семь бит второго байта

$isMasked = $secondByteBinary[0] == '1';	// первый бит второго байта -- из текстового представления.
if($firstByteBinary[0] == '1') $FIN = 'messageComplete';


switch ($opcode) {
case 1:	// text frame:
	$type = 'text';
	break;
case 2:
	$type = 'binary';
	break;
case 8:	// connection close frame
	$type = 'close';
	break;
case 9:	// ping frame
	$type = 'ping';
	break;
case 10:	// pong frame
	$type = 'pong';
	break;
default:
	$type = null;
}

if ($payloadLength === 126) {
	//if (mb_strlen($data,'8bit') < 4) return false;
	if (strlen($data) < 4) return false;
	$mask = mb_substr($data, 4, 4,'8bit');
	$payloadOffset = 8;
	$dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
} 
elseif ($payloadLength === 127) {
	//if (mb_strlen($data,'8bit') < 10) return false;
	if (strlen($data) < 10) return false;
	$mask = mb_substr($data, 10, 4,'8bit');
	$payloadOffset = 14;
	$tmp = '';
	for ($i = 0; $i < 8; $i++) {
		$tmp .= sprintf('%08b', ord($data[$i + 2]));
	}
	$dataLength = bindec($tmp) + $payloadOffset;
	unset($tmp);
} 
else {
	$mask = mb_substr($data, 2, 4,'8bit');
	$payloadOffset = 6;
	$dataLength = $payloadLength + $payloadOffset;
}

/**
 * We have to check for large frames here. socket_recv cuts at 1024 (65536 65550?) bytes
 * so if websocket-frame is > 1024 bytes we have to wait until whole
 * data is transferd.
 */
//echo "mb_strlen(data)=".mb_strlen($data,'8bit')."; dataLength=$dataLength;\n";
if (mb_strlen($data,'8bit') < $dataLength) {
	echo "\n[wsDecode] recievd ".mb_strlen($data,'8bit')." byte, but frame length $dataLength byte.\n";
	$FIN = 'partFrame';
	$tail = $data;
}
else {
	$tail = mb_substr($data,$dataLength,null,'8bit');

	if($isMasked) {
		//echo "[wsDecode] unmasking data\n";
		$unmaskedPayload = ''; 
		for ($i = $payloadOffset; $i < $dataLength; $i++) {
			$j = $i - $payloadOffset;
			if (isset($data[$i])) {
				$unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
			}
		}
		$decodedData = $unmaskedPayload;
	} 
	else {
		$payloadOffset = $payloadOffset - 4;
		$decodedData = mb_substr($data, $payloadOffset,'8bit');
	}
}

return array($decodedData,$type,$FIN,$tail);
} // end function wsDecode


function wsEncode($payload, $type = 'text', $masked = false){
/* https://habr.com/ru/post/209864/ 
Кодирует $payload как один фрейм
*/
if(!$type) $type = 'text';
$frameHead = array();
$payloadLength = mb_strlen($payload,'8bit');

switch ($type) {
case 'text':    // first byte indicates FIN, Text-Frame (10000001):
    $frameHead[0] = 129;
    break;
case 'close':    // first byte indicates FIN, Close Frame(10001000):
    $frameHead[0] = 136;
    break;
case 'ping':    // first byte indicates FIN, Ping frame (10001001):
    $frameHead[0] = 137;
    break;
case 'pong':    // first byte indicates FIN, Pong frame (10001010):
    $frameHead[0] = 138;
    break;
}

// set mask and payload length (using 1, 3 or 9 bytes)
if ($payloadLength > 65535) {
    $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
    $frameHead[1] = ($masked === true) ? 255 : 127;
    for ($i = 0; $i < 8; $i++) {
        $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
    }
    // most significant bit MUST be 0
    if ($frameHead[2] > 127) {
        return array('type' => '', 'payload' => '', 'error' => 'frame too large (1004)');
    }
} 
elseif ($payloadLength > 125) {
    $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
    $frameHead[1] = ($masked === true) ? 254 : 126;
    $frameHead[2] = bindec($payloadLengthBin[0]);
    $frameHead[3] = bindec($payloadLengthBin[1]);
} 
else {
    $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
}

// convert frame-head to string:
foreach (array_keys($frameHead) as $i) {
    $frameHead[$i] = chr($frameHead[$i]);
}
if ($masked === true) {
    // generate a random mask:
    $mask = array();
    for ($i = 0; $i < 4; $i++) {
        $mask[$i] = chr(rand(0, 255));
    }

    $frameHead = array_merge($frameHead, $mask);
}
$frame = implode('', $frameHead);

// append payload to frame:
for ($i = 0; $i < $payloadLength; $i++) {
    $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
}

return $frame;
} // end function wsEncode


// 		Обслуживание сокетов
function createSocketServer($host,$port,$connections=1024){
/* создаёт сокет, соединенный с $host,$port на своей машине, для приёма входящих соединений 
в Ubuntu $connections = 0 означает максимально возможное количество соединений, а в Raspbian (Debian?) действительно 0
*/
if(substr_count($host,':')>1) {
	$sock = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
	$host = trim($host,'[]');
}
else $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if(!$sock) {
	echo "Failed to create server socket by reason: " . socket_strerror(socket_last_error()) . "\n";
	return FALSE;
}
$res = socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);	// чтобы можно было освободить ранее занятый адрес, не дожидаясь, пока его освободит система
for($i=0;$i<100;$i++) {
	$res = @socket_bind($sock, $host, $port);
	if(!$res) {
		echo "Failed to binding to $host:$port by: " . socket_strerror(socket_last_error($sock)) . ", waiting $i\r";
		sleep(1);
	}
	else break;
}
if(!$res) {
	echo "Failed to binding to $host:$port by: " . socket_strerror(socket_last_error($sock)) . "\n";
	return FALSE;
}
$res = socket_listen($sock,$connections); 	// 
if(!$res) {
	echo "Failed listennig by: " . socket_strerror(socket_last_error($sock)) . "\n";
	return FALSE;
}
return $sock;
} // end function createSocketServer


function createSocketClient($host,$port){
/* создаёт сокет, соединенный с $host,$port на другом компьютере */
if(substr_count($host,':')>1) {
	$sock = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
	$host = trim($host,'[]');
}
else $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if(!$sock) {
	echo "Failed to create client socket by reason: " . socket_strerror(socket_last_error()) . "\n";
	return FALSE;
}
if(! @socket_connect($sock,$host,$port)){ 	// подключаемся к серверу
	echo "Failed to connect to remote server $host:$port by reason: " . socket_strerror(socket_last_error()) . "\n";
	return FALSE;
}
//echo "Connected to $host:$port\n";
//$res = socket_write($socket, "\n");
return $sock;
} // end function createSocketClient


function chkSocks($socket=null){	
/*  */
global $sockets,$dataSourceSock,$masterSock,$dataSourceHost,$dataSourcePort,$gpsdProxyHost,$gpsdProxyPort,$SocketTimeout,$minSocketTimeout;
$to = 10;	// sec.
if(($socket === $dataSourceSock) or (!$socket and !$dataSourceSock)){
	if($socket === $dataSourceSock) socketClose($socket);
	$dataSourceSock = createSocketClient($dataSourceHost,$dataSourcePort);
	if($dataSourceSock){
		$sockets[]=$dataSourceSock;
		echo "Connected to data source on $dataSourceHost:$dataSourcePort                     \n";
	}
	else {
		$SocketTimeout = $to;
		echo "Connection to gpsd on $dataSourceHost : $dataSourcePort failed. Wait $to sec.\n\n";
	};
	if($socket) return;
};
if(($socket === $masterSock) or (!$socket and !$masterSock)){
	if($socket === $masterSock) socketClose($socket);
	$masterSock = createSocketServer($gpsdProxyHost,$gpsdProxyPort,20); 	// Соединение для приёма клиентов, входное соединение
	if(!$masterSock){
		echo "Couldn't open $gpsdProxyHost:$gpsdProxyPort to inbound connections. Died.\n";
		exit;
	};
	echo "\ngpsd2websocket proxy ready to inbound connection to $gpsdProxyHost:$gpsdProxyPort\n";
	if($socket) return;
};
if($socket) socketClose($socket);
}; // end function chkSocks


function socketClose($socket){
/**/
global $sockets,$messages,$socksRead,$socksWrite,$socksError;
//// FOR TEST
//echo "[socketClose] before:\n";
//echo "sockets:"; var_dump($sockets);
//echo "messages:"; var_dump($messages);
//// FOR TEST
$n = array_search($socket,$sockets);	// 
echo "Close socket #$n  type ".gettype($socket)." by error or by life                        \n";
// Вся фишка в том, что unset не перенумеровывает массив, поэтому соответствие массивов
// $sockets и $messages сохраняется
if($n !== FALSE){
	unset($sockets[$n]);
	unset($messages[$n]);
}
$n = array_search($socket,$socksRead);	// 
if($n !== FALSE) unset($socksRead[$n]);
$n = array_search($socket,$socksWrite);	// 
if($n !== FALSE) unset($socksWrite[$n]);
$n = array_search($socket,$socksError);	// 
if($n !== FALSE) unset($socksError[$n]);
@socket_close($socket); 	// он может быть уже закрыт. Но в сучном PHP8 это не помогает, потому что если оно не объект Socket, то Fatal error.
//// FOR TEST
//echo "[socketClose] after:\n";
//echo "sockets:"; var_dump($sockets);
//echo "messages:"; var_dump($messages);
//// FOR TEST
}; // end function socketClose


function socketRead($sockKey,$maxzerocnt=20){
/**/
global $messages,$sockets;
$socket = $sockets[$sockKey];
if(@$messages[$sockKey]['protocol']=='WS'){ 	// с этим сокетом уже общаемся по протоколу websocket	@ - для дебильного PHP8, где отсутствующий ключ - Warning
	$buf = @socket_read($socket, 1048576,  PHP_BINARY_READ); 	// читаем до 1MB 65536
}
else {
	$buf = @socket_read($socket, 1048576, PHP_NORMAL_READ); 	// читаем построчно
	// строки могут разделяться как \n, так и \r\n, но при PHP_NORMAL_READ reading stops at \n or \r, соотвественно, сперва строка заканчивается на \r, а после следующего чтения - на \r\n, и только тогда можно заменить
	if(@$buf[-1]=="\n") $buf = trim($buf)."\n";	// т.е., если строка кончалась на \n или \r\n - она будет кончаться на \n; @ - для кретинского PHP8, для которого обращение за пределы массива - Warning
	else $buf = trim($buf);	// если же строка кончалась на \r или просто - она станет без всего в конце
}
if($err = socket_last_error($socket)) { 	// с сокетом проблемы
	//echo "\nbuf has type ".gettype($buf)." and=|$buf|\nwith error ".socket_last_error($socket)."\n";		
	switch($err){
	case 114:	// Operation already in progress
	case 115:	// Operation now in progress
	case 104:	// Connection reset by peer		если клиент сразу закроет сокет, в который он что-то записал, то ещё не переданная часть записанного будет отброшена. Поэтому клиент не закрывает сокет вообще, и он закрывается системой с этим сообщением. Но на этой стороне к моменту получения ошибки уже всё считано?
	//	break;
	default:
		echo "Failed to read data from socket #$sockKey by: " . socket_strerror(socket_last_error($socket)) . "                                 \n"; 	// в общем-то -- обычное дело. Клиент закрывает соединение, мы об этом узнаём при попытке чтения. Если $sockKey == false, то это сокет к gpsd.
	};
	return false;
};
if(trim($buf)) $messages[$sockKey]['zerocnt'] = 0;	// \n может быть частью составного сообщения, поэтому без trim. Но не 20 же штук?
else $messages[$sockKey]['zerocnt']++;
if($messages[$sockKey]['zerocnt']>$maxzerocnt){
	echo "To many empty strings from socket #$sockKey                           \n"; 	// бывает, клиент умер, а сокет -- нет. Тогда из него читается пусто.
	return false;
};
return $buf;
}; // end function socketRead






// Прикладные функции

function writePrepare($instrumentsDataUpdated){
/*
$instrumentsDataUpdated - массив class => [device,...device]
*/
global $sockets,$dataSourceSock,$isNoRealTime,$subscribe;
//echo "\n[writePrepare] Имеется подписка на:"; print_r($subscribe); echo "\n";
//echo "\n[writePrepare] Изменено:"; print_r($instrumentsDataUpdated); echo "\n";
// Возьмём те типы обновлённых данных, на которые есть подписка:
$instrumentsDataUpdated = array_intersect_key($instrumentsDataUpdated,array_fill_keys($subscribe,true));	// те обновленные типы данных, на которые есть подписка
//echo "\n[writePrepare] Из подписаных, обновлены:"; print_r($instrumentsDataUpdated); echo "\n";
if(!$instrumentsDataUpdated) return;	// нет ничего нового
// А теперь раздадим эти данные для отправки каждому клиенту
// При этом содержимое $instrumentsDataUpdated не используется - только ключи, как, собственно,
// и в gpsdPROXY. Может, и не надо собирать устройства?
foreach($sockets as $sockKey=>$socket){
	if($socket === $dataSourceSock) continue;
	writePrepareToSocket($sockKey,$instrumentsDataUpdated,$isNoRealTime);
};
}; // end function writePrepare


function writePrepareToSocket($sockKey,$instrumentsDataUpdated=array(),$isNoRealTime=false){
/**/
global $messages,$instrumentsData,$noRealTime,$currentNoRealTime;
// Эта проверка нужна, потому что writePrepare вызывается по приходу данных,
// вне зависимости от наличия готовых потребителей. Т.е., потребитель может быть не готов,
// но уже находиться в $sockets.
if(@$messages[$sockKey]['POLL']) return;
if(@$messages[$sockKey]['disable']) return;
if(!isset($messages[$sockKey]['subscribe'])) return;	// Сокету ещё не назначена подписка, значит, с этим сокетом идёт рукопожатие, соединение websocket (или какое там) ещё не установлено.
if(!$instrumentsDataUpdated) $instrumentsDataUpdated = array_fill_keys($messages[$sockKey]['subscribe'],array(''));
//echo "\n[writePrepareToSocket] Обновлены:"; print_r($instrumentsDataUpdated); echo "\n";
//echo "\n[writePrepareToSocket] messages[$sockKey]:"; print_r($messages[$sockKey]); echo "\n";
foreach($messages[$sockKey]['subscribe'] as $class){	// для каждого класса gpsd в подписке
	if(!isset($instrumentsDataUpdated[$class])) continue;	// этой подписки нет (а не пустой массив) в изменённых классах. foreach does not support the ability to suppress error messages using the @.
	// Этот механизмик не отдаёт клиентам данные классов $isNoRealTime до тех пор, пока
	// на очередном обороте главного цикла не будет принято из сокета gpsd ни одного из этих классов.
	// На обороте, на котором не будет, будут записаны для клиентов отложенные данные классов $currentNoRealTime,
	// и переданы на следующем обороте. Если нет другого чтения - то через таймаут.
	// Конечно, в принципе, непрерывно поступающие данные AIS при таком подходе не будут переданы никогда.
	// И никогда не будут переданы другие классы из того же списка.
	// Но, чтобы данные AIS поступали непрерыно, нужно 2000 целей. Но можно подумать о периодической отправке...
	// Если установить задержку передачи после приёма всего пакета в одну секунду, то
	// чтобы ничего не передавалось, достаточно получать необязательное сообщение раз в секунду. А
	// при наличии большого количества (но сильно меньше 2000) целей AIS это вполне реально.
	// Поэтому правильно установить таймаут в 0, т.е., необязательные данные отдадутся сразу после
	// последнего приёма, на следующем обороте.
	// Хотя частота отдачи может несколько увеличиться.
	if($isNoRealTime and in_array($class,$noRealTime)){
		//echo "Не надо отдавать данные класса $class, потому что такие были только что приняты.  \n";
		$currentNoRealTime[] = $class;
		continue;
	};
	$output = array(); $lasts = array();
	switch($class){
	case 'AIS':
		if(!isset($instrumentsData['AIS'])) break;
		$output = array('class' => 'AIS','ais' => array());
		foreach($instrumentsData['AIS'] as $vehicle => $data){
			if(!@$data["timestamp"]) continue;	// там, если ещё нет содержательных данных - нет и метки времени. Такие цели отсылать клиентам не будем.
			if(@$messages[$sockKey]['dontUseDevices'][$class] and in_array(@$data['data']['device'],$messages[$sockKey]['dontUseDevices'][$class])) continue;	// этот клиент не хочет получать данные от этого устройства
			$output['ais'][$vehicle] = $data['data'];
			$output['ais'][$vehicle]["timestamp"] = $data["timestamp"];		
		};
		break;
	case 'TPV':	// A TPV object is a time-position-velocity report.
	case 'ATT':	// An ATT object is a vehicle-attitude report. It is returned by digital-compass and gyroscope sensors; depending on device, it may include: heading, pitch, roll, yaw, gyroscope, and magnetic-field readings. 
	case 'SKY':	// A SKY object reports a sky view of the GPS satellite positions. 
	case 'GST':	// A GST object is a pseudorange noise report.
	case 'TOFF':	// This message is emitted on each cycle and reports the offset between the host’s clock time and the GPS time at top of the second (actually, when the first data for the reporting cycle is received).
	case 'PPS':	// This message is emitted each time the daemon sees a valid PPS (Pulse Per Second) strobe from a device. This message exactly mirrors the TOFF message.
	case 'OSC':	// This message reports the status of a GPS-disciplined oscillator (GPSDO).
	default:
		if(!isset($instrumentsData[$class])) break;	// данных может ещё не быть, а клиент - уже
		foreach($instrumentsData[$class] as $source => $data){
			//echo "\n[writePrepareToSocket] class=$class; source=$source; data:"; print_r($messages[$sockKey]['dontUseDevices']); echo "\n";
			if(@$messages[$sockKey]['dontUseDevices'][$class] and in_array($source,$messages[$sockKey]['dontUseDevices'][$class])) continue;	// этот клиент не хочет получать данные от этого устройства
			// нужно собрать свежие данные от всех устройств в одно "устройство". 
			// При этом окажется, что координаты от одного приёмника ГПС, а ошибка этих координат -- от другого, если первый не прислал ошибку
			//echo "\n[writePrepareToSocket] class=$class; source=$source; data:"; print_r($data); echo "\n";
			foreach($data['data'] as $type => $value){
				if($type=='device') continue;	// необязательный параметр. Указать своё устройство?
				if(@$instrumentsData[$class][$source]['cachedTime'][$type] and 
					($instrumentsData[$class][$source]['cachedTime'][$type]<=@$lasts[$type])) continue;	// если эта величина не моложе предыдущей такой же из другого источника - игнорируем. Что лучше -- старый 3D fix, или свежий 2d fix?
				$lasts[$type] = @$instrumentsData[$class][$source]['cachedTime'][$type] ? $instrumentsData[$class][$source]['cachedTime'][$type] : 0;	// сохраним новый возраст величины
				$output[$type] = $value;	
			};
		};			
	};
	if($output){
		//echo "[writePrepareToSocket] Для клиентского сокета №$sockKey подготовлены данные типа :$class         \n"; //print_r($output); echo "\n";
		//if($class=='AIS') echo "[writePrepareToSocket] Для клиентского сокета №$sockKey подготовлены данные типа :$class         \n"; //print_r($output); echo "\n";
		$messages[$sockKey]['output'][] = json_encode($output,JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
	};
};
}; // end function writePrepareToSocket


function updInstrumentsData($inInstrumentsData){
/* собирает данные по устройствам, в том числе и однородные 
$inInstrumentsData -- один ответ gpsd в режиме ?WATCH={"enable":true,"json":true};, 
когда оно передаёт поток отдельных сообщений, типа:
Array
(
    [class] => TPV
    [device] => tcp://localhost:2222
    [mode] => 3
    [lat] => 60.069966667
    [lon] => 23.522883333
    [altHAE] => 0
    [altMSL] => 0
    [alt] => 0
    [track] => 204.46
    [magtrack] => 204.76
    [magvar] => 8.7
    [speed] => 2.932
    [geoidSep] => 0
    [eph] => 0
)
или:
{"class":"AIS","device":"tcp://localhost:2222","type":1,"repeat":0,"mmsi":244660492,"scaled":false,"status":0,"status_text":"Under way using engine","turn":-128,"speed":0,"accuracy":true,"lon":3424893,"lat":31703105,"course":0,"heading":511,"second":25,"maneuver":0,"raim":true,"radio":81955}

скорость - в м/сек
*/
global $instrumentsData,$boatInfo,$currentDevice;
$class = $inInstrumentsData['class'];
if($inInstrumentsData['device']) $currentDevice = $inInstrumentsData['device'];	// 

//echo "\ninInstrumentsData="; print_r($inInstrumentsData);echo"\n";
//echo "recieve $class                     \n";
$instrumentsDataUpdated = array();	// массив class => [device,...device]
$dataTime = time();
foreach($inInstrumentsData as $type => $value){ 	// обновим данные
	if($type == 'time') { // надеемся, что время прислали до содержательных данных
		$dataTime = strtotime($value);
		//echo "\nПрисланное время: |$value|$dataTime, восстановленное: |".date(DATE_ATOM,$dataTime)."|".strtotime(date(DATE_ATOM,$dataTime))." \n";
		if(!$dataTime) $dataTime = time();
	};
	switch($type){
	case 'depth': 
		// Глубину записываем в ATT, не в TPV
		$value = 0+$value; 	// в результает получается целое или вещественное число
		if(isset($boatInfo['to_echosounder'])) $value += $boatInfo['to_echosounder'];
	case 'wanglem': 
	case 'wangler': 
	case 'wanglet': 
	case 'wspeedr': 
	case 'wspeedt': 
	case 'wtemp': 
	case 'temp': 
		// Температуру записываем в ATT, не в TPV
		//echo "type=$type; value=$value;                         \n";
		$value = 0+$value; 	// в результает получается целое или вещественное число
		if(!@$instrumentsData['ATT'][$currentDevice]) $instrumentsData['ATT'][$currentDevice] = array(
			'data'=>array(
				'class'=>'ATT',
				'device'=>$currentDevice
			),
			'cachedTime'=>array()
		);
		if($dataTime > @$instrumentsData['ATT'][$currentDevice]['cachedTime'][$type]){
			if(is_float($value)){
				 if($value !== @$instrumentsData['ATT'][$currentDevice]['data'][$type]){	// Кстати, такой фокус не пройдёт в JavaScript, потому что переменной $instrumentsData['ATT'][$inInstrumentsData['device']]['data'][$type] в начале не существует.
					$instrumentsData['ATT'][$currentDevice]['data'][$type] = $value; 	// int or float
					$instrumentsData['ATT'][$currentDevice]['data']['time'] = $inInstrumentsData['time'];
					$instrumentsData['ATT'][$currentDevice]['cachedTime'][$type] = $dataTime;
					$instrumentsDataUpdated['ATT'][] = $currentDevice;
				};
			}
			else{
				$instrumentsData['ATT'][$currentDevice]['data'][$type] = $value; 	// int or float
				$instrumentsData['ATT'][$currentDevice]['data']['time'] = $inInstrumentsData['time'];
				$instrumentsData['ATT'][$currentDevice]['cachedTime'][$type] = $dataTime;
				$instrumentsDataUpdated['ATT'][] = $currentDevice;
			};
		};
		break;
	case 'mheading': 
		$value = 0+$value; 	// в результает получается целое или вещественное число
		if(isset($boatInfo['magdev'])) $value += $boatInfo['magdev'];
	default:
		if(is_numeric($value)){
			// int or float. нет способа привести к целому или вещественному без явной проверки, 
			// кроме как вот через такую задницу. 
			// Однако, оказывается, что числа уже всегда? И чё теперь? Ибо (int)0 !== (float)0
			//echo "\ntype=$type; value=$value; is_int:".(is_int($value))."; is_float:".(is_float($value))."; \n";
			$value = 0+$value; 	// в результает получается целое или вещественное число
			// Записываем время кеширования всех, потому что оно используется в makeWATCH для собирания самых свежих значений от разных устройств
			// но если значение float, и равно предыдущему - считаем, что это предыдущее значение
			// и время кеширования не обновляем. 
			// Что стрёмно, на самом деле, ибо у нас часто (всегда?) значеия float, даже когда они
			// int, особенно 0. Почему?
			if(is_float($value)){
				 if($value !== @$instrumentsData[$class][$currentDevice]['data'][$type]){	// Кстати, такой фокус не пройдёт в JavaScript, потому что переменной $instrumentsData['TPV'][$inInstrumentsData['device']]['data'][$type] в начале не существует.
					$instrumentsData[$class][$currentDevice]['data'][$type] = $value; 	// int or float
					// php создаёт вложенную структуру, это не python и не javascript
					$instrumentsData[$class][$currentDevice]['cachedTime'][$type] = $dataTime;
					$instrumentsDataUpdated[$class][] = $currentDevice;
				};
			}
			else{
				$instrumentsData[$class][$currentDevice]['data'][$type] = $value; 	// int or float
				$instrumentsData[$class][$inInstrumentsData['device']]['cachedTime'][$type] = $dataTime;
				$instrumentsDataUpdated[$class][] = $currentDevice;
			};
		}
		else{
			$instrumentsData[$class][$currentDevice]['data'][$type] = $value;
			// Записываем время кеширования всех, потому что оно используется в makeWATCH для собирания самых свежих значений от разных устройств
			$instrumentsData[$class][$currentDevice]['cachedTime'][$type] = $dataTime;
			$instrumentsDataUpdated[$class][] = $currentDevice;
		};
	};
};
$instrumentsDataUpdated[$class] = array_unique($instrumentsDataUpdated[$class]);
return $instrumentsDataUpdated;
}; // end function updInstrumentsData


function updAISdata($inInstrumentsData){
/* собирает данные подряд, устройства указываются в данных
Видимо, gpsd считает, что устройство AIS всегда только одно.
Так или иначе, если их больше, то следующее будет затирать предыдущее.
С другой стороны, если есть netAIS, то тамошних целей нет в других AIS...
скорость - в м/сек
*/
global $instrumentsData,$boatInfo;
if($inInstrumentsData['device']) $currentDevice = $inInstrumentsData['device'];	// 
//echo "\nJSON AIS Data: "; print_r($inInstrumentsData); echo "\n";
$vehicle = trim((string)$inInstrumentsData['mmsi']);	//
$instrumentsData['AIS'][$vehicle]['data']['mmsi'] = $vehicle;	// ВНИМАНИЕ! Ключ -- строка, представимая как число. Любые действия в массивом, затрагивающие ключи -- сделают эту строку числом
if(@$inInstrumentsData['netAIS']) $instrumentsData['AIS'][$vehicle]['data']['netAIS'] = TRUE; 	// 
//echo "\nmmsi $vehicle AIS sentence type ".$inInstrumentsData['type']."\n";
//if($vehicle=='538008208') {echo "mmsi: Princess Margo\n"; print_r($instrumentsData['AIS'][$vehicle]['data']); echo "\n";};
$instrumentsDataUpdated = array('AIS'=>array());	// массив class => [device,...device]
$now = time();
switch($inInstrumentsData['type']) {
case 27:
case 18:
case 19:
case 1:
case 2:
case 3:		// http://www.e-navigation.nl/content/position-report
	// Для начала определим timestamp полученного сообщения, и,
	// если оно не моложе имеющегося - сообщение проигнорируем
	$inInstrumentsData['second'] = (int)filter_var($inInstrumentsData['second'],FILTER_SANITIZE_NUMBER_INT);
	if($inInstrumentsData['second']>63) $timestamp = $inInstrumentsData['second'];	// Ну так же проще! Будем считать, что если там большая цифра -- то это unix timestamp. Так будем принимать метку времени от SignalK
	elseif($inInstrumentsData['second']>59) $timestamp = $now;	// т.е., никакого разумного времени передано не было, только условные.
	else $timestamp = $now - $inInstrumentsData['second']; 	// Unis timestamp. Time stamp UTC second when the report was generated by the electronic position system (EPFS) (0-59, or 60 if time stamp is not available, which should also be the default value, or 61 if positioning system is in manual input mode, or 62 if electronic position fixing system operates in estimated (dead reckoning) mode, or 63 if the positioning system is inoperative)
	if(@$instrumentsData['AIS'][$vehicle]['timestamp'] and ($timestamp<=$instrumentsData['AIS'][$vehicle]['timestamp'])) {
		//echo "\nПолучено старое сообщение AIS № 1 для mmsi=$vehicle, игнорируем.\n";
		break;
	}
	$instrumentsData['AIS'][$vehicle]['timestamp'] = $timestamp;
	//echo "\nПолучено сообщение AIS № 1 для mmsi=$vehicle, timestamp=$timestamp; now=$now;\n";

	if(isset($inInstrumentsData['status'])) {
		if(is_string($inInstrumentsData['status'])){	// костыль к горбатому gpsd, который для 27 предложения пишет в status status_text.
			//$instrumentsData['AIS'][$vehicle]['data']['status_text'] = filter_var($inInstrumentsData['status'],FILTER_SANITIZE_STRING);	// оно не надо, ибо интернационализация и всё такое. И, кстати: для американцев нет других языков, да.
			$instrumentsData['AIS'][$vehicle]['data']['status'] = navigationStatusEncode($instrumentsData['AIS'][$vehicle]['data']['status_text']);
		}
		else $instrumentsData['AIS'][$vehicle]['data']['status'] = (int)filter_var($inInstrumentsData['status'],FILTER_SANITIZE_NUMBER_INT); 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
		if($instrumentsData['AIS'][$vehicle]['data']['status'] === 0 and (!@$instrumentsData['AIS'][$vehicle]['data']['speed'])) $instrumentsData['AIS'][$vehicle]['data']['status'] = null;	// они сплошь и рядом ставят статус 0 для не движущегося судна
		if($instrumentsData['AIS'][$vehicle]['data']['status'] == 15) $instrumentsData['AIS'][$vehicle]['data']['status'] = null;
		$instrumentsData['AIS'][$vehicle]['cachedTime']['status'] = $now;
		//echo "inInstrumentsData['status']={$inInstrumentsData['status']}; status={$instrumentsData['AIS'][$vehicle]['data']['status']};\n";
	}
	//if(isset($inInstrumentsData['status_text'])) $instrumentsData['AIS'][$vehicle]['data']['status_text'] = filter_var($inInstrumentsData['status_text'],FILTER_SANITIZE_STRING);
	//echo "inInstrumentsData['status_text']={$inInstrumentsData['status_text']}; status_text={$instrumentsData['AIS'][$vehicle]['data']['status_text']};\n";
	if(isset($inInstrumentsData['accuracy'])) {
		if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['accuracy'] = $inInstrumentsData['accuracy']; 	// данные уже приведены к человеческому виду
		else $instrumentsData['AIS'][$vehicle]['data']['accuracy'] = (bool)filter_var($inInstrumentsData['accuracy'],FILTER_SANITIZE_NUMBER_INT); 	// Position accuracy The position accuracy (PA) flag should be determined in accordance with Table 50 1 = high (£ 10 m) 0 = low (>10 m) 0 = default
		$instrumentsData['AIS'][$vehicle]['cachedTime']['accuracy'] = $now;
	}
	if(isset($inInstrumentsData['turn'])){
		if($inInstrumentsData['scaled']) { 	// данные уже приведены к человеческому виду
			if($inInstrumentsData['turn'] == 'nan') $instrumentsData['AIS'][$vehicle]['data']['turn'] = NULL;
			else $instrumentsData['AIS'][$vehicle]['data']['turn'] = $inInstrumentsData['turn']; 	// градусы в минуту со знаком или строка? one of the strings "fastright" or "fastleft" if it is out of the AIS encoding range; otherwise it is quadratically mapped back to the turn sensor number in degrees per minute
		}
		else {
			$instrumentsData['AIS'][$vehicle]['data']['turn'] = (int)filter_var($inInstrumentsData['turn'],FILTER_SANITIZE_NUMBER_INT); 	// тут чёта сложное...  Rate of turn ROTAIS 0 to +126 = turning right at up to 708° per min or higher 0 to –126 = turning left at up to 708° per min or higher Values between 0 and 708° per min coded by ROTAIS = 4.733 SQRT(ROTsensor) degrees per min where  ROTsensor is the Rate of Turn as input by an external Rate of Turn Indicator (TI). ROTAIS is rounded to the nearest integer value. +127 = turning right at more than 5° per 30 s (No TI available) –127 = turning left at more than 5° per 30 s (No TI available) –128 (80 hex) indicates no turn information available (default). ROT data should not be derived from COG information.
		}
		if($instrumentsData['AIS'][$vehicle]['data']['turn'] == -128) $instrumentsData['AIS'][$vehicle]['data']['turn'] = null;	// -128 ?
		$instrumentsData['AIS'][$vehicle]['cachedTime']['turn'] = $now;
		//echo "$vehicle inInstrumentsData['turn']={$inInstrumentsData['turn']}; turn={$instrumentsData['AIS'][$vehicle]['data']['turn']};                 \n";
	}
	if(isset($inInstrumentsData['lon']) and isset($inInstrumentsData['lat'])){
		if($inInstrumentsData['scaled']) { 	// данные уже приведены к человеческому виду
			if($inInstrumentsData['type'] == 27){
				if( !($instrumentsData['AIS'][$vehicle]['data']['lon'] and $instrumentsData['AIS'][$vehicle]['data']['lat'])) {	// костыль к багу gpsd, когда он округляет эти координаты до первого знака после запятой
					$instrumentsData['AIS'][$vehicle]['data']['lon'] = (float)$inInstrumentsData['lon']; 	// 
					$instrumentsData['AIS'][$vehicle]['data']['lat'] = (float)$inInstrumentsData['lat'];
					$instrumentsData['AIS'][$vehicle]['cachedTime']['lon'] = $now;
					$instrumentsData['AIS'][$vehicle]['cachedTime']['lat'] = $now;
				}
			}
			else {
				$instrumentsData['AIS'][$vehicle]['data']['lon'] = (float)$inInstrumentsData['lon']; 	// 
				$instrumentsData['AIS'][$vehicle]['data']['lat'] = (float)$inInstrumentsData['lat'];
				$instrumentsData['AIS'][$vehicle]['cachedTime']['lon'] = $now;
				$instrumentsData['AIS'][$vehicle]['cachedTime']['lat'] = $now;
			}
		}
		else {
			if($inInstrumentsData['type'] == 27) { 	// оказывается, там координаты в 1/10 минуты и скорость в узлах!!!
				if($inInstrumentsData['lon']==181*60*10) $instrumentsData['AIS'][$vehicle]['data']['lon'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['lon'] = (float)filter_var($inInstrumentsData['lon'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/(10*60); 	// Longitude in degrees	( 1/10 min (±180°, East = positive (as per 2’s complement), West = negative (as per 2’s complement). 181 = (6791AC0h) = not available = default) )
				if($inInstrumentsData['lat']==91*60*10) $instrumentsData['AIS'][$vehicle]['data']['lat'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['lat'] = (float)filter_var($inInstrumentsData['lat'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/(10*60); 	// Latitude in degrees (1/10 min (±90°, North = positive (as per 2’s complement), South = negative (as per 2’s complement). 91° (3412140h) = not available = default))
			}
			else {
				if($inInstrumentsData['lon']==181*60*10000) $instrumentsData['AIS'][$vehicle]['data']['lon'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['lon'] = (float)filter_var($inInstrumentsData['lon'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/(10000*60); 	// Longitude in degrees	( 1/10 000 min (±180°, East = positive (as per 2’s complement), West = negative (as per 2’s complement). 181 = (6791AC0h) = not available = default) )
				if($inInstrumentsData['lat']==91*60*10000) $instrumentsData['AIS'][$vehicle]['data']['lat'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['lat'] = (float)filter_var($inInstrumentsData['lat'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/(10000*60); 	// Latitude in degrees (1/10 000 min (±90°, North = positive (as per 2’s complement), South = negative (as per 2’s complement). 91° (3412140h) = not available = default))
			}
			$instrumentsData['AIS'][$vehicle]['cachedTime']['lon'] = $now;
			$instrumentsData['AIS'][$vehicle]['cachedTime']['lat'] = $now;
		}
	}
	if(isset($inInstrumentsData['speed'])){
		if($inInstrumentsData['scaled']) { 	// данные уже приведены к человеческому виду, но скорость в УЗЛАХ!!!!
			if($inInstrumentsData['speed']=='nan') $instrumentsData['AIS'][$vehicle]['data']['speed'] = NULL;
			else {
				if($inInstrumentsData['type'] == 27){
					if( !$instrumentsData['AIS'][$vehicle]['data']['speed']){	// не будем принимать данные из сообщения 27 из-за меньшей точности
						$instrumentsData['AIS'][$vehicle]['data']['speed'] = $inInstrumentsData['speed']*1852/(60*60); 	// SOG Speed over ground in m/sec 	
						$instrumentsData['AIS'][$vehicle]['cachedTime']['speed'] = $now;
					}
				}
				else {
					$instrumentsData['AIS'][$vehicle]['data']['speed'] = $inInstrumentsData['speed']*1852/(60*60); 	// SOG Speed over ground in m/sec 	
					$instrumentsData['AIS'][$vehicle]['cachedTime']['speed'] = $now;
				}
			}
		}
		else {
			if($inInstrumentsData['type'] == 27) { 	// оказывается, там координаты в 1/10 минуты и скорость в узлах!!!
				if($inInstrumentsData['speed']==63) $instrumentsData['AIS'][$vehicle]['data']['speed'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['speed'] = (float)filter_var($inInstrumentsData['speed'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)*1852/3600; 	// м/сек SOG Speed over ground in m/sec 	Knots (0-62); 63 = not available = default
			}
			else {
				if($inInstrumentsData['speed']>1022) $instrumentsData['AIS'][$vehicle]['data']['speed'] = NULL;	
				else $instrumentsData['AIS'][$vehicle]['data']['speed'] = (float)filter_var($inInstrumentsData['speed'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)*185.2/3600; 	// SOG Speed over ground in m/sec 	(in 1/10 knot steps (0-102.2 knots) 1 023 = not available, 1 022 = 102.2 knots or higher)
			}
			$instrumentsData['AIS'][$vehicle]['cachedTime']['speed'] = $now;
		}
	}
	if(isset($inInstrumentsData['course'])){
		if($inInstrumentsData['scaled']) { 	// данные уже приведены к человеческому виду
			if($inInstrumentsData['course']==360) $instrumentsData['AIS'][$vehicle]['data']['course'] = NULL;
			else {
				if($inInstrumentsData['type'] == 27){
					if( !$instrumentsData['AIS'][$vehicle]['data']['course']){	// не будем принимать данные из сообщения 27 из-за меньшей точности
						$instrumentsData['AIS'][$vehicle]['data']['course'] = $inInstrumentsData['course']; 	// Путевой угол.
						$instrumentsData['AIS'][$vehicle]['cachedTime']['course'] = $now;
					}
				}
				else {
					$instrumentsData['AIS'][$vehicle]['data']['course'] = $inInstrumentsData['course']; 	// Путевой угол.
					$instrumentsData['AIS'][$vehicle]['cachedTime']['course'] = $now;
				}
			}
		}
		else{
			if($inInstrumentsData['type'] == 27) { 	// оказывается, там путевой угол в градусах
				if($inInstrumentsData['course']==511) $instrumentsData['AIS'][$vehicle]['data']['course'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['course'] = (float)filter_var($inInstrumentsData['course'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Путевой угол. COG Course over ground in degrees Degrees (0-359); 511 = not available = default
			}
			else {
				if($inInstrumentsData['course']==3600) $instrumentsData['AIS'][$vehicle]['data']['course'] = NULL;
				else $instrumentsData['AIS'][$vehicle]['data']['course'] = (float)filter_var($inInstrumentsData['course'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION)/10; 	// Путевой угол. COG Course over ground in degrees ( 1/10 = (0-3599). 3600 (E10h) = not available = default. 3601-4095 should not be used)
			};
			$instrumentsData['AIS'][$vehicle]['cachedTime']['course'] = $now;
		};
		//echo "inInstrumentsData['scaled']={$inInstrumentsData['scaled']}\n";
		//if($vehicle=='230985490') echo "inInstrumentsData['course']={$inInstrumentsData['course']}; course={$instrumentsData['AIS'][$vehicle]['data']['course']};\n";
	};
	if(isset($inInstrumentsData['heading'])){
		if($inInstrumentsData['scaled']) {
			if($inInstrumentsData['heading']==511) $instrumentsData['AIS'][$vehicle]['data']['heading'] = NULL;
			else $instrumentsData['AIS'][$vehicle]['data']['heading'] = $inInstrumentsData['heading']; 	// 
		}
		else {
			if($inInstrumentsData['heading']==511) $instrumentsData['AIS'][$vehicle]['data']['heading'] = NULL;
			else $instrumentsData['AIS'][$vehicle]['data']['heading'] = (float)filter_var($inInstrumentsData['heading'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Истинный курс. True heading Degrees (0-359) (511 indicates not available = default)
		};
		$instrumentsData['AIS'][$vehicle]['cachedTime']['heading'] = $now;
		//if($vehicle=='230985490') echo "inInstrumentsData['heading']={$inInstrumentsData['heading']}; heading={$instrumentsData['AIS'][$vehicle]['data']['heading']};\n\n";
	};
	if(isset($inInstrumentsData['maneuver'])){
		if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['maneuver'] = $inInstrumentsData['maneuver']; 	// данные уже приведены к человеческому виду
		else $instrumentsData['AIS'][$vehicle]['data']['maneuver'] = (int)filter_var($inInstrumentsData['maneuver'],FILTER_SANITIZE_NUMBER_INT); 	// Special manoeuvre indicator 0 = not available = default 1 = not engaged in special manoeuvre 2 = engaged in special manoeuvre (i.e. regional passing arrangement on Inland Waterway)
		if($instrumentsData['AIS'][$vehicle]['data']['maneuver'] === 0) $instrumentsData['AIS'][$vehicle]['data']['maneuver'] = NULL;
		$instrumentsData['AIS'][$vehicle]['cachedTime']['maneuver'] = $now;
	};
	if(isset($inInstrumentsData['raim'])) $instrumentsData['AIS'][$vehicle]['data']['raim'] = (bool)filter_var($inInstrumentsData['raim'],FILTER_SANITIZE_NUMBER_INT); 	// RAIM-flag Receiver autonomous integrity monitoring (RAIM) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use. See Table 50
	if(isset($inInstrumentsData['radio'])) $instrumentsData['AIS'][$vehicle]['data']['radio'] = (string)$inInstrumentsData['radio']; 	// Communication state
	$instrumentsData['AIS'][$vehicle]['data']['device'] = @$inInstrumentsData['device'];
	$instrumentsDataUpdated['AIS'][] = $currentDevice;
	//break; 	//comment break чтобы netAIS мог посылать информацию типа 5,24 и 6,8 в сообщени типа 1. Но gpsdAISd не имеет дела с netAIS?
case 5: 	// http://www.e-navigation.nl/content/ship-static-and-voyage-related-data
case 24: 	// Vendor ID не поддерживается http://www.e-navigation.nl/content/static-data-report
	//echo "JSON inInstrumentsData: \n"; print_r($inInstrumentsData); echo "\n";
	if(isset($inInstrumentsData['imo'])) {
		$instrumentsData['AIS'][$vehicle]['data']['imo'] = (string)$inInstrumentsData['imo']; 	// IMO number 0 = not available = default – Not applicable to SAR aircraft 0000000001-0000999999 not used 0001000000-0009999999 = valid IMO number; 0010000000-1073741823 = official flag state number.
		if($instrumentsData['AIS'][$vehicle]['data']['imo'] === '0') $instrumentsData['AIS'][$vehicle]['data']['imo'] = NULL;
	}
	if(isset($inInstrumentsData['ais_version'])) $instrumentsData['AIS'][$vehicle]['data']['ais_version'] = (int)filter_var($inInstrumentsData['ais_version'],FILTER_SANITIZE_NUMBER_INT); 	// AIS version indicator 0 = station compliant with Recommendation ITU-R M.1371-1; 1 = station compliant with Recommendation ITU-R M.1371-3 (or later); 2 = station compliant with Recommendation ITU-R M.1371-5 (or later); 3 = station compliant with future editions
	if(isset($inInstrumentsData['callsign'])){
		if($inInstrumentsData['callsign']=='@@@@@@@') $instrumentsData['AIS'][$vehicle]['data']['callsign'] = NULL;
		elseif($inInstrumentsData['callsign']) $instrumentsData['AIS'][$vehicle]['data']['callsign'] = (string)$inInstrumentsData['callsign']; 	// Call sign 7 x 6 bit ASCII characters, @@@@@@@ = not available = default. Craft associated with a parent vessel, should use “A” followed by the last 6 digits of the MMSI of the parent vessel. Examples of these craft include towed vessels, rescue boats, tenders, lifeboats and liferafts.
	}
	if(isset($inInstrumentsData['shipname'])){
		if($inInstrumentsData['shipname']=='@@@@@@@@@@@@@@@@@@@@') $instrumentsData['AIS'][$vehicle]['data']['shipname'] = NULL;
		elseif($inInstrumentsData['shipname']) $instrumentsData['AIS'][$vehicle]['data']['shipname'] = filter_var($inInstrumentsData['shipname'],FILTER_SANITIZE_STRING); 	// Maximum 20 characters 6 bit ASCII, as defined in Table 47 “@@@@@@@@@@@@@@@@@@@@” = not available = default. The Name should be as shown on the station radio license. For SAR aircraft, it should be set to “SAR AIRCRAFT NNNNNNN” where NNNNNNN equals the aircraft registration number.
	}
	if(isset($inInstrumentsData['shiptype'])) $instrumentsData['AIS'][$vehicle]['data']['shiptype'] = (int)filter_var($inInstrumentsData['shiptype'],FILTER_SANITIZE_NUMBER_INT); 	// Type of ship and cargo type 0 = not available or no ship = default 1-99 = as defined in § 3.3.2 100-199 = reserved, for regional use 200-255 = reserved, for future use Not applicable to SAR aircraft
	//echo "inInstrumentsData['shiptype']={$inInstrumentsData['shiptype']}; shiptype={$instrumentsData['AIS'][$vehicle]['data']['shiptype']};\n\n";
	//if(isset($inInstrumentsData['shiptype_text'])) $instrumentsData['AIS'][$vehicle]['data']['shiptype_text'] = filter_var($inInstrumentsData['shiptype_text'],FILTER_SANITIZE_STRING); 	// 
	//echo "inInstrumentsData['shiptype_text']={$inInstrumentsData['shiptype_text']}; shiptype_text={$instrumentsData['AIS'][$vehicle]['data']['shiptype_text']};\n\n";
	if(isset($inInstrumentsData['to_bow'])) $instrumentsData['AIS'][$vehicle]['data']['to_bow'] = (float)filter_var($inInstrumentsData['to_bow'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Reference point for reported position. Also indicates the dimension of ship (m) (see Fig. 42 and § 3.3.3) For SAR aircraft, the use of this field may be decided by the responsible administration. If used it should indicate the maximum dimensions of the craft. As default should A = B = C = D be set to “0”
	if(isset($inInstrumentsData['to_stern'])) $instrumentsData['AIS'][$vehicle]['data']['to_stern'] = (float)filter_var($inInstrumentsData['to_stern'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Reference point for reported position.
	if(isset($inInstrumentsData['to_port'])) $instrumentsData['AIS'][$vehicle]['data']['to_port'] = (float)filter_var($inInstrumentsData['to_port'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Reference point for reported position.
	if(isset($inInstrumentsData['to_starboard'])) $instrumentsData['AIS'][$vehicle]['data']['to_starboard'] = (float)filter_var($inInstrumentsData['to_starboard'],FILTER_SANITIZE_NUMBER_FLOAT,FILTER_FLAG_ALLOW_FRACTION); 	// Reference point for reported position.
	if(@$instrumentsData['AIS'][$vehicle]['data']['to_bow']===0 and @$instrumentsData['AIS'][$vehicle]['data']['to_stern']===0 and @$instrumentsData['AIS'][$vehicle]['data']['to_port']===0 and @$instrumentsData['AIS'][$vehicle]['data']['to_starboard']===0){
		$instrumentsData['AIS'][$vehicle]['data']['to_bow'] = null;
		$instrumentsData['AIS'][$vehicle]['data']['to_stern'] = null;
		$instrumentsData['AIS'][$vehicle]['data']['to_port'] = null;
		$instrumentsData['AIS'][$vehicle]['data']['to_starboard'] = null;
	}
	if(isset($inInstrumentsData['epfd'])) {
		$instrumentsData['AIS'][$vehicle]['data']['epfd'] = (int)filter_var($inInstrumentsData['epfd'],FILTER_SANITIZE_NUMBER_INT); 	// Type of electronic position fixing device. 0 = undefined (default) 1 = GPS 2 = GLONASS 3 = combined GPS/GLONASS 4 = Loran-C 5 = Chayka 6 = integrated navigation system 7 = surveyed 8 = Galileo, 9-14 = not used 15 = internal GNSS
		if($instrumentsData['AIS'][$vehicle]['data']['epfd'] == 0) $instrumentsData['AIS'][$vehicle]['data']['epfd'] = null;
	}
	//if(isset($inInstrumentsData['epfd_text'])) $instrumentsData['AIS'][$vehicle]['data']['epfd_text'] = (string)$inInstrumentsData['epfd_text']; 	// 
	if(isset($inInstrumentsData['eta'])) {
		$instrumentsData['AIS'][$vehicle]['data']['eta'] = (string)$inInstrumentsData['eta']; 	// ETA Estimated time of arrival; MMDDHHMM UTC Bits 19-16: month; 1-12; 0 = not available = default  Bits 15-11: day; 1-31; 0 = not available = default Bits 10-6: hour; 0-23; 24 = not available = default Bits 5-0: minute; 0-59; 60 = not available = default For SAR aircraft, the use of this field may be decided by the responsible administration
		if($instrumentsData['AIS'][$vehicle]['data']['eta'] === '0') $instrumentsData['AIS'][$vehicle]['data']['eta'] = null;
	}
	if(isset($inInstrumentsData['draught'])){	// осадка не может быть 0
	 	// данные уже приведены к человеческому виду
		if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['draught'] = $inInstrumentsData['draught']; 	// осадка в метрах
		else $instrumentsData['AIS'][$vehicle]['data']['draught'] = (float)filter_var($inInstrumentsData['draught'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Maximum present static draught In m ( 1/10 m, 255 = draught 25.5 m or greater, 0 = not available = default; in accordance with IMO Resolution A.851 Not applicable to SAR aircraft, should be set to 0)
		if($instrumentsData['AIS'][$vehicle]['data']['draught'] == 0) $instrumentsData['AIS'][$vehicle]['data']['draught'] = null;
		//echo "inInstrumentsData['draught']={$inInstrumentsData['draught']}; draught={$instrumentsData['AIS'][$vehicle]['data']['draught']};\n\n";
	}
	if(isset($inInstrumentsData['destination'])){
		$instrumentsData['AIS'][$vehicle]['data']['destination'] = filter_var($inInstrumentsData['destination'],FILTER_SANITIZE_STRING); 	// Destination Maximum 20 characters using 6-bit ASCII; @@@@@@@@@@@@@@@@@@@@ = not available For SAR aircraft, the use of this field may be decided by the responsible administration
		if($instrumentsData['AIS'][$vehicle]['data']['destination'] == '@@@@@@@@@@@@@@@@@@@@') $instrumentsData['AIS'][$vehicle]['data']['destination'] = null;
		$instrumentsData['AIS'][$vehicle]['cachedTime']['destination'] = $now;
	}
	if(isset($inInstrumentsData['dte'])) {
		$instrumentsData['AIS'][$vehicle]['data']['dte'] = (int)filter_var($inInstrumentsData['dte'],FILTER_SANITIZE_NUMBER_INT); 	// DTE Data terminal equipment (DTE) ready (0 = available, 1 = not available = default) (see § 3.3.1)
		if($instrumentsData['AIS'][$vehicle]['data']['dte'] == 1) $instrumentsData['AIS'][$vehicle]['data']['dte'] = null;
	}
	$instrumentsData['AIS'][$vehicle]['data']['device'] = @$inInstrumentsData['device'];
	$instrumentsDataUpdated['AIS'][] = $currentDevice;
	//break; 	// comment break чтобы netAIS мог посылать информацию типа 5,24 и 6,8 в сообщени типа 1
case 6: 	// http://www.e-navigation.nl/asm  http://192.168.10.10/gpsd/AIVDM.adoc
case 8: 	// 
	//echo "JSON inInstrumentsData:\n"; print_r($inInstrumentsData); echo "\n";
	if(isset($inInstrumentsData['dac'])){
		$instrumentsData['AIS'][$vehicle]['data']['dac'] = (string)$inInstrumentsData['dac']; 	// Designated Area Code
		$instrumentsData['AIS'][$vehicle]['cachedTime']['dac'] = $now;
	}
	if(isset($inInstrumentsData['fid'])) $instrumentsData['AIS'][$vehicle]['data']['fid'] = (string)$inInstrumentsData['fid']; 	// Functional ID
	if(isset($inInstrumentsData['vin'])) $instrumentsData['AIS'][$vehicle]['data']['vin'] = (string)$inInstrumentsData['vin']; 	// European Vessel ID
	if(isset($inInstrumentsData['length'])){
		if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['length'] = $inInstrumentsData['length']/10;	// Длина всё равно в дециметрах!!!
		else $instrumentsData['AIS'][$vehicle]['data']['length'] = (float)filter_var($inInstrumentsData['length'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Length of ship in m
		if(!$instrumentsData['AIS'][$vehicle]['data']['length']) $instrumentsData['AIS'][$vehicle]['data']['length'] = null;
	}
	if(isset($inInstrumentsData['beam'])) {
		if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['beam'] = $inInstrumentsData['beam']/10;	// Ширина всё равно в дециметрах!!!
		else $instrumentsData['AIS'][$vehicle]['data']['beam'] = (float)filter_var($inInstrumentsData['beam'],FILTER_SANITIZE_NUMBER_INT)/10; 	// Beam of ship in m ширина, длина бимса.
		if(!$instrumentsData['AIS'][$vehicle]['data']['beam']) $instrumentsData['AIS'][$vehicle]['data']['beam'] = null;
	}
	if(isset($inInstrumentsData['shiptype']) and !$instrumentsData['AIS'][$vehicle]['data']['shiptype']) $instrumentsData['AIS'][$vehicle]['data']['shiptype'] = (string)$inInstrumentsData['shiptype']; 	// Ship/combination type ERI Classification В какой из посылок тип правильный - неизвестно, поэтому будем брать только из одной
	//if(isset($inInstrumentsData['shiptype_text']) and !$instrumentsData['AIS'][$vehicle]['data']['shiptype_text'])$instrumentsData['AIS'][$vehicle]['data']['shiptype_text'] = filter_var($inInstrumentsData['shiptype_text'],FILTER_SANITIZE_STRING); 	// 
	if(isset($inInstrumentsData['hazard'])) $instrumentsData['AIS'][$vehicle]['data']['hazard'] = (int)filter_var($inInstrumentsData['hazard'],FILTER_SANITIZE_NUMBER_INT); 	// Hazardous cargo | 0 | 0 blue cones/lights | 1 | 1 blue cone/light | 2 | 2 blue cones/lights | 3 | 3 blue cones/lights | 4 | 4 B-Flag | 5 | Unknown (default)
	//if(isset($inInstrumentsData['hazard_text'])) $instrumentsData['AIS'][$vehicle]['data']['hazard_text'] = filter_var($inInstrumentsData['hazard_text'],FILTER_SANITIZE_STRING); 	// 
	if(@$inInstrumentsData['draught'] and !$instrumentsData['AIS'][$vehicle]['data']['draught']) {
		if($inInstrumentsData['scaled']) $instrumentsData['AIS'][$vehicle]['data']['draught'] = $inInstrumentsData['draught']/100;	// Осадка всё равно в сантиметрах!!!
		else $instrumentsData['AIS'][$vehicle]['data']['draught'] = (float)filter_var($inInstrumentsData['draught'],FILTER_SANITIZE_NUMBER_INT)/100; 	// Draught in m ( 1-200 * 0.01m, default 0) осадка
		if(!$instrumentsData['AIS'][$vehicle]['data']['draught']) $instrumentsData['AIS'][$vehicle]['data']['draught'] = null;
	}
	if(isset($inInstrumentsData['loaded'])) {
		$instrumentsData['AIS'][$vehicle]['data']['loaded'] = (int)filter_var($inInstrumentsData['loaded'],FILTER_SANITIZE_NUMBER_INT); 	// Loaded/Unloaded | 0 | N/A (default) | 1 | Unloaded | 2 | Loaded
		if(!$instrumentsData['AIS'][$vehicle]['data']['loaded']) $instrumentsData['AIS'][$vehicle]['data']['loaded'] = null;
	}
	//if(isset($inInstrumentsData['loaded_text'])) $instrumentsData['AIS'][$vehicle]['data']['loaded_text'] = filter_var($inInstrumentsData['loaded_text'],FILTER_SANITIZE_STRING); 	// 
	if(isset($inInstrumentsData['speed_q'])) $instrumentsData['AIS'][$vehicle]['data']['speed_q'] = (int)filter_var($inInstrumentsData['speed_q'],FILTER_SANITIZE_NUMBER_INT); 	// Speed inf. quality 0 = low/GNSS (default) 1 = high
	if(isset($inInstrumentsData['course_q'])) $instrumentsData['AIS'][$vehicle]['data']['course_q'] = (int)filter_var($inInstrumentsData['course_q'],FILTER_SANITIZE_NUMBER_INT); 	// Course inf. quality 0 = low/GNSS (default) 1 = high
	if(isset($inInstrumentsData['heading_q'])) $instrumentsData['AIS'][$vehicle]['data']['heading_q'] = (int)filter_var($inInstrumentsData['heading_q'],FILTER_SANITIZE_NUMBER_INT); 	// Heading inf. quality 0 = low/GNSS (default) 1 = high
	$instrumentsData['AIS'][$vehicle]['data']['device'] = @$inInstrumentsData['device'];
	$instrumentsDataUpdated['AIS'][] = $currentDevice;
	//break; 	// comment break чтобы netAIS мог посылать информацию типа 5,24 и 6,8 в сообщени типа 1
case 14:	// Safety related broadcast message https://www.e-navigation.nl/content/safety-related-broadcast-message
	if(isset($inInstrumentsData['text'])) $instrumentsData['AIS'][$vehicle]['data']['safety_related_text'] = filter_var($inInstrumentsData['text'],FILTER_SANITIZE_STRING); 	// 
	$instrumentsData['AIS'][$vehicle]['data']['device'] = @$inInstrumentsData['device'];
	//$instrumentsDataUpdated['AIS'][] = $currentDevice;	// это сообщение приходит только вместе с сообщением 1, поэтому не будем по его приёме указывать на этот факт. Тогда содержание этого сообщения будет отослано клиентам только тогда, когда будет отослано (следующее) сообщение 1
	break;
}; // end switch($inInstrumentsData['type'])
$instrumentsDataUpdated['AIS'] = array_unique($instrumentsDataUpdated['AIS']);

if(!@$instrumentsData['AIS'][$vehicle]['data']['length'] and @$instrumentsData['AIS'][$vehicle]['data']['to_bow'] and @$instrumentsData['AIS'][$vehicle]['data']['to_stern']){
	$instrumentsData['AIS'][$vehicle]['data']['length'] = $instrumentsData['AIS'][$vehicle]['data']['to_bow'] + $instrumentsData['AIS'][$vehicle]['data']['to_stern'];
};
if(!@$instrumentsData['AIS'][$vehicle]['data']['beam'] and @$instrumentsData['AIS'][$vehicle]['data']['to_port'] and @$instrumentsData['AIS'][$vehicle]['data']['to_starboard']){
	$instrumentsData['AIS'][$vehicle]['data']['beam'] = $instrumentsData['AIS'][$vehicle]['data']['to_port'] + $instrumentsData['AIS'][$vehicle]['data']['to_starboard'];
};
//echo "\n instrumentsData[AIS][$vehicle]['data']:\n"; print_r($instrumentsData['AIS'][$vehicle]['data']);echo "\n";
return $instrumentsDataUpdated;
}; // end function updAISdata


function chkFreshOfData(){
/* Проверим актуальность всех данных */
global $instrumentsData,$gpsdProxyTimeouts;
$instrumentsDataUpdated = array(); // массив, где указано, какие классы изменениы и кем.
$TPVtimeoutMultiplexor = 30;	// через сколько таймаутов свойство удаляется совсем
//print_r($instrumentsData);
$now = time();
foreach($instrumentsData as $class => $devices){
	switch($class){
	case 'AIS':
		foreach($instrumentsData['AIS'] as $id => $vehicle){
			//echo "[chkFreshOfData] AIS id=$id;\n";
			if(isset($gpsdProxyTimeouts['AIS']['noVehicle']) and isset($vehicle['timestamp']) and (($now - $vehicle['timestamp'])>$gpsdProxyTimeouts['AIS']['noVehicle'])) {
				unset($instrumentsData['AIS'][$id]); 	// удалим цель, последний раз обновлявшуюся давно
				$instrumentsDataUpdated['AIS'][] = '';
				//echo "Данные AIS для судна ".$id." протухли на ".($now - $vehicle['timestamp'])." сек при норме {$gpsdProxyTimeouts['AIS']['noVehicle']}       \n";
				continue;	// к следующей цели AIS
			};
			if($instrumentsData['AIS'][$id]['cachedTime']){ 	// поищем, не протухло ли чего
				foreach($instrumentsData['AIS'][$id]['cachedTime'] as $type => $cachedTime){
					if(!is_null($vehicle['data'][$type]) and isset($gpsdProxyTimeouts['AIS'][$type]) and (($now - $cachedTime) > $gpsdProxyTimeouts['AIS'][$type])) {
						$instrumentsData['AIS'][$id]['data'][$type] = null;
						$instrumentsDataUpdated['AIS'][] = '';
						//echo "Данные AIS ".$type." для судна ".$id." протухли на ".($now - $cachedTime)." сек                     \n";
					}
					elseif(is_null($vehicle['data'][$type]) and isset($gpsdProxyTimeouts['AIS'][$type]) and (($now - $cachedTime) > (2*$gpsdProxyTimeouts['AIS'][$type]))) {
						unset($instrumentsData['AIS'][$id]['data'][$type]);
						unset($instrumentsData['AIS'][$id]['cachedTime'][$type]);
						$instrumentsDataUpdated['AIS'][] = '';
						//echo "Данные AIS ".$type." для судна ".$id." совсем протухли на ".($now - $cachedTime)." сек                     \n";
					};
				};
			};
		};
		if(@$instrumentsDataUpdated[$class]) {
			//echo "[chkFreshOfData] instrumentsDataUpdated[$class]:\n"; print_r($instrumentsDataUpdated[$class]); echo "\n";
			$instrumentsDataUpdated[$class] = array_unique($instrumentsDataUpdated[$class]);
		};
		break;
	case 'TPV':	// A TPV object is a time-position-velocity report.
	case 'ATT':	// An ATT object is a vehicle-attitude report. It is returned by digital-compass and gyroscope sensors; depending on device, it may include: heading, pitch, roll, yaw, gyroscope, and magnetic-field readings. 
	case 'SKY':	// A SKY object reports a sky view of the GPS satellite positions. 
	case 'GST':	// A GST object is a pseudorange noise report.
	case 'TOFF':	// This message is emitted on each cycle and reports the offset between the host’s clock time and the GPS time at top of the second (actually, when the first data for the reporting cycle is received).
	case 'PPS':	// This message is emitted each time the daemon sees a valid PPS (Pulse Per Second) strobe from a device. This message exactly mirrors the TOFF message.
	case 'OSC':	// This message reports the status of a GPS-disciplined oscillator (GPSDO).
	default:
		$dataLongTimeOutFlag = false;
		foreach($instrumentsData[$class] as $device => $data){
			foreach($instrumentsData[$class][$device]['cachedTime'] as $type => $cachedTime){ 	// поищем, не протухло ли чего
				//if($type=='wspeedr')echo "type=$type, val=".$instrumentsData[$class][$device]['data'][$type]."                 \n";
				if((!is_null($data['data'][$type])) and isset($gpsdProxyTimeouts[$class][$type]) and (($now - $cachedTime) > $gpsdProxyTimeouts[$class][$type])) {	// Notice if on $gpsdProxyTimeouts not have this $type
					$instrumentsData[$class][$device]['data'][$type] = null;
					$instrumentsDataUpdated[$class][] = $device;
					//echo "Данные ".$type." от устройства ".$device." протухли на ".($now - $cachedTime)." сек            \n"; print_r($instrumentsData[$class][$device]['data'][$type]); echo "\n";
					//echo "instrumentsData[$class][$device] после очистки:"; print_r($instrumentsData[$class][$device]['data']);
				}
				elseif((is_null($data['data'][$type])) and isset($gpsdProxyTimeouts[$class][$type]) and (($now - $cachedTime) > ($TPVtimeoutMultiplexor*$gpsdProxyTimeouts[$class][$type]))) {	// Notice if on $gpsdProxyTimeouts not have this $type
					unset($instrumentsData[$class][$device]['data'][$type]);
					unset($instrumentsData[$class][$device]['cachedTime'][$type]);
					$instrumentsDataUpdated[$class][] = $device;
					$dataLongTimeOutFlag = true;
					//echo "Данные ".$type." от устройства ".$device." совсем протухли на ".($now - $cachedTime)." сек   \n";
				};
			};
			//echo "instrumentsData[$class][$device] после очистки:"; print_r($instrumentsData[$class][$device]['data']);
			// Удалим все данные устройства, которое давно ничего не давало из контролируемых на протухание параметров
			if($dataLongTimeOutFlag and $instrumentsData[$class][$device]['cachedTime']) {
				$toDel = TRUE;
				// поищем, есть ли среди кешированных контролируемые параметры
				// Если нет, это значит, что все контролируемые параметры были удалены выше
				// как "совсем протухли", и остались только неконтролируемые.
				// Что позволяет считать, что это устройства "давно ничего не давало".
				// Однако, их может не быть ещё, а не уже, поэтому нужен флаг
				foreach($instrumentsData[$class][$device]['cachedTime'] as $type => $cachedTime){	
					if(@$gpsdProxyTimeouts[$class][$type]) {
						$toDel = FALSE;
						break;
					};
				};
				if($toDel) {	// 
					unset($instrumentsData[$class][$device]); 	// 
					$instrumentsDataUpdated[$class][] = $device;
					echo "All $class data of the device $device purged by the long silence.                        \n";
				};
			};
		};
		if(@$instrumentsDataUpdated[$class]) $instrumentsDataUpdated[$class] = array_unique($instrumentsDataUpdated[$class]);
	};
};
//echo "[chkFreshOfData] Изменено:                 "; print_r($instrumentsDataUpdated); echo"\n";
return $instrumentsDataUpdated;
} // end function chkFreshOfData


function chkSubscribe(){
/**/
global $subscribe,$messages;
$subscribe = array();
foreach($messages as $sockKey => $data){
	if(!@$data['subscribe']) continue;	// сокет данных (gpsd) не имеет поля subscribe
	if(@$messages[$sockKey]['POLL']) continue;
	if(@$messages[$sockKey]['disable']) continue;
	$subscribe = array_merge($subscribe,$data['subscribe']);
};
$subscribe = array_unique($subscribe);
}; // end function chkSubscribe


function updUserParms($sockKey,$params){
/* чтобы всякая присланная фигня не мешала, явно установим параметры */
global $messages;
foreach($params as $parm=>$value){
	switch($parm){
	case 'subscribe':
		// Позволяем прислать json массив вместо строки, но если там
		// прислали совсем ерунду - это их проблемы
		if(is_array($value)) $messages[$sockKey]['subscribe'] = $value;
		else $messages[$sockKey]['subscribe'] = explode(',',$value);	
		chkSubscribe();
		break;
	case 'dontUseDevices':
		$messages[$sockKey]['dontUseDevices'] = $value;
		break;
	case 'enable':
		$messages[$sockKey]['disable'] = !$value;
		break;
	};
};
}; // end function updUserParms
?>
