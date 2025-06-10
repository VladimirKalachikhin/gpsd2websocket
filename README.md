[Русское описание](README.ru-RU.md)  
# gpsd2websocket  [![License: CC BY-NC-SA 4.0](Cc-by-nc-sa_icon.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/deed.en)
**version 0**

Access to **[gpsd](https://gpsd.io/)** data via the websocket protocol from webapps.  
The main goal is to simplify the creation of web applications using Global Navigation Satellite System receivers.  
It is a lightweight proxy server, written in pure PHP without using third-party libraries. The server runs on any platform that has a basic PHP implementation, and is very resource-free.

## Features
* The **gpsd2websocket** communicates with a separately running **gpsd** in the usual way, so you can run **gpsd** in the way you want in the configuration you require.
* The user application can connect to the **gpsd2websocket** using either the websocket or tcp socket protocol.
* The user application can only retrieve data from the required **gpsd** data classes.
* The user application may not receive the specified class data from certain **gpsd** devices.
* The user application is not required to follow any communication protocol and can be dumb - just connect.
* However, a user application can specify connection parameters using commands similar to the **gpsd** protocol commands.
* Any number of **gpsd2websocket** instances can be running simultaneously, each with its own configuration.


## Demo
located in [demo/](demo/) directory

On the same computer:
* Run the `gpsd` with a real GNSS receiver or with a [simulator](https://github.com/panaaj/nmeasimulator). 
* Run the `php gpsd2websocket.php`
* Open `demo/index.html` in the browser.


## Install
Just copy files `gpsd2websocket.php` and `fCommon.php` to any dir. Optionally, copy and modify `params.php`.


## Usage
### Run
`php gpsd2websocket.php [--params=params.php] [any parameters]`  
When running without parameters, all parameters are taken from the included `params.php` file, if present.  
You can specify your own configuration file. Explicitly specified in command line parameters take precedence over parameters from a file.  
Command line parameters:  
--params=params.php  The parameters file name. Default params.php  
--gpsdProxyHost=host  To the **gpsd2websocket** connection host. The square brackets [] on ipv6 address is required. Default [::]  
--gpsdProxyPort=port  To the **gpsd2websocket** connection port. Default 3839.  
--dataSourceHost=host  The instruments data source, **gpsd** host. Default localhost.  
--dataSourcePort=port The instruments data source, **gpsd** port. Default 2947.  
--gpsdProxyTimeouts="{json string}"  Timeouts for **gpsd** data types, after which the data are considered obsolete. See `params.php` for defaults.  
--dontUseDevices="{json string}"  **gpsd** devices black list. In order not to use this data from this device. For example: {"TPV":["tcp://192.168.10.10:3800"],"AIS":[gpsd://192.168.10.10:2947#tcp://localhost:2222]}. Default empty.  
--defaultSubscribe="TPV,ATT"  Default returned **gpsd** data classes. All list: TPV, ATT, SKY, TOFF, PPS, OSC, AIS. Default "TPV,ATT".  
--boatInfo="{json string}"  Vehacle description. Some vessel characteristics to correct some data parameters. Default empty.  
--noClientTimeout=30  sec., disconnect from **gpsd** on no any client present. 0 to disable. Default 30sec.  
--noRealTime="AIS,SKY"  A list of **gpsd** classes that do not have to be given to clients immediately. Default "AIS,SKY".  
-h --help  short help

### Connect
The client application should access the **gpsd2websocket** in the [usual way](https://developer.mozilla.org/en-US/docs/Web/API/WebSockets_API):  
```
let webSocket = new WebSocket("ws://"+gpsdProxyHost+":"+gpsdProxyPort);

webSocket.onopen = ...

webSocket.onmessage = ...

webSocket.onclose = ...

webSocket.onerror = ...
```
, see [demo/index.html](demo/index.html) for example.  
Immediately after the connection is established, the client program will start receiving data according to the **gpsd2websocket** configuration.  
When connecting via a tcp socket, you should send a string `\n\n` to start receiving data.

### Data format
The **gpsd2websocket** sends the data in JSON format as described in the [gpsd documentation](https://gpsd.io/gpsd_json.html) for {"scaled";true,"split24":true} mode, except:

* All data (include AIS) in SI units:
>* Speed in m/sec
>* Location in degrees
>* Angles in degrees
>* Draught in meters
>* Length in meters
>* Beam in meters
* undefined values (include AIS) is __null__
* No 'second' field, but has 'timestamp' as unix time
* No 'depth' value in the TPV class data, this value is only present in class ATT data
* No 'temp' value in the TPV class data, this value is only present in class ATT data
* The AIS class contain only:
```
{"class":"AIS",
"ais":{
	"vessel_mmsi":{
		...
		vessel data
		...
```
The data format is the same as in [gpsdPROXY](https://github.com/VladimirKalachikhin/gpsdPROXY).

### Data control
The client program can send commands to the **gpsd2websocket** similar to [gpsd commands](https://gpsd.io/gpsd_json.html#_core_protocol_commands):  
**?DEVICES;** Returns a device list object  
**?WATCH={};** This tells the **gpsd2websocket** to stop/start sending data or change the data conditions. The parameters can be as follows:  

> "subscribe" - specify which data classes should be received. List of classes: TPV, ATT, SKY, TOFF, PPS, OSC, AIS.  
> For example: ?WATCH={"subscribe":"TPV,AIS"};  

> "dontUseDevices" - specify which data classes from which devices should NOT be received.  
> For example: ?WATCH={"dontUseDevices":{"ATT":["gpsd://192.168.10.10:2947#tcp://localhost:2222"],"AIS":["tcp://192.168.10.10:3800"]}}  

> "enable" - true/false, start/stop sending data  

**?POLL;**, **?POLL={};** This stop (default) **WATCH** mode and requests the relevant data once. The parameters can be "subscribe" and "dontUseDevices". Unlike **gpsd**, you can ?POLL={"subscribe":"AIS"};

Any other commands and parameters are ignored. Commands can be sent at any time and in any order.


## Support
[Forum](https://github.com/VladimirKalachikhin/Galadriel-map/discussions)

The forum will be more lively if you make a donation at [ЮMoney](https://sobe.ru/na/galadrielmap)

[Paid personal consulting](https://kwork.ru/it-support/20093939/galadrielmap-installation-configuration-and-usage-consulting)  
