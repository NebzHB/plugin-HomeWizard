/* jshint esversion: 8,node: true,-W041: false */
"use strict";
const HW = require('homewizard-energy-api');
const LogType = require('./utils/logger.js').logType;
const Logger = require('./utils/logger.js').getInstance();
const express = require('express');
const fs = require('fs');

Logger.setLogLevel(LogType.DEBUG);
const conf={};
const hasOwnProperty = Object.prototype.hasOwnProperty;
const pollingIntervalsDefaults={
	"HWE-P1":5000,
	"HWE-SKT":5000,
	"HWE-SKT_state":1000,
	"HWE-WTR":5000,
	"SDM230-wifi":5000,
	"SDM630-wifi":5000,
	"HWE-KWH1":5000,
	"HWE-KWH3":5000,
};


// Logger.log('env : '+process.env.NODE_ENV,LogType.DEBUG);


// Args handling
process.argv.forEach(function(val, index) {
	switch (index){
		case 2: conf.urlJeedom = val; break;
		case 3: conf.apiKey = val; break;
		case 4: conf.serverPort = val; break;
		case 5: conf.pid = val; break;
		case 6:
			conf.logLevel = val;
			if (conf.logLevel == 'debug') {Logger.setLogLevel(LogType.DEBUG);}
			else if (conf.logLevel == 'info') {Logger.setLogLevel(LogType.INFO);}
			else if (conf.logLevel == 'warning') {Logger.setLogLevel(LogType.WARNING);}
			else {Logger.setLogLevel(LogType.ERROR);}
		break;
	}
});

// write PID
fs.writeFile(conf.pid, process.pid.toString(), () => {});

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

myCommands.cmd = async function(req, res) {
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
			result=await conn[req.query.id].updateState({power_on: true});
			if(result.power_on === true) {
				Logger.log("Réponse de la commande OK : "+JSON.stringify(result),LogType.Info); 
				res.json({'result':'ok'});
			}
		} else if(req.query.cmd == 'power_off') {
			result=await conn[req.query.id].updateState({power_on: false});
			if(result.power_on === false) {
				Logger.log("Réponse de la commande OK : "+JSON.stringify(result),LogType.Info); 
				res.json({'result':'ok'});
			}
		} else if(req.query.cmd == 'lock') {
			result=await conn[req.query.id].updateState({switch_lock: true});
			if(result.switch_lock === true) {
				Logger.log("Réponse de la commande OK : "+JSON.stringify(result),LogType.Info); 
				res.json({'result':'ok'});
			}
		} else if(req.query.cmd == 'unlock') {
			result=await conn[req.query.id].updateState({switch_lock: false});
			if(result.switch_lock === false) {
				Logger.log("Réponse de la commande OK : "+JSON.stringify(result),LogType.Info); 
				res.json({'result':'ok'});
			}
		} else if(req.query.cmd == 'brightness') {
			result=await conn[req.query.id].updateState({brightness: parseInt(req.query.val)});
			if(result.brightness === parseInt(req.query.val)) {
				Logger.log("Réponse de la commande OK : "+JSON.stringify(result),LogType.Info); 
				res.json({'result':'ok'});
			}
		} else if(req.query.cmd == 'identify') {
			result=await conn[req.query.id].identify();
			if(result.identify === 'ok') {
				Logger.log("Réponse de la commande OK : "+JSON.stringify(result),LogType.Info); 
				res.json({'result':'ok'});
			}
		} else {
			const error="Commande "+req.query.cmd+" inconnue !";
			Logger.log(error,LogType.ERROR); 
			res.json({'result':'ko','error':error});
		}
	} catch (e) {
		Logger.log("Réponse de la commande KO : "+e.response,LogType.Info); 
		res.json({'result':'ko','error':e});
	}
};

myCommands.config = function(req, res) {
	res.type('json');
	res.status(202);
	
	Logger.log('Recu une configuration de jeedom :'+JSON.stringify(req.query),LogType.INFO);
	
	if ('setting' in req.query === false) {
		const error="Pour faire une config, le démon a besoin de son nom";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	if ('value' in req.query === false) {
		const error="Pour faire une config, le démon a besoin d'une valeur a configurer";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	
	switch(req.query.setting) {
		case 'initConfig':
			conf.pollingIntervals = {};
			for (const key in pollingIntervalsDefaults) {
				if(pollingIntervalsDefaults.hasOwnProperty(key)) {
					const value = req.query.value.pollingIntervals[key];
					if (value === undefined || value === "" || value === null || Number(value) < 100) {
						conf.pollingIntervals[key] = pollingIntervalsDefaults[key];
					} else {
						conf.pollingIntervals[key] = Number(value);
					}
				}
			}
			Logger.log("Configuration des intervales de polling : "+JSON.stringify(conf.pollingIntervals),LogType.DEBUG);
			discover();
		break;
		case 'sendLoglevel':
			conf.logLevel = req.query.value;
			if (conf.logLevel == 'debug') {Logger.setLogLevel(LogType.DEBUG);}
			else if (conf.logLevel == 'info') {Logger.setLogLevel(LogType.INFO);}
			else if (conf.logLevel == 'warning') {Logger.setLogLevel(LogType.WARNING);}
			else {Logger.setLogLevel(LogType.ERROR);}
		break;
		default: {
			const error = "Configuration inexistante";
			Logger.log('ERROR CONFIG: ' + req.query.setting + ' : '+error,LogType.ERROR);
			res.json({'result':'ko','msg':error});
			return;
		}
	}
	Logger.log("Configuration de : "+req.query.setting+" effectuée avec la valeur : "+((typeof req.query.value == "object")?JSON.stringify(req.query.value):req.query.value),LogType.INFO);
	res.json({'result':'ok','value':req.query.value});
};


// prepare commands
app.get('/stop', myCommands.stop);
app.get('/cmd',	 myCommands.cmd);
app.get('/config', myCommands.config);
app.use(function(err, req, res, _next) {
	res.type('json');
	Logger.log(err,LogType.ERROR);
	res.json({'result':'ko','msg':err});
});


function startStateInterval(index) {
	if(intervals[index]) {clearInterval(intervals[index]);};
	intervals[index] = setInterval(async () => {
		try {
			const state = await conn[index].getState();
			eventReceived(index,state);
		} catch(error) {
			clearInterval(intervals[index]);
			delete intervals[index];
			if(error.toString().includes("TimeoutError")) {
				Logger.log(index+' (getState) : Ne réponds plus sur le réseau ('+error+')',LogType.ERROR);
			} else {
				Logger.log(index+' (getState) : '+error,LogType.ERROR);
			}
		}
	}, conf.pollingIntervals["HWE-SKT_state"]);
}


/** Listen **/
const server = app.listen(conf.serverPort, () => {
	Logger.log("Démon prêt et à l'écoute sur "+conf.serverPort+" !",LogType.INFO);
	Logger.log("Attente de la réception de la configuration...",LogType.INFO);
	jsend({'eventType': 'daemonReady', 'result':true});
});

function discover() {	
	discovery.start();
	
	discovery.on('response', async (mdns) => {
		const type=mdns.txt.product_type;
		Logger.log("Découverte de : "+JSON.stringify(mdns, null, 4),LogType.DEBUG);
		if(mdns.txt.api_enabled == 0) {console.log("API Locale pas activée dans l'application, Icône Engrenage > Mesures > Dispositif > API Locale...",LogType.INFO);return;}

		const index=type+'_'+mdns.txt.serial;
		
		const param={
			polling: {
				interval: conf.pollingIntervals[type],
				stopOnError: false,
			},
			/* logger: {
				method: console.log
			} */
		};
		switch(type) {
			case "HWE-P1": // P1 Meter
				if(!conn[index]) {conn[index]= new HW.P1MeterApi('http://'+mdns.ip, param);}
			break;
			case "HWE-SKT": // Energy Socket
				if(!conn[index]) {conn[index]= new HW.EnergySocketApi('http://'+mdns.ip, param);}
				startStateInterval(index);
			break;
			case "HWE-WTR": // Watermeter (only on USB)
				if(!conn[index]) {conn[index]= new HW.WaterMeterApi('http://'+mdns.ip, param);}
			break;
			case "SDM230-wifi": // kWh meter (1 phase)
			case "HWE-KWH1":
				if(!conn[index]) {conn[index]= new HW.KwhMeter1PhaseApi('http://'+mdns.ip, param);}
			break;
			case "SDM630-wifi": // kWh meter (3 phases)
			case "HWE-KWH3":
				if(!conn[index]) {conn[index]= new HW.KwhMeter3PhaseApi('http://'+mdns.ip, param);}
			break;
			default:
				Logger.log("Equipement inconnu",LogType.WARNING);
				return;
		}
		
		if (conn[index]) {
			
			const basic = await conn[index].getBasicInformation();
			mdns.firmware_version=basic.firmware_version;
			
			conn[index].mdns=mdns;
			jsend({eventType: 'createEq', id: index, mdns: mdns});
			conn[index].polling.getData.stop();
			conn[index].polling.getData.on('error', () => {}).removeAllListeners();
			conn[index].polling.getData.start();
			conn[index].polling.getData.on('response', (response) => {
				eventReceived(index,response);
			});
			conn[index].polling.getData.on('error', (error) => {
				if(error.toString().includes("TimeoutError")) {
					Logger.log(index+' (getData) : Ne réponds plus sur le réseau ('+error+')',LogType.ERROR);
				} else {
					Logger.log(index+' (getData) : '+error,LogType.ERROR);
				}
				if(conn[index] && conn[index].mdns) {
					Logger.log(index+' (getData) : Remove from mdns cache...',LogType.DEBUG);
					discovery.removeCachedResponseByFqdn(conn[index].mdns.fqdn);
				}
				Logger.log(index+' (getData) : Asking Jeedom to ping...',LogType.DEBUG);
				jsend({eventType: 'doPing', id: index});
				try {
					Logger.log(index+' (getData) : Stopping...',LogType.DEBUG);
					conn[index].polling.getData.stop();
				} catch(e){
					// Don't need to do anything
				} finally {
					Logger.log(index+' (getData) : Remove response event & error event...',LogType.DEBUG);
					conn[index].polling.getData.on('error',() => {}).removeAllListeners();
					Logger.log(index+' (getData) : Delete ref...',LogType.DEBUG);
					delete conn[index];
					Logger.log(index+' (getData) : Stopping Discovery...',LogType.DEBUG);
					discovery.stop();
					setTimeout(() => {
						Logger.log(index+' (getData) : Starting Discovery...',LogType.DEBUG);
						discover();
					},5000);
				}
			});
			}
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
}


function eventReceived(who,ev) {
	const w=who.split('_');
	if(conf.logLevel=='debug') {
		Logger.log("Event reçu de "+conn[who].mdns.txt.product_name+'('+w[1]+') de type '+w[0]+' : '+JSON.stringify(ev),LogType.DEBUG);
	} else if(conf.logLevel=='info') {
		Logger.log("Event reçu de "+conn[who].mdns.txt.product_name+'('+w[1]+') de type '+w[0],LogType.INFO);
	}
	jsend({eventType: 'updateValue', id: who, value: ev});
}


/** 
	UTILS
**/
process.on('SIGHUP', function() {
	Logger.log("Recu SIGHUP",LogType.DEBUG);
	myCommands.stop();
});
process.on('SIGTERM', function() {
	Logger.log("Recu SIGTERM",LogType.DEBUG);
	myCommands.stop();
});
