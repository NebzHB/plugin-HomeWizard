/* jshint esversion: 8,node: true,-W041: false */
"use strict";
const HW = require('./utils/hwlib.js');
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
// Equipements qui pilotent un groupe de Plug-In Batteries (/api/batteries, API v2 uniquement)
const batteriesTypes=["HWE-P1","SDM230-wifi","HWE-KWH1","SDM630-wifi","HWE-KWH3"];
const batteriesMinInterval=5000;
const batteriesIdleInterval=300000; // sans batterie associée (ou endpoint absent), on revérifie toutes les 5 minutes
const systemPollingInterval=60000;
const webSocketFallbackDelay=15000; // sans mesure reçue par WebSocket après ce délai, on repasse en polling
const webSocketLostDelay=30000; // une coupure du WebSocket plus courte est silencieuse (la librairie se reconnecte seule)
const onlineCycles=3; // un équipement est en ligne s'il a envoyé un message pendant les 3 derniers cycles (période configurée)
const onlineMinDelay=10000; // jamais moins de 10s : une reconnexion du WebSocket prend déjà 5s
const v1FallbackDelay=10000; // équipement annoncé seulement en API v2 et pas autorisé : on tente quand même l'API v1
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
const batteriesTimers = {}; // API v2 : timer de lecture du groupe de batteries par équipement
const batteriesState = {}; // dernier état du groupe de batteries par équipement
const lastSeen = {}; // dernier message reçu par équipement
const onlineState = {}; // dernier état en ligne envoyé à Jeedom par équipement
conf.devices = {}; // {id: {apiVersion: 'v2', token: 'xxx'}} reçu de Jeedom
// découverte des annonces API v1 (_hwenergy._tcp) et API v2 (_homewizard._tcp)
const discovery = new HW.Discovery();

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
		} else if(req.query.cmd == 'reboot') {
			if(typeof conn[req.query.id].reboot != 'function') {
				throw new Error("Cet équipement ne peut pas être redémarré par l'API");
			}
			await conn[req.query.id].reboot();
			Logger.log("Redémarrage demandé : "+req.query.id,LogType.Info);
			res.json({'result':'ok'});
		} else if(req.query.cmd == 'cloud' || req.query.cmd == 'led' || req.query.cmd == 'apiv1') {
			// réglages système : communication avec le cloud HomeWizard, luminosité de la led d'état (API v2)
			if(typeof conn[req.query.id].updateSystem != 'function') {
				throw new Error("Cet équipement n'a pas ce réglage");
			}
			let params;
			if(req.query.cmd == 'cloud') {
				params={cloud_enabled: (req.query.val == 1)};
			} else if(req.query.cmd == 'apiv1') {
				// API v2 : couper l'API v1 réserve l'équipement aux clients autorisés (bouton)
				if(!conn[req.query.id].isV2) {throw new Error("Ce réglage nécessite l'API v2");}
				params={api_v1_enabled: (req.query.val == 1)};
			} else {
				if(!conn[req.query.id].isV2) {throw new Error("La luminosité de la led nécessite l'API v2");}
				params={status_led_brightness_pct: Math.max(0, Math.min(100, parseInt(req.query.val) || 0))};
			}
			result=await conn[req.query.id].updateSystem(params);
			Logger.log("Réponse de la commande OK : "+JSON.stringify(result),LogType.Info);
			systemEvent(req.query.id, result);
			res.json({'result':'ok'});
		} else if(req.query.cmd == 'batteries_mode' || req.query.cmd == 'batteries_permission' || req.query.cmd == 'batteries_charge_to_full') {
			// pilotage du groupe de Plug-In Batteries associé à ce compteur (API v2)
			if(typeof conn[req.query.id].updateBatteries != 'function') {
				throw new Error("Cet équipement ne pilote pas de batteries (API v2 nécessaire)");
			}
			let params;
			if(req.query.cmd == 'batteries_mode') {
				params={mode: req.query.val};
			} else if(req.query.cmd == 'batteries_charge_to_full') {
				params={charge_to_full: (req.query.val == 1)};
			} else {
				// les permissions s'envoient en liste complète : on part du dernier état connu
				const current=(batteriesState[req.query.id] && batteriesState[req.query.id].permissions) || [];
				const permissions=current.filter((permission) => permission != req.query.val);
				if(req.query.state == 1) {permissions.push(req.query.val);}
				params={permissions: permissions};
			}
			result=await conn[req.query.id].updateBatteries(params);
			Logger.log("Réponse de la commande OK : "+JSON.stringify(result),LogType.Info); 
			eventReceived(req.query.id,batteriesEvent(req.query.id,result));
			res.json({'result':'ok'});
		} else {
			const error="Commande "+req.query.cmd+" inconnue !";
			Logger.log(error,LogType.ERROR); 
			res.json({'result':'ko','error':error});
		}
	} catch (e) {
		// code d'erreur de l'équipement (API v2 : errorCode, API v1 : apiError), sinon le message de l'erreur
		const errorDetail = e.errorCode || e.apiError || e.message || e;
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
	Logger.log("Reçu une demande de "+((req.query.forget == 1)?"retrait de l'autorisation API v2 (équipement supprimé)":"retour à l'API v1")+" pour "+id,LogType.INFO);
	const type = id.split('_')[0];
	let deleted = false;
	// keepUser : le token est partagé avec un autre Jeedom (copie), on ne le supprime pas de l'équipement
	if (req.query.keepUser != 1) {
		// équipement plus connecté (hors ligne au démarrage...) : connexion temporaire avec son token pour le supprimer
		const temporary = !(conn[id] && conn[id].isV2);
		const api = (!temporary)?conn[id]:((useV2(id) && known[id])?newConnection(type, known[id].v2 || known[id].v1, 2, conf.devices[id].token):null);
		try {
			if (api) {
				// retour en API v1 : l'API v1 a pu être coupée depuis Jeedom, on la rallume d'abord (sinon plus aucune valeur)
				if (req.query.forget != 1 && typeof api.updateSystem == 'function') {
					try {
						const system = await api.getSystem();
						if (system && system.api_v1_enabled === false) {
							await api.updateSystem({api_v1_enabled: true});
							Logger.log(id+" : API v1 réactivée sur l'équipement avant le retour en v1",LogType.INFO);
						}
					} catch (e) {
						Logger.log(id+" : Impossible de vérifier ou réactiver l'API v1 : "+(e.errorCode || e.message || e),LogType.WARNING);
					}
				}
				await api.deleteUser();
				deleted = true;
			}
		} catch (e) {
			Logger.log(id+" : Impossible de supprimer l'utilisateur sur l'équipement : "+(e.errorCode || e.message || e),LogType.WARNING);
		}
		if (api && temporary) {api.close().catch(() => {});}
	}
	delete conf.devices[id];
	removeConnection(id);
	if (req.query.forget == 1) {
		// équipement supprimé de Jeedom : on ne le reconnecte pas en API v1
		delete lastSeen[id];
		delete onlineState[id];
	} else {
		const mdns = (known[id])?known[id].v1:null;
		if (mdns) {connect(id, type, mdns);}
	}
	res.json({'result':'ok','userDeleted':deleted});
};


/** Gestion des utilisateurs (autorisations) enregistrés sur un équipement en API v2 **/
myCommands.users = async function(req, res) {
	res.type('json');
	const id = req.query.id;
	if (!conn[id] || !conn[id].isV2) {
		res.json({'result':'ko','error':"Equipement "+id+" non connecté en API v2"});
		return;
	}
	try {
		res.json({'result':'ok','users':await conn[id].getUsers()});
	} catch (e) {
		res.json({'result':'ko','error':String(e.errorCode || e.message || e)});
	}
};

myCommands.userDelete = async function(req, res) {
	res.type('json');
	const id = req.query.id;
	const name = req.query.name;
	Logger.log("Reçu une demande de suppression de l'utilisateur "+name+" sur "+id,LogType.INFO);
	if (!conn[id] || !conn[id].isV2 || !name) {
		res.json({'result':'ko','error':"Equipement "+id+" non connecté en API v2"});
		return;
	}
	try {
		const users = await conn[id].getUsers();
		// l'utilisateur de ce Jeedom se retire par le retour à l'API v1 ou la suppression de l'équipement
		if (users.some((user) => user.name == name && user.current)) {
			res.json({'result':'ko','error':"C'est l'utilisateur de ce Jeedom"});
			return;
		}
		await conn[id].deleteUser(name);
		res.json({'result':'ok','users':await conn[id].getUsers()});
	} catch (e) {
		Logger.log(id+" : Suppression de l'utilisateur "+name+" KO : "+(e.errorCode || e.message || e),LogType.WARNING);
		res.json({'result':'ko','error':String(e.errorCode || e.message || e)});
	}
};

// prepare commands
app.get('/stop', myCommands.stop);
app.get('/cmd',	 myCommands.cmd);
app.get('/config', myCommands.config);
app.get('/pair', myCommands.pair);
app.get('/pairCancel', myCommands.pairCancel);
app.get('/unpair', myCommands.unpair);
app.get('/users', myCommands.users);
app.get('/userDelete', myCommands.userDelete);
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
server.on('error', (error) => {
	// port déjà utilisé... : inutile de rester en vie sans pouvoir écouter Jeedom
	Logger.log("Impossible d'écouter sur le port "+conf.serverPort+" : "+error,LogType.ERROR);
	process.exit(1);
});

// API v2 : un utilisateur (token) Jeedom existe pour cet équipement
function useV2(index) {
	return Boolean(conf.devices[index] && conf.devices[index].apiVersion == 'v2' && conf.devices[index].token);
}

function newConnection(type, mdns, apiVersion, token) {
	const param = {interval: conf.pollingIntervals[type]};
	if(apiVersion == 2) {
		// API v2 : https + token, le certificat de l'équipement doit être émis pour son identifiant (appliance/<type>/<serial>)
		param.token = token || undefined;
		param.identifier = (HW.isMdnsTxtRecordV2(mdns.txt) && mdns.txt.id)?mdns.txt.id:HW.getCertificateIdentifier(type, mdns.txt.serial);
		// HWE-SKT, HWE-WTR, HWE-DSP : API v2 pas encore publiée par HomeWizard
	}
	return HW.newDevice(type, mdns.ip, apiVersion, param);
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
	if(batteriesTimers[index]) {
		clearTimeout(batteriesTimers[index]);
		delete batteriesTimers[index];
	}
	delete batteriesState[index];
	if(wsTimers[index]) {
		clearTimeout(wsTimers[index].fallback);
		clearTimeout(wsTimers[index].throttle);
		clearTimeout(wsTimers[index].lost);
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

// réglages système remontés à Jeedom (uniquement ceux que l'équipement donne)
const lastUptime = {};
function systemEvent(index, system) {
	if(!system || typeof system != 'object') {return;}
	const ev = {};
	if(typeof system.cloud_enabled == 'boolean') {ev.cloud_enabled = system.cloud_enabled?1:0;}
	if(typeof system.status_led_brightness_pct == 'number') {ev.status_led_brightness_pct = system.status_led_brightness_pct;}
	if(typeof system.api_v1_enabled == 'boolean') {ev.api_v1_enabled = system.api_v1_enabled?1:0;}
	if(typeof system.uptime_s == 'number') {
		// temps de fonctionnement (API v2) : s'il retombe, l'équipement a redémarré (et non une simple coupure réseau)
		ev.uptime_s = system.uptime_s;
		// gardé hors de conn[] : la connexion peut être recréée après une coupure, la valeur précédente doit rester
		if(typeof lastUptime[index] == 'number' && system.uptime_s < lastUptime[index]) {
			Logger.log(index+" : L'équipement a redémarré il y a "+system.uptime_s+"s (il fonctionnait depuis "+lastUptime[index]+"s au relevé précédent)",LogType.WARNING);
		}
		lastUptime[index] = system.uptime_s;
	}
	if(conn[index] && conn[index].isV2) {
		// API v2 : le wifi n'est plus dans les mesures mais dans /api/system
		const rssi = system.wifi_rssi_db;
		if(system.wifi_ssid !== undefined) {ev.wifi_ssid = system.wifi_ssid;}
		if(typeof rssi == 'number') {
			ev.wifi_rssi_db = rssi;
			// même calcul que le client officiel HomeWizard, garde la commande wifi_strength (%) de l'API v1 vivante
			ev.wifi_strength = (rssi <= -100 || rssi == 0)?0:((rssi >= -50)?100:2 * (rssi + 100));
		}
	}
	if(Object.keys(ev).length) {eventReceived(index,ev);}
}

// /api/system : wifi (API v2) et réglages système (cloud, led)
function startSystemInterval(index) {
	const getSystem = async () => {
		if(!conn[index] || typeof conn[index].getSystem != 'function') {return;}
		try {
			systemEvent(index, await conn[index].getSystem());
		} catch(error) {
			Logger.log(index+' (getSystem) : '+error,LogType.DEBUG);
			// utilisateur supprimé pendant que le démon tourne
			if(error.errorCode == 'user:unauthorized' && conn[index]) {tokenInvalid(index);}
		}
	};
	if(!systemIntervals[index]) {
		getSystem();
		systemIntervals[index] = setInterval(getSystem, systemPollingInterval);
	}
}

// API v2 : état du groupe de Plug-In Batteries associé à un compteur, en valeurs simples pour Jeedom
function batteriesEvent(index, batteries) {
	batteriesState[index] = batteries;
	const ev = {
		batteries_count: batteries.battery_count,
		batteries_mode: batteries.mode,
		batteries_power_w: batteries.power_w,
		batteries_target_power_w: batteries.target_power_w,
		batteries_max_consumption_w: batteries.max_consumption_w,
		batteries_max_production_w: batteries.max_production_w,
	};
	if(Array.isArray(batteries.permissions)) {
		ev.batteries_charge_allowed = (batteries.permissions.includes('charge_allowed'))?1:0;
		ev.batteries_discharge_allowed = (batteries.permissions.includes('discharge_allowed'))?1:0;
	}
	if(typeof batteries.charge_to_full == 'boolean') {ev.batteries_charge_to_full = (batteries.charge_to_full)?1:0;}
	return ev;
}

// API v2 : le compteur P1 et les kWh Meters pilotent les Plug-In Batteries qui leur sont associées
function startBatteriesInterval(index) {
	const api = conn[index];
	if(batteriesTimers[index] || typeof api.getBatteries != 'function') {return;}
	const activeInterval = Math.max(conf.pollingIntervals[index.split('_')[0]] || 0, batteriesMinInterval);
	const poll = async () => {
		let delay = batteriesIdleInterval;
		try {
			const batteries = await api.getBatteries();
			if(conn[index] !== api) {return;}
			if(batteries.battery_count === 0) {
				// plus aucune batterie associée : on le signale une fois si on en avait
				if(batteriesState[index] && batteriesState[index].battery_count !== 0) {eventReceived(index,batteriesEvent(index,batteries));}
				batteriesState[index] = batteries;
			} else {
				eventReceived(index,batteriesEvent(index,batteries));
				delay = activeInterval;
			}
		} catch(error) {
			Logger.log(index+' (getBatteries) : '+(error.errorCode || error),LogType.DEBUG);
		}
		if(conn[index] === api) {batteriesTimers[index] = setTimeout(poll, delay);}
	};
	batteriesTimers[index] = setTimeout(poll, 1000);
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

// version d'API inconnue (firmware ancien, champ absent) : on suppose qu'elle suffit, une erreur sera simplement notée
function apiVersionAtLeast(version, minimum) {
	if(typeof version != 'string' || !version) {return true;}
	const result = HW.isApiVersionAtLeast(version, minimum);
	return (result !== false);
}

// Ne doit jamais rejeter : appelée sans await depuis les événements (découverte, appairage...)
function connect(index, type, mdns, quiet) {
	return doConnect(index, type, mdns, quiet).catch((error) => {
		Logger.log(index+' (connect) : '+(error && error.stack || error),LogType.ERROR);
	});
}

function tokenInvalid(index) {
	Logger.log(index+" : Le token de l'API v2 n'est plus valide (utilisateur supprimé ou équipement réinitialisé), refaites l'autorisation de cet équipement avec l'assistant",LogType.ERROR);
	jsend({eventType: 'tokenInvalid', id: index});
	removeConnection(index);
}

// quiet : tentative en API v1 sans annonce v1 reçue, un échec est normal (API locale v1 désactivée) et n'est pas retenté
async function doConnect(index, type, mdns, quiet) {
	const v2 = useV2(index);
	// if already exists but with different ip, delete previous object.
	if(conn[index] && conn[index].mdns && conn[index].mdns.ip != mdns.ip) {
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
			tokenInvalid(index);
			return;
		}
		if(quiet) {
			Logger.log(index+" : Pas de réponse en API v1 ("+error+"), l'équipement attend son autorisation en API v2",LogType.DEBUG);
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
		// /api/batteries n'existe qu'à partir de l'API 2.1.0 (avant : 404)
		if(batteriesTypes.includes(type) && apiVersionAtLeast(basic.api_version, '2.1.0')) {startBatteriesInterval(index);}
		// API v2 : l'équipement pousse ses mesures par WebSocket, plus besoin de l'interroger
		startWebSocket(index);
	} else {
		// API v1 : /api/v1/system ne donne que le réglage du cloud
		startSystemInterval(index);
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
	// déjà démarré pour cette connexion (connect() est rappelé à chaque annonce mDNS de l'équipement, v1 et v2)
	if(wsTimers[index]) {return;}
	const api = conn[index];
	const interval = conf.pollingIntervals[index.split('_')[0]];
	const timers = {fallback: null, throttle: null, lost: null};
	let lost = false; // coupure longue signalée
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
		if(timers.lost) {
			clearTimeout(timers.lost);
			timers.lost = null;
		}
		if(lost) {
			lost = false;
			Logger.log(index+' (WebSocket) : Reconnecté',LogType.INFO);
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
		if(error.errorCode == 'user:unauthorized' && conn[index] === api) {tokenInvalid(index);}
	});
	api.websocket.on('close', () => {
		if(conn[index] !== api) {return;}
		// La librairie se reconnecte toute seule (après 1s, puis de plus en plus espacé jusqu'à 5s) et se réabonne : une coupure courte est normale et reste
		// silencieuse. Si aucune mesure n'est revenue après webSocketLostDelay, on le signale une seule fois.
		Logger.log(index+' (WebSocket) : Connexion fermée, reconnexion automatique...',LogType.DEBUG);
		if(!received || lost || timers.lost) {return;}
		timers.lost = setTimeout(() => {
			timers.lost = null;
			if(conn[index] !== api) {return;}
			lost = true;
			// l'avertissement utilisateur est celui du passage hors ligne (setOnline)
			Logger.log(index+' (WebSocket) : Toujours pas de connexion, nouvelles tentatives en continu...',LogType.DEBUG);
			// si l'équipement a changé d'IP, sa prochaine annonce mDNS doit être prise en compte
			if(api.mdns) {discovery.removeCachedResponseByFqdn(api.mdns.fqdn);}
		}, webSocketLostDelay);
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
	// toujours repartir d'un mDNS neuf : deux équipements en erreur en même temps relancent chacun la découverte,
	// sans cet arrêt les sockets et les écouteurs s'accumulaient
	discovery.stop();
	discovery.start();
	
	discovery.on('response', (mdns) => {
		const type=mdns.txt.product_type;
		const isV2=HW.isMdnsTxtRecordV2(mdns.txt);
		Logger.log("Découverte de : "+JSON.stringify(mdns, null, 4),LogType.DEBUG);

		const index=type+'_'+mdns.txt.serial;
		if(!known[index]) {known[index]={};}
		known[index][(isV2)?'v2':'v1']=mdns;
		
		if(isV2) {
			if(!useV2(index)) {
				// Pas (ou plus) d'API v1 pour cet équipement : uniquement API v2 (batterie), ou API locale v1 non activée
				// (nouvel équipement ajouté directement en v2). On crée l'équipement, ses valeurs arriveront après
				// l'autorisation par le bouton (assistant).
				const v1 = known[index].v1;
				if(v2Supported.includes(type) && (v2Only.includes(type) || !v1 || v1.txt.api_enabled == 0)) {
					jsend({eventType: 'createEq', id: index, mdns: mdns});
				}
			}
			// l'équipement annonce l'API v2 (_homewizard._tcp) : Jeedom peut proposer la migration ou l'autorisation
			// (envoyé après createEq : l'équipement doit exister dans Jeedom pour le retenir)
			jsend({eventType: 'v2Available', id: index, mdns: mdns, supported: v2Supported.includes(type)});
			if(!useV2(index)) {
				// En API v1 c'est l'annonce _hwenergy._tcp qui gère la connexion. Filet de sécurité : si elle n'arrive pas
				// (seule l'annonce v2 a été reçue), on tente quand même l'API v1 pour ne pas laisser sans valeurs un équipement
				// qui fonctionnait en v1.
				if(!v2Only.includes(type) && !known[index].v1) {
					setTimeout(() => {
						if(!conn[index] && !useV2(index) && known[index] && !known[index].v1) {
							Logger.log(index+" : Pas d'annonce API v1 reçue, tentative en API v1...",LogType.DEBUG);
							connect(index, type, mdns, true);
						}
					}, v1FallbackDelay);
				}
				return;
			}
		} else if(mdns.txt.api_enabled == 0 && !useV2(index)) {
			if(v2Supported.includes(type)) {
				Logger.log(index+" : API Locale (v1) pas activée dans l'application. Autorisez l'équipement en API v2 avec l'assistant du plugin, ou activez l'API Locale : Icône Engrenage > Mesures > Dispositif > API Locale...",LogType.INFO);
			} else {
				console.log("API Locale pas activée dans l'application, Icône Engrenage > Mesures > Dispositif > API Locale...");
			}
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


// Commande Online : l'équipement est en ligne tant qu'il a envoyé un message pendant les 3 derniers cycles
function setOnline(index, online) {
	if(onlineState[index] === online) {return;}
	onlineState[index] = online;
	Logger.log(index+' : '+((online)?'En ligne':'Hors ligne, aucun message depuis '+onlineCycles+' cycles'),(online)?LogType.INFO:LogType.WARNING);
	jsend({eventType: 'online', id: index, value: (online)?1:0});
}

setInterval(() => {
	for(const index in onlineState) {
		if (!hasOwnProperty.call(onlineState,index) || !onlineState[index]) {continue;}
		const limit = Math.max(onlineCycles * (conf.pollingIntervals[index.split('_')[0]] || 5000), onlineMinDelay);
		if(Date.now() - lastSeen[index] <= limit) {continue;}
		// En WebSocket l'équipement n'envoie que ce qui change : une connexion ouverte et autorisée (vérifiée par un ping
		// toutes les 30s) compte comme un signe de vie, sinon un équipement dont les valeurs ne bougent pas passerait hors ligne
		if(conn[index] && conn[index].isV2 && wsTimers[index] && conn[index].websocket.isAuthorized) {continue;}
		setOnline(index, false);
	}
}, 1000);

function eventReceived(who,ev) {
	const w=who.split('_');
	if(!conn[who] || !conn[who].mdns) {return;}
	lastSeen[who] = Date.now();
	setOnline(who, true);
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
// Un démon ne doit pas s'arrêter sur une erreur imprévue d'une librairie ou d'un équipement : on la note et on continue
process.on('uncaughtException', function(error) {
	Logger.log("Erreur non gérée : "+(error && error.stack || error),LogType.ERROR);
});
process.on('unhandledRejection', function(reason) {
	Logger.log("Promesse rejetée non gérée : "+(reason && reason.stack || reason),LogType.ERROR);
});
process.on('SIGHUP', function() {
	Logger.log("Recu SIGHUP",LogType.DEBUG);
	myCommands.stop();
});
process.on('SIGTERM', function() {
	Logger.log("Recu SIGTERM",LogType.DEBUG);
	myCommands.stop();
});
