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
	"HWE-BAT":5000,
};
// Equipements pour lesquels HomeWizard a publié l'API v2 (les autres restent en v1 tant que ce n'est pas le cas)
const v2Supported=["HWE-P1","SDM230-wifi","HWE-KWH1","SDM630-wifi","HWE-KWH3","HWE-BAT"];
// Equipements qui n'existent qu'en API v2
const v2Only=["HWE-BAT"];
const systemPollingInterval=60000;
const webSocketFallbackDelay=15000; // sans mesure reçue par WebSocket après ce délai, on repasse en polling
const pairDefaultTimeout=120; // secondes
const pairInterval=2000;


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
const systemIntervals = {};
const wsTimers = {}; // API v2 : timers du WebSocket par équipement {fallback, throttle}
const known = {}; // derniers mdns reçus par équipement : {v1: mdns, v2: mdns}
const pairings = {}; // appairages (migration API v2) en cours
conf.devices = {}; // {id: {apiVersion: 'v2', token: 'xxx'}} reçu de Jeedom
// La librairie doit supporter l'API v2 (>= 1.6.0)
const libHasV2 = (typeof HW.P1MeterApiV2 == 'function');
// prepare callback for discovery
const discovery = new HW.HomeWizardEnergyDiscovery((libHasV2)?{types: ['hwenergy', 'homewizard']}:undefined);

// Ne jamais écrire un token dans les logs
function logSafe(obj) {
	return JSON.stringify(obj, (key, value) => ((key === 'token' && value) ? '***' : value));
}


/* Routing */
const app = express();
app.set("query parser", "extended");
const myCommands = {};

/** Stop the server **/
myCommands.stop = function(req, res) {
	Logger.log("Recu de jeedom: Demande d'arret",LogType.INFO);
	discovery.stop();
	for(const p in pairings) {
		if (hasOwnProperty.call(pairings,p)) {pairings[p].abort();}
	}
	for(const c in conn) {
		if (hasOwnProperty.call(conn,c)) {
			if(conn[c].isPolling.getData) {conn[c].polling.getData.stop();}
			if(systemIntervals[c]) {clearInterval(systemIntervals[c]);}
			if(typeof conn[c].close == 'function') {conn[c].close().catch(() => {});}
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

	Logger.log("Reçu une commande..."+JSON.stringify(req.query),LogType.DEBUG); 
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
	if (!conn[req.query.id]) {
		const error="Equipement "+req.query.id+" non connecté";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','error':error});
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
			// API v2 : pas de contenu en réponse (204), une erreur lève une exception
			if(conn[req.query.id].isV2 || result.identify === 'ok') {
				Logger.log("Réponse de la commande OK : "+JSON.stringify(result),LogType.Info); 
				res.json({'result':'ok'});
			}
		} else {
			const error="Commande "+req.query.cmd+" inconnue !";
			Logger.log(error,LogType.ERROR); 
			res.json({'result':'ko','error':error});
		}
	} catch (e) {
		const errorDetail = e.response || e.message || e;
		const logMsg = (typeof errorDetail === 'object') ? JSON.stringify(errorDetail) : errorDetail;
		Logger.log("Réponse de la commande KO : "+logMsg,LogType.Info);
		res.json({'result':'ko','error':logMsg});
	}
};

myCommands.config = function(req, res) {
	res.type('json');
	res.status(202);
	
	Logger.log('Recu une configuration de jeedom :'+logSafe(req.query),LogType.INFO);
	
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
			conf.devices = req.query.value.devices || {};
			conf.userName = req.query.value.userName || 'jeedom';
			Logger.log("Equipements en API v2 : "+(Object.keys(conf.devices).join(', ') || 'aucun'),LogType.DEBUG);
			if(!libHasV2) {Logger.log("La librairie homewizard-energy-api installée ne supporte pas l'API v2, relancez les dépendances",LogType.WARNING);}
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
	Logger.log("Configuration de : "+req.query.setting+" effectuée avec la valeur : "+((typeof req.query.value == "object")?logSafe(req.query.value):req.query.value),LogType.INFO);
	res.json({'result':'ok','value':JSON.parse(logSafe(req.query.value))});
};

/** Migration API v2 : attend que l'utilisateur appuie sur le bouton de l'équipement pour obtenir un token **/
myCommands.pair = function(req, res) {
	res.type('json');
	const id = req.query.id;
	Logger.log("Reçu une demande d'appairage API v2 pour "+id,LogType.INFO);
	if (!libHasV2) {
		res.json({'result':'ko','error':"La librairie installée ne supporte pas l'API v2, relancez les dépendances"});
		return;
	}
	if (!id || !known[id]) {
		res.json({'result':'ko','error':"Equipement "+id+" pas (encore) découvert sur le réseau"});
		return;
	}
	const type = id.split('_')[0];
	if (!v2Supported.includes(type)) {
		res.json({'result':'ko','error':"L'API v2 n'est pas encore disponible pour "+type});
		return;
	}
	if (pairings[id] && !pairings[id].signal.aborted) {
		res.json({'result':'ko','error':"Un appairage est déjà en cours pour "+id});
		return;
	}
	const mdns = known[id].v2 || known[id].v1;
	const timeout = (parseInt(req.query.timeout) || pairDefaultTimeout) * 1000;
	const api = newConnection(type, mdns, 2, null);
	const controller = new AbortController();
	pairings[id] = controller;
	
	// On répond tout de suite, le résultat est envoyé à Jeedom quand l'utilisateur a appuyé sur le bouton (ou au timeout)
	res.json({'result':'ok','timeout':timeout / 1000});
	
	api.pair(conf.userName, {timeout: timeout, interval: pairInterval, signal: controller.signal}).then((user) => {
		Logger.log(id+" : Bouton appuyé, token reçu pour l'utilisateur "+user.name,LogType.INFO);
		conf.devices[id] = {apiVersion: 'v2', token: user.token};
		jsend({eventType: 'pairResult', id: id, result: 'ok', token: user.token, userName: user.name});
		// on bascule directement la connexion en API v2
		removeConnection(id);
		connect(id, type, mdns);
	}).catch((error) => {
		const reason = error.reason || 'error';
		const errorDetail = error.errorCode || error.message || error;
		Logger.log(id+" : Appairage API v2 KO ("+reason+") : "+errorDetail,(reason == 'error')?LogType.ERROR:LogType.INFO);
		jsend({eventType: 'pairResult', id: id, result: 'ko', reason: reason, error: String(errorDetail)});
	}).finally(() => {
		if (pairings[id] === controller) {delete pairings[id];}
		api.close().catch(() => {});
	});
};

myCommands.pairCancel = function(req, res) {
	res.type('json');
	const id = req.query.id;
	Logger.log("Reçu une annulation d'appairage API v2 pour "+id,LogType.INFO);
	if (pairings[id]) {pairings[id].abort();}
	res.json({'result':'ok'});
};

/** Retour à l'API v1 : supprime l'utilisateur créé sur l'équipement et reconnecte en v1 **/
myCommands.unpair = async function(req, res) {
	res.type('json');
	const id = req.query.id;
	Logger.log("Reçu une demande de retour à l'API v1 pour "+id,LogType.INFO);
	let deleted = false;
	try {
		if (conn[id] && conn[id].isV2) {
			await conn[id].deleteUser();
			deleted = true;
		}
	} catch (e) {
		Logger.log(id+" : Impossible de supprimer l'utilisateur sur l'équipement : "+(e.errorCode || e.message || e),LogType.WARNING);
	}
	delete conf.devices[id];
	const type = id.split('_')[0];
	const mdns = (known[id])?known[id].v1:null;
	removeConnection(id);
	if (mdns) {connect(id, type, mdns);}
	res.json({'result':'ok','userDeleted':deleted});
};


// prepare commands
app.get('/stop', myCommands.stop);
app.get('/cmd',	 myCommands.cmd);
app.get('/config', myCommands.config);
app.get('/pair', myCommands.pair);
app.get('/pairCancel', myCommands.pairCancel);
app.get('/unpair', myCommands.unpair);
app.use(function(err, req, res, _next) {
	res.type('json');
	Logger.log(err,LogType.ERROR);
	res.json({'result':'ko','msg':err});
});


function startStateInterval(index) {
	if(!intervals[index]) {
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
}


/** Listen **/
const server = app.listen(conf.serverPort, () => {
	Logger.log("Démon prêt et à l'écoute sur "+conf.serverPort+" !",LogType.INFO);
	Logger.log("Attente de la réception de la configuration...",LogType.INFO);
	jsend({'eventType': 'daemonReady', 'result':true});
});

// API v2 : un utilisateur (token) Jeedom existe pour cet équipement
function useV2(index) {
	return Boolean(libHasV2 && conf.devices[index] && conf.devices[index].apiVersion == 'v2' && conf.devices[index].token);
}

function newConnection(type, mdns, apiVersion, token) {
	const param={
		polling: {
			interval: conf.pollingIntervals[type],
			stopOnError: true,
		},
		/* logger: {
			method: console.log
		} */
	};
	if(apiVersion == 2) {
		// API v2 : https + token, le certificat de l'équipement est vérifié (appliance/<type>/<serial>)
		param.api = {
			token: token || undefined,
			identifier: (HW.isMdnsTxtRecordV2(mdns.txt) && mdns.txt.id)?mdns.txt.id:HW.getCertificateIdentifier(type, mdns.txt.serial),
		};
		switch(type) {
			case "HWE-P1": // P1 Meter
				return new HW.P1MeterApiV2('https://'+mdns.ip, param);
			case "SDM230-wifi": // kWh meter (1 phase)
			case "HWE-KWH1":
				return new HW.KwhMeter1PhaseApiV2('https://'+mdns.ip, param);
			case "SDM630-wifi": // kWh meter (3 phases)
			case "HWE-KWH3":
				return new HW.KwhMeter3PhaseApiV2('https://'+mdns.ip, param);
			case "HWE-BAT": // Plug-In Battery (API v2 uniquement)
				return new HW.PlugInBatteryApiV2('https://'+mdns.ip, param);
			// HWE-SKT, HWE-WTR, HWE-DSP : API v2 pas encore publiée par HomeWizard
			default:
				return null;
		}
	}
	switch(type) {
		case "HWE-P1": // P1 Meter
			return new HW.P1MeterApi('http://'+mdns.ip, param);
		case "HWE-SKT": // Energy Socket
			return new HW.EnergySocketApi('http://'+mdns.ip, param);
		case "HWE-WTR": // Watermeter (only on USB)
			return new HW.WaterMeterApi('http://'+mdns.ip, param);
		case "SDM230-wifi": // kWh meter (1 phase)
		case "HWE-KWH1":
			return new HW.KwhMeter1PhaseApi('http://'+mdns.ip, param);
		case "SDM630-wifi": // kWh meter (3 phases)
		case "HWE-KWH3":
			return new HW.KwhMeter3PhaseApi('http://'+mdns.ip, param);
		default:
			return null;
	}
}

function removeConnection(index) {
	if(intervals[index]) {
		clearInterval(intervals[index]);
		delete intervals[index];
	}
	if(systemIntervals[index]) {
		clearInterval(systemIntervals[index]);
		delete systemIntervals[index];
	}
	if(wsTimers[index]) {
		clearTimeout(wsTimers[index].fallback);
		clearTimeout(wsTimers[index].throttle);
		delete wsTimers[index];
	}
	if(conn[index]) {
		conn[index].polling.getData.on('error', () => {}).removeAllListeners();
		try {
			conn[index].polling.getData.stop();
		} catch(e){
			// Don't need to do anything
		}
		if(typeof conn[index].close == 'function') {conn[index].close().catch(() => {});}
		delete conn[index];
	}
}

// API v2 : le wifi n'est plus dans les mesures mais dans /api/system
function startSystemInterval(index) {
	const getSystem = async () => {
		try {
			const system = await conn[index].getSystem();
			const rssi = system.wifi_rssi_db;
			const ev = {wifi_ssid: system.wifi_ssid, wifi_rssi_db: rssi};
			// même calcul que le client officiel HomeWizard, garde la commande wifi_strength (%) de l'API v1 vivante
			if(typeof rssi == 'number') {
				ev.wifi_strength = (rssi <= -100 || rssi == 0)?0:((rssi >= -50)?100:2 * (rssi + 100));
			}
			eventReceived(index,ev);
		} catch(error) {
			Logger.log(index+' (getSystem) : '+error,LogType.DEBUG);
		}
	};
	if(!systemIntervals[index]) {
		getSystem();
		systemIntervals[index] = setInterval(getSystem, systemPollingInterval);
	}
}

// API v2 : adapte une mesure avant envoi à Jeedom
function v2Measurement(response) {
	const ev = Object.assign({}, response);
	// change à chaque télégramme, sans intérêt en commande
	delete ev.timestamp;
	// le gaz n'existe plus que dans "external", on garde les commandes total_gas_m3 et gas_unique_id de l'API v1 vivantes
	if(Array.isArray(ev.external)) {
		const gas = ev.external.find((ext) => ext.type == 'gas_meter' && ext.unit == 'm3');
		if(gas) {
			ev.total_gas_m3 = gas.value;
			ev.gas_unique_id = gas.unique_id;
		}
	}
	return ev;
}

async function connect(index, type, mdns) {
	const v2 = useV2(index);
	// if already exists but with different ip, delete previous object.
	if(conn[index] && conn[index].polling.getData && conn[index].mdns.ip != mdns.ip) {
		Logger.log("Changement d'IP de "+conn[index].mdns.ip+" à "+mdns.ip,LogType.DEBUG);
		removeConnection(index);
	}
	if(!conn[index]) {
		Logger.log("Connexion à : "+mdns.ip+" (API "+((v2)?'v2':'v1')+")",LogType.DEBUG);
		const api = newConnection(type, mdns, (v2)?2:1, (v2)?conf.devices[index].token:null);
		if(!api) {
			Logger.log("Equipement inconnu",LogType.WARNING);
			return;
		}
		api.isV2 = v2;
		conn[index] = api;
	}
	if(type == "HWE-SKT" && !conn[index].isV2) {startStateInterval(index);}

	let basic;
	try {
		basic = await conn[index].getBasicInformation();
	} catch(error) {
		if(conn[index] && conn[index].isV2 && error.errorCode == 'user:unauthorized') {
			Logger.log(index+" : Le token de l'API v2 n'est plus valide (utilisateur supprimé ou équipement réinitialisé), refaites la migration de cet équipement",LogType.ERROR);
			jsend({eventType: 'tokenInvalid', id: index});
			removeConnection(index);
			return;
		}
		Logger.log(index+' (getBasicInformation) : '+error,LogType.ERROR);
		removeConnection(index);
		discovery.removeCachedResponseByFqdn(mdns.fqdn);
		// l'équipement a été vu sur le réseau mais ne répond pas (redémarrage...) : on réessaie plus tard
		setTimeout(() => {
			if(!conn[index] && known[index] && (known[index].v1 === mdns || known[index].v2 === mdns)) {connect(index, type, mdns);}
		}, 30000);
		return;
	}
	if(!conn[index]) {return;}
	mdns.firmware_version=basic.firmware_version;

	conn[index].mdns=mdns;
	jsend({eventType: 'createEq', id: index, mdns: mdns});

	if(conn[index].isV2) {
		startSystemInterval(index);
		// API v2 : l'équipement pousse ses mesures par WebSocket, plus besoin de l'interroger
		startWebSocket(index);
	} else {
		startPolling(index);
	}
}

function startPolling(index) {
	// Logger.log("IsPolling getData à : "+conn[index].isPolling.getData,LogType.DEBUG);
	if(!conn[index].isPolling.getData) {
		conn[index].polling.getData.start();
		conn[index].polling.getData.on('response', (response) => {
			if(!conn[index]) {return;}
			eventReceived(index,(conn[index].isV2)?v2Measurement(response):response);
		});
		conn[index].polling.getData.on('error', (error) => {
			if(error.toString().includes("TimeoutError")) {
				Logger.log(index+' (getData) : Ne réponds plus sur le réseau ('+error+')',LogType.ERROR);
			} else {
				Logger.log(index+' (getData) : '+error,LogType.ERROR);
			}
			if(conn[index] && conn[index].mdns) {
				Logger.log(index+' (getData) : Remove '+conn[index].mdns.fqdn+' from mdns cache...',LogType.DEBUG);
				discovery.removeCachedResponseByFqdn(conn[index].mdns.fqdn);
			}
			Logger.log(index+' (getData) : Asking Jeedom to ping...',LogType.DEBUG);
			jsend({eventType: 'doPing', id: index});
			Logger.log(index+' (getData) : Remove response event & error event, stopping & delete ref...',LogType.DEBUG);
			removeConnection(index);
			Logger.log(index+' (getData) : Stopping Discovery...',LogType.DEBUG);
			discovery.stop();
			setTimeout(() => {
				Logger.log(index+' (getData) : Starting Discovery...',LogType.DEBUG);
				discover();
			},5000);
		});
	}
}

// API v2 : les mesures arrivent par WebSocket dès qu'elles changent (chaque seconde pour un P1 Meter).
// Jeedom reçoit au plus une mesure par intervalle de polling configuré, toujours la plus récente.
function startWebSocket(index) {
	const api = conn[index];
	const interval = conf.pollingIntervals[index.split('_')[0]];
	const timers = {fallback: null, throttle: null};
	let lastSent = 0;
	let pending = null;
	let received = false;
	wsTimers[index] = timers;

	const send = () => {
		timers.throttle = null;
		if(conn[index] !== api || !pending) {return;}
		lastSent = Date.now();
		eventReceived(index,v2Measurement(pending));
		pending = null;
	};
	api.websocket.on('measurement', (measurement) => {
		if(!received) {
			received = true;
			Logger.log(index+' (WebSocket) : Connecté, les mesures sont poussées par l\'équipement',LogType.INFO);
		}
		pending = measurement;
		if(timers.throttle) {return;}
		const wait = interval - (Date.now() - lastSent);
		if(wait <= 0) {
			send();
		} else {
			timers.throttle = setTimeout(send, wait);
		}
	});
	api.websocket.on('error', (error) => {
		Logger.log(index+' (WebSocket) : '+(error.errorCode || error.message || error),LogType.DEBUG);
		if(error.errorCode == 'user:unauthorized') {
			Logger.log(index+" : Le token de l'API v2 n'est plus valide (utilisateur supprimé ou équipement réinitialisé), refaites la migration de cet équipement",LogType.ERROR);
			jsend({eventType: 'tokenInvalid', id: index});
			removeConnection(index);
		}
	});
	api.websocket.on('close', () => {
		if(conn[index] !== api) {return;}
		// la librairie se reconnecte toute seule et se réabonne
		Logger.log(index+' (WebSocket) : Connexion perdue, reconnexion automatique...',(received)?LogType.WARNING:LogType.DEBUG);
		if(received) {jsend({eventType: 'doPing', id: index});}
	});
	api.websocket.subscribe('measurement');
	api.websocket.start();

	timers.fallback = setTimeout(() => {
		if(conn[index] !== api || received) {return;}
		Logger.log(index+' (WebSocket) : Aucune mesure reçue par WebSocket après '+(webSocketFallbackDelay / 1000)+'s, utilisation du polling',LogType.WARNING);
		api.websocket.removeAllListeners();
		api.websocket.stop();
		startPolling(index);
	}, webSocketFallbackDelay);
}

function discover() {	
	discovery.start();
	
	discovery.on('response', (mdns) => {
		const type=mdns.txt.product_type;
		const isV2=(libHasV2 && HW.isMdnsTxtRecordV2(mdns.txt));
		Logger.log("Découverte de : "+JSON.stringify(mdns, null, 4),LogType.DEBUG);

		const index=type+'_'+mdns.txt.serial;
		if(!known[index]) {known[index]={};}
		known[index][(isV2)?'v2':'v1']=mdns;
		
		if(isV2) {
			// l'équipement annonce l'API v2 (_homewizard._tcp) : Jeedom peut proposer la migration
			jsend({eventType: 'v2Available', id: index, mdns: mdns, supported: v2Supported.includes(type)});
			if(v2Only.includes(type) && !useV2(index)) {
				// pas d'API v1 : on crée l'équipement, les valeurs arriveront après l'appui sur le bouton (migration)
				jsend({eventType: 'createEq', id: index, mdns: mdns});
				return;
			}
			// en API v1 c'est la réponse _hwenergy._tcp qui gère la connexion
			if(!useV2(index)) {return;}
		} else if(mdns.txt.api_enabled == 0 && !useV2(index)) {
			console.log("API Locale pas activée dans l'application, Icône Engrenage > Mesures > Dispositif > API Locale...");
			return;
		}

		connect(index, type, mdns);
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
		}
		API v2 (_homewizard._tcp) :
			txt: {
				api_version: '2.0.0',
				id: 'appliance/p1dongle/abcdserial',
				serial: 'abcdserial',
				product_type: 'HWE-P1',
				product_name: 'P1 meter'
			}
		*/

	});
	discovery.on('error', (error) => {
		Logger.log("Discovery : "+error,LogType.ERROR);
	});
	discovery.on('warning', (error) => {
		Logger.log("Discovery : "+error,LogType.WARNING);
	});
}


function eventReceived(who,ev) {
	const w=who.split('_');
	if(!conn[who] || !conn[who].mdns) {return;}
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
