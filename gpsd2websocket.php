<?php
ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
//ini_set('error_reporting', E_ALL & ~E_STRICT & ~E_DEPRECATED);
chdir(__DIR__); // задаем директорию выполнение скрипта
require('fCommon.php');
/* 
*/
$longopts  = array(
	'params::',
	'gpsdProxyHost::',
	'gpsdProxyPort::',
	'dataSourceHost::',
	'dataSourcePort::',
	'noClientTimeout::',
	'boatInfo::',
	'gpsdProxyTimeouts::',
	'dontUseDevices::',
	'defaultSubscribe::',
	'noRealTime::',
	'help'
);
$options = getopt('h', $longopts);
if(isset($options['h']) or isset($options['help'])){
?>
gpsd to websocket proxy server
Usage:
php gpsd2websocket.php [--params=params.php] [any parameters]
Parameters:
--params=params.php  The parameters file. Explicitly specified parameters take precedence over parameters from a file.
--gpsdProxyHost=host  To the gpsd2websocket connection host. The square brackets [] on ipv6 address is required.
--gpsdProxyPort=port  To the gpsd2websocket connection port.
--dataSourceHost=host  The instruments data source, gpsd host.
--dataSourcePort=port The instruments data source, gpsd port. 
--gpsdProxyTimeouts="{json string}"  Timeouts for gpsd data types.
--dontUseDevices="{json string}"  Global gpsd devices black list.
--defaultSubscribe="TPV,ATT"  Default returned gpsd data classes. All list: TPV,ATT,SKY,TOFF,PPS,OSC,AIS
--boatInfo="{json string}"  Vehacle description.
--noClientTimeout=30  sec., disconnect from gpsd on no any client present. 0 to disable.
--noRealTime="AIS,SKY"  A list of gpsd classes that do not have to be given to clients immediately.
-h --help  this help
<?php
return;
};

if(!($params=@$options['params'])) $params = 'params.php';
@include($params);

//$gpsdProxyHost = '0.0.0.0';
if($options['gpsdProxyHost']=filter_var(@$options['gpsdProxyHost'],FILTER_VALIDATE_DOMAIN)) $gpsdProxyHost = $options['gpsdProxyHost'];	// хотя FILTER_VALIDATE_DOMAIN, по-моему, всё пропускает
elseif(!@$gpsdProxyHost) $gpsdProxyHost = '[::]';
//echo "options['gpsdProxyHost']={$options['gpsdProxyHost']}; gpsdProxyHost=$gpsdProxyHost;\n";
if($options['gpsdProxyPort']=filter_var(@$options['gpsdProxyPort'],FILTER_VALIDATE_INT)) $gpsdProxyPort = $options['gpsdProxyPort'];
elseif(!@$gpsdProxyPort) $gpsdProxyPort = 3839;
//echo "gpsdProxy Host=$gpsdProxyHost; Port=$gpsdProxyPort;\n";
if($options['dataSourceHost']=filter_var(@$options['dataSourceHost'],FILTER_VALIDATE_DOMAIN)) $dataSourceHost = $options['dataSourceHost'];
elseif(!@$dataSourceHost) $dataSourceHost = 'localhost';
if($options['dataSourcePort']=filter_var(@$options['dataSourcePort'],FILTER_VALIDATE_INT)) $dataSourcePort = $options['dataSourcePort'];
elseif(!@$dataSourcePort) $dataSourcePort = 2947;	// default gpsd
//echo "dataSource Host=$dataSourceHost; Port=$dataSourcePort;\n";
if($options['noClientTimeout']=filter_var(@$options['noClientTimeout'],FILTER_VALIDATE_INT)) $noClientTimeout = $options['noClientTimeout'];
elseif(!@$noClientTimeout) $noClientTimeout = 30;	// sec., disconnect from gpsd on no any client present. 0 to disable.
//echo "noClientTimeout:"; print_r($noClientTimeout); echo "\n";
if($options['boatInfo']=json_decode(@$options['boatInfo'],TRUE)) $boatInfo = $options['boatInfo'];
elseif(!@$boatInfo) $boatInfo = array(
//'to_echosounder'=>0,		// поправка к получаемой от прибора глубине до желаемой: от поверхности или от киля. Correction to the depth received from the device to the desired depth: from the surface or from the keel.
//'magdev'=>0		// девиация компаса, градусы. Magnetic deviation of the compass, degrees
);
//echo "boatInfo:"; print_r($boatInfo); echo "\n";
// перечень типов данных каждого источника в gpsd, для которых требуется контролтровать время жизни
// gpsd data types and their lifetime, sec
if($options['gpsdProxyTimeouts']=json_decode(@$options['gpsdProxyTimeouts'],TRUE)) $gpsdProxyTimeouts = $options['gpsdProxyTimeouts'];
elseif(!@$gpsdProxyTimeouts) $gpsdProxyTimeouts = array(  	// время в секундах после последнего обновления, после которого считается, что данные протухли.
'TPV' => array( 	// time-position-velocity report datatypes
	'altHAE' => 20, 	// Altitude, height above ellipsoid, in meters. Probably WGS84.
	'altMSL' => 20, 	// MSL Altitude in meters. 
	'alt' => 20, 	// legacy Altitude in meters. 
	'lat' => 10,
	'lon' => 10,
	'track' => 10, 	// истинный путевой угол
	'heading' => 10,	// истинный курс
	'speed' => 5,	// Speed over ground, meters per second.
	'errX' => 30,
	'errY' => 30,
	'errS' => 30,
	'magtrack' => 10, 	// магнитный курс
	'magvar' => 31557600, 	// магнитное склонение, один юлианский год.
	'mheading' => 10,	// магнитный курс
	'depth' => 5, 		// глубина
	'wanglem' => 3, 	// Wind angle magnetic in degrees.
	'wangler' => 3, 	// Wind angle relative in degrees.
	'wanglet' => 3, 	// Wind angle true in degrees.
	'wspeedr' => 3, 	// Wind speed relative in meters per second.
	'wspeedt' => 3, 	// Wind speed true in meters per second.
	'time' => 10		// Set same as lat lon. Regiure!
),
'AIS' => array( 	// AIS datatypes. Реально задержка даже от реального AIS может быть минута, а через интернет - до трёх
	'noVehicle' => 20*60,	// время в секундах, в течении которого цель AIS сохраняется в кеше после получения от неё последней информации
	 						// seconds, time of continuous absence of the vessel in AIS, when reached - is deleted from the data. "when a ship is moored or at anchor, the position message is only broadcast every 180 seconds;"
	'status' => 86400, 	// Navigational status, one day сутки
	'accuracy' => 60*5, 	// Position accuracy
	'turn' => 60*3, 	// 
	'lon' => 60*4, 	// 
	'lat' => 60*4, 	// 
	'speed' => 60*2, 	// 
	'course' => 60*3, 	// 
	'heading' => 60*3, 	// 
	'maneuver' => 60*3 	// 
)
);
//echo "gpsdProxyTimeouts:"; print_r($gpsdProxyTimeouts); echo "\n";
if($options['dontUseDevices']=json_decode(@$options['dontUseDevices'],TRUE)) $dontUseDevices = $options['dontUseDevices'];
elseif(!@$dontUseDevices) $dontUseDevices = array();	// Глобальный список устройств, данные указанного класса от которых следует игнорировать. array('class'=>'device, ...device')
//echo "dontUseDevices:"; print_r($dontUseDevices); echo "\n";
if(@$options['defaultSubscribe']) {
	if($tmp=json_decode($options['defaultSubscribe'],TRUE)) $defaultSubscribe = $tmp;	// разрешим как json массив, так и строку
	elseif($tmp=explode(',',$options['defaultSubscribe'])) $defaultSubscribe = $tmp;
};
if(!@$defaultSubscribe) $defaultSubscribe = array('TPV','ATT');	// Умолчальный ответ: координаты, направление, курс и глубина.
//echo "defaultSubscribe:"; print_r($defaultSubscribe); echo "\n";
if(@$options['noRealTime']){
	if($tmp=json_decode($options['noRealTime'],TRUE)) $noRealTime = $tmp;	// разрешим как json массив, так и строку
	elseif($tmp=explode(',',$options['noRealTime'])) $noRealTime = $tmp;
};
if(!@$noRealTime) $noRealTime = array('AIS','SKY');	// список классов, которые не обязательно отдавать клиентам немедленно по получению данных. Их можно отдать после окончания приёма.
//echo "noRealTime:"; print_r($noRealTime); echo "\n";

$subscribe = array();	// текущая подписка: на что подписаны все клиенты
$currentNoRealTime = array();	// текуший список классов, которые не обязательно отдавать клиентам немедленно.
$isNoRealTime = false;	// приняты данные классов, которые отдавать сразу не обязательно.
$dataSourceSock = false;	// Соединение для gpsd, ещё не создано
$masterSock = false; 	// Соединение для приёма клиентов, входное соединение, ещё не создано
$instrumentsData = array();	// буфер, массив данных, получаемых от gpsd
$currentDevice = 'dev/nul';	// поле device в сообщениях gpsd. Например, если спутников нет, то вместо непосылки объекта SKY будет прислано {"class":"SKY"}, без device, хотя device уже известно.
$sockets = array(); 	// список функционирующих клиентских сокетов и $dataSourceSock, без $masterSock
/*$messages: массив "номер сокета в массиве $sockets" => "массив [
'output'=> array(сообщений), // сообщения для отправки через этот сокет на следующем обороте
'greeting'=>TRUE/FALSE,	// признак, что приветствие протокола послано
'inBuf'=>''	// буфер для сбора строк обращения клиента, когда их больше одной
'protocol'=>''/'WS'	// признак, что общение происходит по протоколу socket (''), или websocket ('WS')
'zerocnt' => 0	// счётчик подряд посланных пустых сообщений. 
'subscribe'=>'' // массив подписки, TPV,AIS...
'dontUseDevices' //
]" номеров сокетов подключившихся клиентов
*/
$messages = array(); 	// 
$socksRead = array(); $socksWrite = array(); $socksError = array(); 	// массивы для изменивших состояние сокетов (с учётом, что они в socket_select() по ссылке, и NULL прямо указать нельзя)
$greeting = '{"class":"VERSION","release":"gpsd2websocket_0","rev":"beta","proto_major":3,"proto_minor":0}';
$minSocketTimeout = 86400;	// сек., сутки
// определим, какое минимальное время протухания величины указано в конфиге
array_walk_recursive($gpsdProxyTimeouts, function($val){
											global $minSocketTimeout;
											if(is_numeric($val) and ($val<$minSocketTimeout)) $minSocketTimeout = $val;
										});
if($minSocketTimeout == 86400) $minSocketTimeout = 30;
//echo "minSocketTimeout=$minSocketTimeout;\n";
$SocketTimeout = $minSocketTimeout;	// null - ждать вечно. Раз в секунд основной цикл провернётся для контроля всего.
$lastClientExchange = time();	// время последней коммуникации какого-нибудь клиента
$DEVICES = array();	// список устройств, сообщаемый gpsd, path => array(). Для полезных целей не используется.

$rotateBeam = array("|","/","-","\\");
$rBi = 0;

chkSocks();	// создадим сокеты для gpsd и для приёма входящих соединений
do {
	// Отсюда до socket_select должно быть только то, что требуется непосредственно для обеспечения
	// чтения и записи сокетов. Всё остальное, что требуется после обработки соединений, выделения
	// полезных данных и их обработки - должно быть в конце цикла.
	// Хотя это, разумеется, без разницы, и не очень-то удобно. Зато концептуально.
	//echo "Начало главного цикла =============\n";
	// ===============================
	// мы собираемся читать все сокеты
	$socksRead = $sockets; 	
	$socksRead[] = $masterSock; 	// 

	$socksWrite = array(); 	// очистим массив 
	foreach($messages as $i => $message){ 	// пишем только в сокеты, полученные от masterSock путём socket_accept
		if(@$message['output']){
			$socksWrite[] = $sockets[$i]; 	// если есть, что писать -- добавим этот сокет в те, в которые будем писать
		};
	}
	//echo "socksRead перед socket_select ";print_r($socksRead);
	$socksError = $sockets; 	// 
	$socksError[] = $masterSock; 	// 

	// =============== ожидание сокетов ================
	$num_changed_sockets = socket_select($socksRead, $socksWrite, $socksError, $SocketTimeout); 	// должно ждать
	// ===============================
	// Информация в последней строке терминала
	// если после ожидания - то будет видно количество сокетов, готовых к записи
	echo($rotateBeam[$rBi]);	// вращающаяся палка
	if($dataSourceSock) $str = (count($sockets)-1)." user connections, and";
	else $str = (count($sockets))." user connections, and no";
	echo "Has $str data source. Ready ".count($socksRead)." read and ".count($socksWrite)." write socks\r";
	$rBi++;
	if($rBi>=count($rotateBeam)) $rBi = 0;

	$SocketTimeout = $minSocketTimeout;	// Вернём умолчальный таймаут, если кто-нибудь его поменял.

	// ===============================
	//echo "Пишем в ",count($socksWrite)," сокетов                     \n";
	// Здесь пишется в сокеты то, что попало в $messages на предыдущем обороте. Тогда соответствующие сокеты проверены на готовность, и готовые попали в $socksWrite. 
	// в ['output'] элемент - всегда текст или массив из текста [0] и параметров передачи (для websocket). Но у нас всегда текст, так что - никаких параметров.
	foreach($socksWrite as $socket){
		$sockKey = array_search($socket,$sockets);	// 
		$msg='';
		foreach($messages[$sockKey]['output'] as &$msg) { 	// все накопленные сообщения. & для экономии памяти, но что-то не экономится...
			//echo "Пишем для сокета $sockKey сообщение \n|$msg|\n";
			$msgParams = null;
			if(is_array($msg)) list($msg,$msgParams) = $msg;	// второй элемент -- тип фрейма
			switch(@$messages[$sockKey]['protocol']){
			case 'WS':
				$msg = wsEncode($msg,$msgParams);	
				break;
			case 'WS handshake':
				$messages[$sockKey]['protocol'] = 'WS';
				break;
			default:	// просто сокет, например, в виде telnet
				$msg .= "\n\n";
			};
			
			$msgLen = mb_strlen($msg,'8bit');
			//$msgLen = strlen($msg);
			$res = socket_write($socket, $msg, $msgLen);
			if($res === FALSE) { 	// клиент умер
				echo "\n\nFailed to write data to socket by: " . socket_strerror(socket_last_error($sock)) . "\n";
				chkSocks($socket);
				continue 3;	// к следующему сокету
			}
			elseif($res <> $msgLen){	// клиент не принял всё. У него проблемы?
				echo "\n\nNot all data was writed to socket by: " . socket_strerror(socket_last_error($sock)) . "\n";
				chkSocks($socket);
				continue 3;	// к следующему сокету
			}
			$lastClientExchange = time();
		}
		$messages[$sockKey]['output'] = array();
		unset($msg);
	};
	// ===============================

	// ===============================
	//echo "Читаем из ",count($socksRead)," сокетов                    \n";
	// массив source => [class, ...class] классов gpsd по источникам, которые обновились за текущий оборот. Т.е., список классов, данные которых надо отдать клиентам на следующем обороте.
	if($isNoRealTime and $currentNoRealTime) {
		//echo "Имеются необязательные данные:      "; print_r($currentNoRealTime); echo "\n";
		$currentNoRealTime = array_unique($currentNoRealTime);
		$instrumentsDataUpdated = array_fill_keys($currentNoRealTime,array(''));	//
		$SocketTimeout = 0;	// если ничего не будет принято, то основной цикл провернётся через секунду. (0 - сразу)
	}
	else $instrumentsDataUpdated = array();	
	$currentNoRealTime = array();
	foreach($socksRead as $socket){
		socket_clear_error($socket);
		$sockKey = array_search($socket,$sockets); 	// Для $masterSock это лишнее, но здесь это делать концептуально
		// ==============новое подключение=================
		if($socket === $masterSock) { 	// новое подключение, здесь именно ===, т.е., для говёного PHP8 - тот же объект
			$sock = socket_accept($socket); 	// новый сокет для подключившегося клиента
			if(!$sock) {	// В говняном PHP8 это объект, а в нормальном PHP - ресурс. Поэтому проверить, что именно вернулось от socket_accept довольно громоздко, а потому - не нужно.
				echo "Failed to accept incoming by: " . socket_strerror(socket_last_error($socket)) . "\n";
				chkSocks($socket); 	// recreate masterSock
				continue;
			}
			$sockets[] = $sock; 	// добавим новое входное подключение к имеющимся соединениям
			$sockKey = array_search($sock,$sockets);	// Resource id не может быть ключём массива, поэтому используем порядковый номер. Что стрёмно.
			//var_dump($sock);
			$messages[$sockKey]['zerocnt'] = 0;
			echo "New client connected:  with key $sockKey                                       \n";
		    continue; 	//  к следующему сокету
		};
		// ===============================

		// ==============Читаем сокет источника данных, gpsd=================
		if($socket === $dataSourceSock){	// источник данных, gpsd
			//echo "Читаем из источника данных\n";
			// gpsd передаёт всё сообщение за один раз, так что нет необходимости сообщение собирать.
			// ===============================
			$buf = socketRead($sockKey);
			if($buf===false){	// с сокетом проблемы
				chkSocks($socket);
				continue;	// к следующему сокету
			};
			if(!($buf=trim($buf))) continue;	// к следующему сокету	// пустая строка может быть возвращена, но нам не нужна пустая строка. Облом же по пустым строкам - в socketRead.
			$buf = json_decode($buf,TRUE);
			if(!$buf){
				// Это сработает, если и у gpsd съедет крыша, и он начнёт присылать пустые строки.
				echo "Failed to decode JSON data from data source by: " . json_last_error_msg() . "                                 \n"; 
				$messages[$sockKey]['zerocnt']++;
				if($messages[$sockKey]['zerocnt']>3){
					echo "Data from $dataSourceHost : $dataSourcePort is confusing. Died.\n";
					//chkSocks($socket);
					exit;
				};
				continue;	// к следующему сокету
			};
			//echo "\nПринято от источника данных: "; print_r($buf); echo"\n";
			// ==============Обработка принятого от источника данных=================
			switch($buf['class']){
			// ==============Обработка приветствия=================
			case 'VERSION':
				$params = array(
					"enable"=>TRUE,
					"json"=>TRUE,
					"scaled"=>TRUE, 	// преобразование единиц в gpsd. Возможно, это поможет с углом поворота, который я не декодирую
					"split24"=>TRUE 	// объединять части длинных сообщений
				);
				$msg = '?WATCH='.json_encode($params)."\n"; 	// велим gpsd включить устройства и посылать информацию
				$messages[$sockKey]['output'] = array($msg);	// 
				break;
			case 'DEVICES':
				echo "Found DEVICES:                                                            "; print_r($buf); echo"\n";
				foreach($buf['devices'] as $device){
					if(!isset($DEVICES[$device['path']])) $DEVICES[$device['path']] = array();
					$DEVICES[$device['path']] = array_merge($DEVICES[$device['path']],$device);
				}
				break;
			case 'DEVICE':
				echo "Use DEVICE:                                                               "; print_r($buf); echo"\n";
				if(!isset($DEVICES[$buf['path']])) $DEVICES[$device['path']] = array();
				$DEVICES[$device['path']] = array_merge($DEVICES[$buf['path']],$buf);
				break;
			case 'WATCH':
				break;
			// ==============Обработка данных=================
			//echo "\nБуфер данных: "; print_r($instrumentsData); echo"\n";
			case 'TPV':	// A TPV object is a time-position-velocity report.
			case 'ATT':	// An ATT object is a vehicle-attitude report. It is returned by digital-compass and gyroscope sensors; depending on device, it may include: heading, pitch, roll, yaw, gyroscope, and magnetic-field readings. 
			case 'IMU':	// The IMU object is asynchronous to the GNSS epoch. The ATT and IMU objects have the same fields, but IMU objects are output as soon as possible.
			case 'SKY':	// A SKY object reports a sky view of the GPS satellite positions. 
			case 'GST':	// A GST object is a pseudorange noise report.
			case 'TOFF':	// This message is emitted on each cycle and reports the offset between the host’s clock time and the GPS time at top of the second (actually, when the first data for the reporting cycle is received).
			case 'PPS':	// This message is emitted each time the daemon sees a valid PPS (Pulse Per Second) strobe from a device. This message exactly mirrors the TOFF message.
			case 'OSC':	// This message reports the status of a GPS-disciplined oscillator (GPSDO).
				$instrumentsDataUpdated = array_merge_recursive($instrumentsDataUpdated,updInstrumentsData($buf));
				break;
			case 'AIS':	//
				//echo "\nДанные AIS: "; print_r($buf); echo"\n";
				$instrumentsDataUpdated = array_merge_recursive($instrumentsDataUpdated,updAISdata($buf));
				break;
			};
			// Укажем, что приняты данные класса, который не обязательно отдавать клиенту
			if(in_array($buf['class'],$noRealTime)) $isNoRealTime = true;	
			else $isNoRealTime = false;
			// Здесь сообщение от источника данных получено.
		    continue; 	//  к следующему сокету
		};
		// ===============================

		// ===============Читаем клиентский сокет================
		//echo "Читаем из сокета № $sockKey по протоколу для ",@$messages[$sockKey]['protocol']?$messages[$sockKey]['protocol']:'',"  \n";
		$buf = socketRead($sockKey);
		if($buf===false){	// с сокетом проблемы
			chkSocks($socket);
			continue;	// к следующему сокету
		};
		
		//echo "\nПРИНЯТО ОТ КЛИЕНТА # $sockKey ".mb_strlen($buf,'8bit')." байт\n";
		//print_r($messages[$sockKey]);
		if(@$messages[$sockKey]['greeting']===TRUE){ 	// с этим сокетом уже беседуем, значит -- пришли данные	
			switch($messages[$sockKey]['protocol']){
			// ===============Приём данных про протоколу websocket================
			case 'WS':	// ответ за запрос через websocket, здесь нет конца передачи, посылается сколько-то фреймов.
				//echo "\nПРИНЯТО  из вебсокета ОТ КЛИЕНТА $sockKey  ".mb_strlen($buf,'8bit')." байт\n";
				//print_r(wsDecode($buf));
				// бывают склеенные и неполные фреймы
				// там может быть: 1) неполный фрейм; 2) сколько-то полных фреймов, и, возможно, неполный
				// но нет полного сообщения; 3) завершение сообщения, плюс что-то ещё; 4) полное сообщение,
				// плюс, возможно, что-то ещё
				$n = 0;
				do{	// выделим из полученного полные фреймы
					$n++;
					if(@$messages[$sockKey]['FIN']=='partFrame') {
						//echo "предыдущий фрейм был неполный, к имеющимся ".mb_strlen($messages[$sockKey]['partFrame'],'8bit')." байт добавлено полученные ".mb_strlen($buf,'8bit')." байт, получилось ".(mb_strlen($messages[$sockKey]['partFrame'],'8bit')+mb_strlen($buf,'8bit'))." байт $n \n";
						$buf = $messages[$sockKey]['partFrame'].$buf;	
					}
					// здесь декодируется либо только что принятое, либо tail от предыдущего принятого, где содержится следующее сообщение
					$res = wsDecode($buf);	// собственно декодирование: вытаскивание из потока байт фреймов
					$saveBuf = $buf;
					$buf = null;
					if($res == FALSE){
						echo "Bufer decode fails, will close websocket\n";
						chkSocks($socket);	// закроет сокет
						continue 3;	// к следующему сокету						
					}
					else list($decodedData,$type,$FIN,$tail) = $res;	// $tail - это не декодированная часть принятого, например, следующее сообщение.

					$messages[$sockKey]['FIN'] = $FIN;
					
					// ping -- это фрейм, а не сообщение, как сказано в rfc6455, 
					// однако этот фрейм имеет первый бит ws-frame равный 1, т.е., это последний фрейм сообщения.
					// Таким образом, ping -- это сообщение из одного фрейма, которое может придти посередине другого сообщения?
					
					switch($FIN){
					case 'messageComplete':	// СООБЩЕНИЕ ПРИНЯТО: в буфере последний фрейм сообщения -- полностью, он имеет тип $messages[$sockKey]['frameType'] и декодирован в $decodedData. Возможно, есть ещё полные или неполные фреймы, они находятся в $tail и не декодированы
						$buf = $tail;	// возможно, там ещё есть следующее сообщение, оно должно быть декодировано на следующем обороте do

						//echo "Сообщение принято $n \n";
						if($type) {
							//echo "\tв одном фрейме\n";
							$realType = $type;
						}
						else {
							//echo "\tв нескольких фреймах\n";
							$realType = $messages[$sockKey]['frameType'];
						};
						if($tail) {	// есть уже следующее сообщение
							//echo "\t\tоднако, в буфере имеется ".mb_strlen($tail,'8bit')." байт другого сообщения\n";
						};

						switch($realType){	// 
						case 'text':	// требуемое
							$messages[$sockKey]['inBuf'] .= $decodedData;	// 
							//echo "Принято текстовое сообщение длиной ".mb_strlen($messages[$sockKey]['inBuf'],'8bit')." байт\n";
							//echo "decoded data={$messages[$sockKey]['inBuf']};\n";
							if(rtrim($messages[$sockKey]['inBuf'])){	// пустые строки, пришедшие отдельным сообщением не записываем. А это правильно?
								$messages[$sockKey]['inBufS'][] = $messages[$sockKey]['inBuf'];	// всегда для websockets будем складывать сообщения в массив, потому что сокет за одно чтение может принять сколько-то.
							};
							$messages[$sockKey]['inBuf'] = '';
							$messages[$sockKey]['partFrame'] = '';
							$messages[$sockKey]['frameType'] = null;
							break;
						case 'close':
							//echo "От клиента принято требование закрыть соединение.    \n"; //var_dump($socket);
							chkSocks($socket);	// закроет сокет
							$lastClientExchange = time();
							continue 5;	// к следующему сокету
						case 'ping':	// An endpoint MAY send a Ping frame any time after the connection is    established and before the connection is closed.
							//file_put_contents('ping.frame',$saveBuf);							
						case 'pong':
						case 'binary':
						default:
							echo "A frame of type '$type' was dropped $n                              \n";
							if($decodedData === null){
								echo "Frame decode fails, will close websocket\n";
								chkSocks($socket);	// закроет сокет
								continue 5;	// к следующему сокету
							};
						};
						//echo "type={$messages[$sockKey]['frameType']}; FIN=$FIN;n=$n; tail:|$tail|\n";
						break;
					case 'partFrame':	// в буфере -- неполный фрейм, он не декодирован ($decodedData==null) и возвращён в $tail
						//echo "Принят неполный фрейм типа $type, размером ".mb_strlen($tail,'8bit')." байт $n\n";
						if($type) {	// это первый фрейм. 
							$messages[$sockKey]['frameType'] = $type;
							//echo "это первый фрейм $n\n";
						}
						if($messages[$sockKey]['frameType']) 	{
							$messages[$sockKey]['partFrame'] = $tail;	// я присоединяю перед декодированием
							continue 4;	// к следующему сокету
						}
						else {	// всё кривое, скажем, после приёма нормального фрейма. Однако, принятое надо обработать.
							//echo "однако тип его неизвестен. Игнорируем остаток данных.          \n";
							$messages[$sockKey]['inBuf'] = '';
							$messages[$sockKey]['partFrame'] = '';
						}
						break;
					default:	// непоследний фрейм сообщения полностью, и, возможно, что-то ещё
						if($type) {	// это первый фрейм. 
							$messages[$sockKey]['frameType'] = $type;
							//echo "Получен первый фрейм $n\n";
						};
						//echo "Собираем сообщение типа {$messages[$sockKey]['frameType']}, декодировано ".mb_strlen($decodedData,'8bit')." байт $n\n";
						$messages[$sockKey]['inBuf'] .= $decodedData;	// собираем сообщение
						$buf = $tail;	// для декодирования на следующем обороте ближайшего do
					};
				}while($buf);	// выбрали полные фреймы, в $tail -- неполный
				if(!$tail) $messages[$sockKey]['inBuf'] = '';
				//echo "\nПринято от websocket'а:"; print_r($messages[$sockKey]['inBufS']);
				if(!@$messages[$sockKey]['inBufS']) continue 2;	// к следующему сокету
				break;	// case protocol WS
			// ===============================
			// ================Приём данных по другому протоколу===============
			case 'TCP':
				if(rtrim($buf)){	// пустые строки, пришедшие отдельным сообщением не записываем
					$messages[$sockKey]['inBufS'][] = $buf;	// всегда будем складывать сообщения в массив.
				};
				//echo "\nПринято от TCP socket'а: $buf\n"; print_r($messages[$sockKey]); echo "\n";
				break;
			// ===============================
			}; // end switch protocol
		}
		else{ 	// с этим сокетом ещё не беседовали, значит, пришёл заголовок или команда gpsd или ничего, если сокет просто открыли
			// разберёмся с заголовком
			if(!isset($messages[$sockKey]['inBuf'])) $messages[$sockKey]['inBuf'] = '';
			$messages[$sockKey]['inBuf'] .= "$buf";	// собираем заголовок
			//echo "Собрано:|{$messages[$sockKey]['inBuf']}|";
			if(substr($messages[$sockKey]['inBuf'],-2)=="\n\n"){	// конец заголовков (и вообще сообщения) -- пустая строка
				//echo "Заголовок: |{$messages[$sockKey]['inBuf']}|\n";
				$inBuf = explode("\n",$messages[$sockKey]['inBuf']);
				foreach($inBuf as $msg){	// поищем в заголовке Надо просмотреть все строки, потому что они могут быть в любом порядке
					$msg = explode(':',$msg,2);
					array_walk($msg,function(&$str,$key){$str=trim($str);});
					if($msg[0]=='Sec-WebSocket-Key'){
						$SecWebSocketAccept = base64_encode(pack('H*',sha1($msg[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));	// https://datatracker.ietf.org/doc/html/rfc6455#section-1.2 https://habr.com/ru/post/209864/
					}
					elseif($msg[0]=='Upgrade' and $msg[1]=='websocket') {
						$messages[$sockKey]['protocol'] = 'WS handshake';	// это запрос на общение по websocket
					};
				};
				unset($inBuf);
				// определился протокол
				switch(@$messages[$sockKey]['protocol']){	// немного через задницу, но указать, что протокол будет абстрактный, можно только после того, когда не оказалось конкретного
				case 'WS handshake':	// ответ за запрос через websocket, в минимальной форме, иначе Chrom не понимает
					$SecWebSocketAccept = 
						"HTTP/1.1 101 Switching Protocols\r\n"
						."Upgrade: websocket\r\n"
						."Connection: Upgrade\r\n"
						."Sec-WebSocket-Accept: ".$SecWebSocketAccept."\r\n"
						."\r\n";			
					//echo "SecWebSocketAccept=$SecWebSocketAccept;\n";
					//echo "header sockKey=$sockKey;\n";
					$messages[$sockKey]['output'][] = $SecWebSocketAccept;	// 
					$messages[$sockKey]['inBufS'] = array();	// для websocket будет ещё и буфер сообщений
					// ====здесь конец создания соединения протокола websocket(text)===============
					// ====здесь полезная нагрузка перед первой коммуникацией свежесозданного соединения websocket================
					$messages[$sockKey]['subscribe'] = $defaultSubscribe;
					chkSubscribe();
					$messages[$sockKey]['dontUseDevices'] = $dontUseDevices;
					// Прикинемся, что $instrumentsDataUpdated. Тогда новый клиент получит
					// имеющиеся данные, а не будет ждать новых. Если уже - он и так получит.
					// Правда, все остальные получат то, что у них уже есть.
					if(!$instrumentsDataUpdated) $instrumentsDataUpdated = array_fill_keys($defaultSubscribe,array(''));	
					$messages[$sockKey]['output'][] = $greeting;	// Пошлём приветствие, чтобы заточенные на GPSDproxy клиенты начали рукопожатие
					// ===============================
					break;
				default:	// ответ вообще в сокет, как это для протокола gpsd
					$messages[$sockKey]['protocol'] = 'TCP';	// это просто кто-то обратился
					$messages[$sockKey]['subscribe'] = $defaultSubscribe;
					chkSubscribe();
					$messages[$sockKey]['dontUseDevices'] = $dontUseDevices;
					if(rtrim($messages[$sockKey]['inBuf'])){	// пустые строки, пришедшие отдельным сообщением не записываем
						$messages[$sockKey]['inBufS'][] = $messages[$sockKey]['inBuf'];	// всегда будем складывать сообщения в массив, для единообразия с websocket.
					};
					//$messages[$sockKey]['output'][] = $greeting."\r\n\r\n";	// приветствие gpsd
					break;	//
				};
				//echo "sockKey=$sockKey;\n";
				$messages[$sockKey]['greeting']=TRUE;
				$messages[$sockKey]['inBuf'] = '';					
			}
			else continue;	// к следующему сокету, если ещё не прочитано /n/n, т.е. читается заголовок
		};
		// ===============================
		// Здесь сообщение из этого сокета получено, и это не заголовок
		$buf = @$messages[$sockKey]['inBufS'] ? $messages[$sockKey]['inBufS'] : array();
		unset($messages[$sockKey]['inBufS']);	// должно помочь с памятью?
		//echo "Здесь сообщение из этого сокета получено: "; print_r($buf);echo "\n";
		//file_put_contents('input.text',implode($buf));
		foreach($buf as $message){
			$message = trim($message);
			if(!$message) continue;
			$message = explode(';',$message);	// вдруг кто-то послал две команды в одном сообщении?
			foreach($message as $command){
				if(!$command) continue;	// если там действительно есть ;, то последний элемент массива будет пустая строка
				if($command[0]!='?') continue; 	// это не команда протокола gpsd
				$command = rtrim(substr($command,1),';');	// ? ;
				@list($command,$params) = explode('=',$command);
				$params = trim($params);
				//echo "\nRecieved command from Client #$sockKey: command=$command; params=$params;\n";
				$params = json_decode($params,TRUE);
				if(!$params) $params = array();
				//echo "\nparams:"; print_r($params); echo "\n";
				// Обработаем команду
				switch($command){
				case 'POLL':
					chkFreshOfData();	// проверим на свежесть
					$messages[$sockKey]['POLL'] = false;	// т.е., подготовить данные
					$d = $messages[$sockKey]['disable'];
					$messages[$sockKey]['disable'] = false;
					chkSubscribe();	// добавим подписку от этого клиента
					updUserParms($sockKey,$params);
					writePrepareToSocket($sockKey);
					$messages[$sockKey]['POLL'] = true;		// т.е., данные для POLL уже подготовлены
					$messages[$sockKey]['disable'] = $d;	// с POLL disable не применяется
					chkSubscribe();	// уберём подписку от этого клиента
					//echo "\nRecieved command POLL from Client #$sockKey "; print_r($messages[$sockKey]); echo "\n";
					break;
				case 'WATCH':
					$messages[$sockKey]['POLL'] = false;
					updUserParms($sockKey,$params);
					chkSubscribe();	// обновим подписку от этого клиента
					//echo "\nRecieved command WATCH from Client #$sockKey "; print_r($messages[$sockKey]); echo "\n";
					break;
				case 'DEVICES':
					$output = array("class"=>"DEVICES","devices"=>array());
					foreach($DEVICES as $path=>$device){
						$output['devices'][] = $device;
					};
					$messages[$sockKey]['output'][] = json_encode($output,JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
					break;
				};
			};
		};
		// ===============================
		$lastClientExchange = time();
	};	// конец перебора читаемых сокетов
	// ===============================
	
	// ===============================
	// Здесь Все сообщения получены
	//echo "Здесь Все сообщения получены \n";
	// Проверим данные на свежесть, каждый оборот.
	$instrumentsDataUpdated = array_merge_recursive($instrumentsDataUpdated,chkFreshOfData());
	// Подготовим данные к отправке клиентам
	//echo "\nИзменено: "; print_r($instrumentsDataUpdated); echo"\n";
	if($instrumentsDataUpdated) {
		array_walk($instrumentsDataUpdated,function ($sources, $class){	// потому что array_merge_recursive вернёт неуникальные списки источников
			global $instrumentsDataUpdated;
			$instrumentsDataUpdated[$class] = array_unique($sources);
		});
		writePrepare($instrumentsDataUpdated);	// подготовим данные для отправки каждому клиенту
	};
	// ===============================
	
	// =============== клиентов нет ================
	if($dataSourceSock){	//соединение с gpsd есть
		if($noClientTimeout and (count($sockets)<2) and ((time()-$lastClientExchange)>=$noClientTimeout)) {	// клиентов нет, указан таймаут без клиентов и он истёк
			socketClose($dataSourceSock);	// закроем соединение с gpsd
			$dataSourceSock = false;	// поскольку в говёном PHP8 нельзя узнать, что сокет закрыт, делаем его не сокет
			echo "Data source connection closed by no clients\n";
		};
	}
	else {	//соединения с gpsd нет
		if(count($sockets)>0){	// однако клиенты есть
			chkSocks();	// запустим соединение с источником данных
		};
	};
	// ===============================

	//echo "Конец главного цикла ==============\n";
} while (true);

?>
