<?php
// Подключение к gpsd2websocket, хост,порт
// по возможности не указывайте '0.0.0.0' и '[::]'-- это не очень безопасно.
// Квадратные скобки [] в адресе ipv6 обязательны.
// The gpsd2websocket connection host,port.
// Pls, avoid set '0.0.0.0' и '[::]' for security reasons.
// The square brackets [] on ipv6 address is required.
//$gpsdProxyHost = '0.0.0.0';
$gpsdProxyHost = '[::]';
$gpsdProxyPort = 3839;

// Источник данных. Data source.
// адрес и порт источника координат и остальных данных, по умолчанию -- gpsd
// host and port of instruments data source, gpsd by default
$dataSourceHost = 'localhost';	// default
$dataSourcePort = 2947;	// default gpsd
//$dataSourceHost = '192.168.10.105';	// SignalK
//$dataSourcePort = 3000;	// SignalK

// перечень типов данных каждого источника в gpsd, для которых требуется контролтровать время жизни
// gpsd data types and their lifetime, sec
$gpsdProxyTimeouts = array(  	// время в секундах после последнего обновления, после которого считается, что данные протухли. Поскольку мы спрашиваем gpsd POLL, легко не увидеть редко передаваемые данные
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
// Глобальный список устройств, данные указанного класса от которых следует игнорировать.
// array('class'=>array('device, ...device'))
$dontUseDevices = array();	// Global devices black list. Ignore the class data from this devices from the gpsd output.
// Умолчальный ответ: координаты, направление, курс и глубина.
$defaultSubscribe = array('TPV','ATT');	// Default returned data types. All list: 'TPV','ATT','SKY','TOFF','PPS','OSC','AIS'

// Характеристики судна
// Vehacle description
/*
$boatInfo = array(
'to_echosounder'=>0,		// поправка к получаемой от прибора глубине до желаемой: от поверхности или от киля. Correction to the depth received from the device to the desired depth: from the surface or from the keel.
'magdev'=>0		// девиация компаса, градусы. Magnetic deviation of the compass, degrees
);
*/


// Отключение от gpsd
// Freeing gpsd
// Время, сек., через которое происходит отключение от gpsd при отсутствии клиентов. gpsd отключит датчики.
// Полезно для экономии ресурсов.
// 0 для предотвращения отключения приёмника ГПС.
$noClientTimeout = 30;	// sec., disconnect from gpsd on no any client present. 0 to disable.

// Список классов gpsd, которые не обязательно отдавать клиентам немедленно по получению данных. Их можно отдать после окончания приёма.
$noRealTime = array('AIS','SKY');	// A list of gpsd classes that do not have to be given to clients immediately upon receiving data. They can be returned after the end of the reception.
?>
