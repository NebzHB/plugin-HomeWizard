/* jshint esversion: 8,node: true,-W041: false */
"use strict";
const HW = require('homewizard-energy-api');
const LogType = require('./utils/logger.js').logType;
const Logger = require('./utils/logger.js').getInstance();
const express = require('express');


Logger.setLogLevel(LogType.DEBUG);
var conf={};
var hasOwnProperty = Object.prototype.hasOwnProperty;

// Logger.log('env : '+process.env.NODE_ENV,LogType.DEBUG);

// Args handling
process.argv.forEach(function(val, index) {
	switch (index){
		case 2: conf.urlJeedom = val; break;
		case 3: conf.apiKey = val; break;
		case 4: conf.serverPort = val; break;
		case 5:
			conf.logLevel = val;
			if (conf.logLevel == 'debug') {Logger.setLogLevel(LogType.DEBUG);}
			else if (conf.logLevel == 'info') {Logger.setLogLevel(LogType.INFO);}
			else if (conf.logLevel == 'warning') {Logger.setLogLevel(LogType.WARNING);}
			else {Logger.setLogLevel(LogType.ERROR);}
		break;
		case 6:
			conf.jeedom42=val;
		break;
	}
});

const jsend = require('./utils/jeedom.js')('hkControl',conf.urlJeedom,conf.apiKey,conf.jeedom42,conf.logLevel);

// display starting
Logger.log("Démarrage démon HomeWizard...", LogType.INFO);
for(var name in conf) {
	if (hasOwnProperty.call(conf,name)) {
		Logger.log(name+' = '+((typeof conf[name] == 'object')?JSON.stringify(conf[name]):conf[name]), LogType.DEBUG);
	}
}

const conn = {};
// prepare callback for discovery
const discovery = new HW.HomeWizardEnergyDiscovery();







/* Routing */
const app = express();
var server = null;
var myCommands = {};

myCommands.reDiscover = function(req,res) {
	if(res) {res.type('json');}

	Logger.log("Reçu une demande de reDécouverte"+((req && typeof req == 'string')?" pour "+conf.pairings[req].name+'('+req+')':'')+'...',LogType.Debug); 
	discovery.stop();
	discovery.start();

	if(res) {res.json({'result':'ok'});}
};




myCommands.test = function(req,res) {
	res.type('json');

	Logger.log("Reçu une demande de test...",LogType.Debug); 

	try {
		var idTest='tutu';
		conf.pairings[idTest]={
			'testing':true,
			'hasTest':'bla',
		};
		delete conf.pairings[idTest];
	} catch (e) {
		res.json({'result':'ko','error':e});
	}

	res.json({'result':'ok'});
};

/** Stop the server **/
myCommands.stop = function(req, res) {
	Logger.log("Recu de jeedom: Demande d'arret",LogType.INFO);
	discovery.stop();
	for(const c in conn) {
		console.log(conn[c].isPolling.getData);
		if(conn[c].isPolling.getData) {conn[c].polling.getData.stop();}
	}
	res.json({'result':'ok'});
	server.close(() => {
		Logger.log("Exit",LogType.INFO);
		process.exit(0);
	});
};

const pollingIntervals={
	"HWE-P1":950,
};

// prepare commands
app.get('/reDiscover', myCommands.reDiscover);
app.get('/test', myCommands.test);
app.get('/stop', myCommands.stop);
app.use(function(err, req, res, _next) {
	res.type('json');
	Logger.log(err,LogType.ERROR);
	res.json({'result':'ko','msg':err});
});

/** Listen **/
server = app.listen(conf.serverPort || 4563, () => {
	Logger.log("Démon prêt et à l'écoute sur "+conf.serverPort+" !",LogType.INFO);
	discovery.start();
	
	discovery.on('response', async (mdns) => {
		Logger.log("Découverte de : "+JSON.stringify(mdns),LogType.DEBUG);
		if(mdns.txt.api_enabled == 0) {Logger.log("API pas activée dans l'application, veuillez activer l'api dans l'application...",LogType.INFO);return;}

		let index=mdns.txt.product_type+'_'+mdns.txt.serial;
		switch(mdns.txt.product_type) {
			case "HWE-P1":
				conn[index]= new HW.P1MeterApi('http://'+mdns.ip, {
					polling: {
						interval: pollingIntervals['HWE-P1'],
						stopOnError: false,
					},
				});
				console.log(await conn[index].identify());
				conn[index].polling.getData.start();
				conn[index].polling.getData.on('response', response => {
					console.log(index,response);
				});
				conn[index].polling.getData.on('error', error => {
					Logger.log(error,LogType.ERROR);
				});
			break;
		}

		/*{
		  ip: '192.168.1.146',
		  hostname: 'p1meter-0ACDC0.local',
		  fqdn: 'p1meter-0ACDC0._hwenergy._tcp.local',
		  txt: {
			api_enabled: '1',
			path: '/api/v1',
			serial: '5c2faf0acdc0',
			product_type: 'HWE-P1',
			product_name: 'P1 meter'
		  }
		}*/

	});
	discovery.on('error', error => {
	  Logger.log(error,LogType.ERROR);
	});
	discovery.on('warning', error => {
	  Logger.log(error,LogType.WARNING);
	});
});










/** 
	UTILS
**/

Promise.delay = function(t, val) {
    return new Promise((resolve) => {
        setTimeout(resolve.bind(null, val), t);
    });
};
Promise.raceAll = function(promises, timeoutTime, timeoutVal) {
    return Promise.all(promises.map((p) => {
        return Promise.race([p, Promise.delay(timeoutTime, timeoutVal)]);
    }));
};

function toBool(val) {
	if (val == 'false' || val == '0') {
		return false;
	} else {
		return Boolean(val);
	}
}


async function eventReceived(ev) {
	var valueToSend,charact,fetchedChar;
	for(var thisEvent of ev.characteristics) {
		if(BigNumber.isBigNumber(thisEvent.aid)) {thisEvent.aid=thisEvent.aid.toString();}
		if(BigNumber.isBigNumber(thisEvent.iid)) {thisEvent.iid=thisEvent.iid.toString();}
		charact=getChar(conf.pairings[this.deviceId].accessories,thisEvent.aid,thisEvent.iid);
		
		if(charact.format == 'bool') {
			fetchedChar = await conf.pairings[this.deviceId].client.getCharacteristics([thisEvent.aid+'.'+thisEvent.iid]);
			if(fetchedChar.characteristics[0].value == thisEvent.value) {
				valueToSend=thisEvent.value;
			} else {
				console.log("****ev différent de fetchedChar !!! correction de la valeur : ev=",thisEvent,"fetchedChar=",fetchedChar.characteristics[0]);
				valueToSend=fetchedChar.characteristics[0].value;
			}
		} else {
			valueToSend=thisEvent.value;
		}
		
		/* if(charact.format == 'tlv8' || charact.format == 'data') {
			try {
				//valueToSend=TLV.decodeBuffer(Buffer.from(thisEvent.value, 'base64'));
				//valueToSend=JSON.stringify(valueToSend);
				if(thisEvent.value.slice(-1) != '=') {
					//console.log("Looks like a TLV Buffer, decoding...",valueToSend);
					//valueToSend=TLV.decodeBuffer(thisEvent.value);
					//console.log("Result :",valueToSend);
					//valueToSend=thisEvent.value.toString('utf8');
				} else {
					//valueToSend=Buffer.from(thisEvent.value,'base64').toString('hex');
					
				}
			} catch(e) {
				console.error("ERROR",e);
				valueToSend=valueToSend.toString('utf8');
			}
		} */
		
		Logger.log("Event reçu de "+conf.pairings[this.deviceId].name+' : '+charact.typeLabel+'='+valueToSend,LogType.INFO);
		jsend({eventType: 'updateValue', id: this.deviceId, aidiid: thisEvent.aid+'.'+thisEvent.iid, value: valueToSend});
	}
}



// Speed up calls to hasOwnProperty
function isEmpty(obj) {
    // null and undefined are 'empty'
    if (obj == null) {return true;}

    // Assume if it has a length property with a non-zero value
    // that that property is correct.
    if (obj.length > 0) {return false;}
    if (obj.length === 0) {return true;}

    // If it isn't an object at this point
    // it is empty, but it can't be anything *but* empty
    // Is it empty?  Depends on your application.
    if (typeof obj !== 'object') {return true;}

    // Otherwise, does it have any properties of its own?
    // Note that this doesn't handle
    // toString and valueOf enumeration bugs in IE < 9
    for (var key in obj) {
        if (hasOwnProperty.call(obj, key)) {return false;}
    }

    return true;
}


/**
 * Restarts the workers.
 */
process.on('SIGHUP', function() {
	Logger.log("Recu SIGHUP",LogType.DEBUG);
});
/**
 * Gracefully Shuts down the workers.
 */
process.on('SIGTERM', function() {
	Logger.log("Recu SIGTERM",LogType.DEBUG);
});

