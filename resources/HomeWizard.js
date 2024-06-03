/* jshint esversion: 8,node: true,-W041: false */
"use strict";
const HW = require('homewizard-energy-api');
const LogType = require('./utils/logger.js').logType;
const Logger = require('./utils/logger.js').getInstance();
const express = require('express');


Logger.setLogLevel(LogType.DEBUG);
const conf={};
const hasOwnProperty = Object.prototype.hasOwnProperty;
const pollingIntervals={
	"HWE-P1":5000,
	"HWE-SKT":5000,
	"HWE-WTR":5000,
	"SDM230-wifi":5000,
	"SDM630-wifi":5000,
};


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
	}
});


const jsend = require('./utils/jeedom.js')('HomeWizard',conf.urlJeedom,conf.apiKey,conf.logLevel,'jsonrpc');


// display starting
Logger.log("Démarrage démon HomeWizard...", LogType.INFO);
for(const name in conf) {
	if (hasOwnProperty.call(conf,name)) {
		Logger.log(name+' = '+((typeof conf[name] == 'object')?JSON.stringify(conf[name]):conf[name]), LogType.DEBUG);
	}
}


const conn = {};
const intervals = {};
// prepare callback for discovery
const discovery = new HW.HomeWizardEnergyDiscovery();


/* Routing */
const app = express();
const myCommands = {};

/** Stop the server **/
myCommands.stop = function(req, res) {
	Logger.log("Recu de jeedom: Demande d'arret",LogType.INFO);
	discovery.stop();
	for(const c in conn) {
		if (hasOwnProperty.call(conn,c)) {
			if(conn[c].isPolling.getData) {conn[c].polling.getData.stop();}
		}
	}
	if(res) {res.json({'result':'ok'});}
	server.close(() => {
		Logger.log("Exit",LogType.INFO);
		process.exit(0);
	});
};

myCommands.cmd = function(req, res) {
	res.type('json');

	Logger.log("Reçu une commande..."+JSON.stringify(req.query),LogType.Debug); 
	if ('id' in req.query === false) {
		const error="Pour faire une commande, le démon a besoin de l'id";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	if ('cmd' in req.query === false) {
		const error="Pour faire une commande, le démon a besoin du nom de la commande";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	let result;
	try {
		if(req.query.cmd == 'power_on') {
			result=conn[req.query.id].updateState({power_on: true});
		} else if(req.query.cmd == 'power_off') {
			result=conn[req.query.id].updateState({power_on: false});
		} else if(req.query.cmd == 'lock') {
			result=conn[req.query.id].updateState({switch_lock: true});
		} else if(req.query.cmd == 'unlock') {
			result=conn[req.query.id].updateState({switch_lock: false});
		} else if(req.query.cmd == 'brightness') {
			result=conn[req.query.id].updateState({brightness: parseInt(req.query.val)});
		} else {
			const error="Commande "+req.query.cmd+" inconnue !";
			Logger.log(error,LogType.ERROR); 
			res.json({'result':'ko','msg':error});
			return;
		}
		
	} catch (e) {
		res.json({'result':'ko','error':e});
		console.error("CMD KO : ",e);
	}

	Logger.log("CMD OK : "+JSON.stringify(result, null, 4),LogType.Debug); 
	res.json({'result':'ok'});
};


// prepare commands
app.get('/stop', myCommands.stop);
app.get('/cmd',	 myCommands.cmd);
app.use(function(err, req, res, _next) {
	res.type('json');
	Logger.log(err,LogType.ERROR);
	res.json({'result':'ko','msg':err});
});


function startStateInterval(index) {
    intervals[index] = setInterval(async () => {
		try {
			const state = await conn[index].getState();
			eventReceived(index,state);
		} catch(e) {
			if(e.toString().includes("HeadersTimeoutError") || e.toString().includes("ConnectTimeoutError")) {
				Logger.log(index+' (getState) : Injoignable ('+e+')',LogType.ERROR);
			} else {
				Logger.log(index+' (getState) : '+e,LogType.ERROR);
			}
			clearInterval(intervals[index]);
		}
    }, 1000);
}


/** Listen **/
const server = app.listen(conf.serverPort, () => {
	Logger.log("Démon prêt et à l'écoute sur "+conf.serverPort+" !",LogType.INFO);
	discovery.start();
	
	discovery.on('response', async (mdns) => {
		const type=mdns.txt.product_type;
		Logger.log("Découverte de : "+JSON.stringify(mdns, null, 4),LogType.DEBUG);
		if(mdns.txt.api_enabled == 0) {Logger.log("API Locale pas activée dans l'application, Icône Engrenage > Mesures > Dispositif > API Locale...",LogType.INFO);return;}

		const index=type+'_'+mdns.txt.serial;
		jsend({eventType: 'createEq', id: index, mdns: mdns});
		const param={
			polling: {
				interval: pollingIntervals[type],
				stopOnError: false,
			}/*,
			logger: {
				method: console.log
			}*/
		};
		switch(type) {
			case "HWE-P1": // P1 Meter
				conn[index]= new HW.P1MeterApi('http://'+mdns.ip, param);
			break;
			case "HWE-SKT": // Energy Socket
				conn[index]= new HW.EnergySocketApi('http://'+mdns.ip, param);
				startStateInterval(index);
			break;
			case "HWE-WTR": // Watermeter (only on USB)
				conn[index]= new HW.WaterMeterApi('http://'+mdns.ip, param);
			break;
			case "SDM230-wifi": // kWh meter (1 phase)
				conn[index]= new HW.P1MeterApi('http://'+mdns.ip, param);
			break;
			case "SDM630-wifi": // kWh meter (3 phases)
				conn[index]= new HW.P1MeterApi('http://'+mdns.ip, param);
			break;
		}
		conn[index].mdns=mdns;
		conn[index].polling.getData.start();
		conn[index].polling.getData.on('response', (response) => {
			eventReceived(index,response);
		});
		conn[index].polling.getData.on('error', (error) => {
			if(error.toString().includes("HeadersTimeoutError") || error.toString().includes("ConnectTimeoutError")) {
				Logger.log(index+' (getData) : Injoignable ('+error+')',LogType.ERROR);
			} else {
				Logger.log(index+' (getData) : '+error,LogType.ERROR);
			}
			conn[index].polling.getData.stop();
			discovery.removeCachedResponseByFqdn(conn[index].mdns.fqdn);
			//jsend({eventType: 'doPing', id: index}); //not working... getting ECONNABORTED
		});

		/* {
		  ip: '192.168.1.100',
		  hostname: 'p1meter-ABABAB.local',
		  fqdn: 'p1meter-ABABAB._hwenergy._tcp.local',
		  txt: {
			api_enabled: '1',
			path: '/api/v1',
			serial: 'abcdserial',
			product_type: 'HWE-P1',
			product_name: 'P1 meter'
		  }
		} */

	});
	discovery.on('error', (error) => {
		Logger.log("Discovery : "+error,LogType.ERROR);
		discovery.start();
	});
	discovery.on('warning', (error) => {
		Logger.log("Discovery : "+error,LogType.WARNING);
	});
});


async function eventReceived(who,ev) {
	const w=who.split('_');
	Logger.log("Event reçu de "+conn[who].mdns.txt.product_name+'('+w[1]+') de type '+w[0]+' : '+JSON.stringify(ev),LogType.INFO);
	jsend({eventType: 'updateValue', id: who, value: ev});
}




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


process.on('SIGHUP', function() {
	Logger.log("Recu SIGHUP",LogType.DEBUG);
	myCommands.stop();
});
process.on('SIGTERM', function() {
	Logger.log("Recu SIGTERM",LogType.DEBUG);
	myCommands.stop();
});
