/* jshint esversion: 8,node: true,-W041: false */
"use strict";
const {Service, Characteristic, Category, TLV, HttpClient, IPDiscovery} = require('hap-controller');
const LogType = require('./utils/logger.js').logType;
const Logger = require('./utils/logger.js').getInstance();
const express = require('express');
const fs = require('fs');
const ipList = require('./utils/ipList.js').ipList;
const BigNumber = require('bignumber.js');


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
			conf.pairingFile=val;
		break;
		case 7:
			conf.jeedom42=val;
		break;
		case 8:
			conf.detectCam=((val=='AllowCam')?true:false);
		break;
	}
});

const jsend = require('./utils/jeedom.js')('hkControl',conf.urlJeedom,conf.apiKey,conf.jeedom42,conf.logLevel);

// loadPairings
if (conf.pairingFile) {
	try {
		conf.pairings = JSON.parse(fs.readFileSync(conf.pairingFile, 'utf8'));
	} catch (err) {
		if (err) {
			Logger.log("Impossible de lire : "+conf.pairingFile+' : '+err, LogType.ERROR);
			process.exit(-1);
		}
	}
} else {
	Logger.log("Pas reçu de fichier de pairing", LogType.ERROR) ;
	process.exit(-1);
}


// display starting
Logger.log("Démarrage démon hkControl...", LogType.INFO);
for(var name in conf) {
	if (hasOwnProperty.call(conf,name)) {
		Logger.log(name+' = '+((typeof conf[name] == 'object')?JSON.stringify(conf[name]):conf[name]), LogType.DEBUG);
	}
}


// prepare callback for discovery
const discovery = new IPDiscovery();
discovery.on('serviceUp', async (mdns) => {
	Logger.log("Bonjour reçu : "+JSON.stringify(mdns),LogType.DEBUG);
	await serviceUp(mdns);
});

discovery.on('serviceDown', async (mdns) => {
	Logger.log("Aurevoir reçu : "+JSON.stringify(mdns),LogType.DEBUG);
	await serviceDown(mdns);
});

discovery.on('serviceChanged', async (mdns) => {
	Logger.log("ServiceChanged reçu : "+JSON.stringify(mdns),LogType.DEBUG);
	// await serviceDown(mdns);
	await serviceUp(mdns);
});

async function serviceUp(mdns) {
	var nonEligibleReason=[];
	if(mdns.availableToPair == false) { // sf: 0 = paired / 1 = not paired / 2 = wifi not configured / 3 = problem detected
		nonEligibleReason.push("Il est déjà appairé");
	}
	if(ipList.includes(mdns.address)) {
		nonEligibleReason.push("Il est local (et pas de boucle autorisée)");
	}
	if(mdns.ci == 17 || mdns.ci == 18) {
		if(!conf.detectCam) {
			nonEligibleReason.push("Pas de support pour les IP Cameras/Video Doorbells");
		}
	}
	if(mdns.md.toLowerCase().includes('homebridge') || mdns.name.toLowerCase().includes('homebridge') || mdns.name.toLowerCase().includes('_repaired') || mdns.name.toLowerCase().includes('jeedom')) {
		nonEligibleReason.push("C'est un Homebridge et il n'acceptera pas de double lien");
	}

	if(nonEligibleReason.length == 0) {
		if(mdns.ci == 2) {
			Logger.log("Le pont "+mdns.name+" est éligible à l'ajout !",LogType.INFO);
		} else {
			Logger.log("L'accessoire "+mdns.name+" de type \""+Category.categoryFromId(mdns.ci)+"\" est éligible à l'ajout !",LogType.INFO);
		}
		const pairMethod = await discovery.getPairMethod(mdns);
		jsend({eventType: 'createEq', mdns : mdns, accType : Category.categoryFromId(mdns.ci), accTypeId : mdns.ci, pairMethod: pairMethod});
	} else if(typeof conf.pairings[mdns.id] == 'object') {
			
			// we are already connected to the accessory !
			Logger.log("Bonjour reçu : "+JSON.stringify(mdns),LogType.DEBUG);
			
			if(mdns.port != conf.pairings[mdns.id].port) {
				Logger.log("Le port a changé !!! ancien :"+conf.pairings[mdns.id].port+" nouveau : "+mdns.port,LogType.DEBUG);
				
				// update in daemon :
				conf.pairings[mdns.id].port=mdns.port;
				savePairings();
				const pairMethod = await discovery.getPairMethod(mdns);
				// update in jeedom :
				jsend({eventType: 'createEqwithoutEvent', mdns : mdns, accType : Category.categoryFromId(mdns.ci), accTypeId : mdns.ci, pairMethod: pairMethod});
				
				conf.pairings[mdns.id].client=null;
			}
			
			if(mdns.address != conf.pairings[mdns.id].address) {
				Logger.log("L'ip a changé !!! ancienne :"+conf.pairings[mdns.id].address+" nouvelle : "+mdns.address,LogType.DEBUG);
				
				// update in daemon :
				conf.pairings[mdns.id].address=mdns.address;
				savePairings();
				const pairMethod = await discovery.getPairMethod(mdns);
				// update in jeedom :
				jsend({eventType: 'createEqwithoutEvent', mdns : mdns, accType : Category.categoryFromId(mdns.ci), accTypeId : mdns.ci, pairMethod: pairMethod});
				
				conf.pairings[mdns.id].client=null;
			}
			
			if(conf.pairings[mdns.id]['c#']!=mdns['c#'] || conf.pairings[mdns.id].client==null) {
				connectAccessories(mdns.id,true);
			} else {
				connectAccessories(mdns.id);
			}
			conf.pairings[mdns.id]['c#']=mdns['c#'];
	} else if(mdns.ci == 2) {
		Logger.log("Le pont "+mdns.name+" est non éligible à l'ajout, raison"+((nonEligibleReason.length >1)?'s':'')+' : '+nonEligibleReason.join(','),LogType.DEBUG);
	} else {
		Logger.log("L'accessoire "+mdns.name+" de type \""+Category.categoryFromId(mdns.ci)+"\" est non éligible à l'ajout, raison"+((nonEligibleReason.length >1)?'s':'')+' : '+nonEligibleReason.join(','),LogType.DEBUG);
	}

}

async function serviceDown(mdns) {
	if(typeof conf.pairings[mdns.id] == 'object') {
		conf.pairings[mdns.id]['c#']=mdns['c#'];
	}
	if(conf.pairings[mdns.id] && conf.pairings[mdns.id].client && typeof conf.pairings[mdns.id].client == 'object') {
		if(conf.pairings[mdns.id].client.subscribedCharacteristics && conf.pairings[mdns.id].client.subscribedCharacteristics.length) {
			Logger.log("Désouscription en cours de "+conf.pairings[mdns.id].name+" sur "+conf.pairings[mdns.id].client.subscribedCharacteristics+'...',LogType.DEBUG);
			var error=false;
			try {
				await conf.pairings[mdns.id].client.unsubscribeCharacteristics();
			} catch(e) { 
				error=true;
			}
			if(!error) {
				Logger.log("Désouscription réussie de "+conf.pairings[mdns.id].name+" sur "+conf.pairings[mdns.id].client.subscribedCharacteristics,LogType.DEBUG);
			}
		}
		Logger.log("Clôture de connection",LogType.DEBUG);
		conf.pairings[mdns.id].client.removeAllListeners('event-disconnect');
		conf.pairings[mdns.id].client.removeAllListeners('disconnect');
		conf.pairings[mdns.id].client.removeAllListeners('event');
		conf.pairings[mdns.id].client.close();
		conf.pairings[mdns.id].client=null;
	}
	if(conf.pairings[mdns.id] && conf.pairings[mdns.id].rediscoverInterval) {
		Logger.log("Arret du reDiscover, on a reçu un Aurevoir propre.",LogType.DEBUG);
		clearInterval(conf.pairings[mdns.id].rediscoverInterval);
		conf.pairings[mdns.id].rediscoverInterval=null;
	}
}

async function eventDisconnect(subscriptionList) {
	var id = this.deviceId;
	Logger.log("Déconnexion des Events reçu de "+conf.pairings[id].name+' : '+subscriptionList,LogType.DEBUG);
	
	try {
		Logger.log("ReSouscription en cours à "+conf.pairings[id].name+" sur "+subscriptionList+'...',LogType.DEBUG);
		await conf.pairings[id].client.subscribeCharacteristics(subscriptionList);
	} catch(e) {
		jsend({eventType: 'doPing', id: id});
		Logger.log("Impossible de ReSouscrire à "+conf.pairings[id].name+" sur "+subscriptionList+' : '+JSON.stringify(e),LogType.DEBUG);
		if(typeof e == 'object' && (e.code=='EHOSTUNREACH' || e.code=='ETIMEDOUT')) {
			var rediscoverTime= 5 * 60 * 1000;
			if(conf.pairings[id].rediscoverTry !== null && conf.pairings[id].rediscoverTry < 5) {
				rediscoverTime= 1 * 60 * 1000;
			} 
			Logger.log("L'équipement "+conf.pairings[id].name+" ne réponds plus sur le réseau ("+((e.code=='EHOSTUNREACH')?'éteint':'planté')+" ?), reDécouverte dans "+(rediscoverTime/60000)+"min si pas de Bonjour...", LogType.DEBUG);
			if(!conf.pairings[id].rediscoverInterval) {
				conf.pairings[id].rediscoverTry++;
				conf.pairings[id].rediscoverInterval=setInterval(myCommands.reDiscover, rediscoverTime, id, null);
			}
		}
		Logger.log("Clôture de connection",LogType.DEBUG);
		conf.pairings[id].client.removeAllListeners('event-disconnect');
		conf.pairings[id].client.removeAllListeners('disconnect');
		conf.pairings[id].client.removeAllListeners('event');
		conf.pairings[id].client.close();
		conf.pairings[id].client=null;
		return;
	}
	Logger.log("ReSouscrit correctement à "+conf.pairings[id].name+" sur "+subscriptionList,LogType.DEBUG);
}

function disconnectReceived(idReceived=null) {
	var rediscoverTime= 5 * 60 * 1000;
	if(conf.pairings[id].rediscoverTry !== null && conf.pairings[id].rediscoverTry < 5) {
		rediscoverTime= 1 * 60 * 1000;
	}
	
	var id = this.deviceId || idReceived;
	jsend({eventType: 'doPing', id: id});
	if(id && conf.pairings[id] && conf.pairings[id].name) {
		Logger.log("Disconnect de : "+conf.pairings[id].name+((!conf.pairings[id].rediscoverInterval)?" reDécouverte dans "+(rediscoverTime/60000)+"min si pas de Bonjour...":''),LogType.INFO);
	} else {
		Logger.log("Disconnect de : "+id+' '+JSON.stringify(conf.pairings[id])+' '+id+' '+JSON.stringify(this),LogType.DEBUG);
	} 
	if(conf.pairings[id] && !conf.pairings[id].rediscoverInterval) {
		conf.pairings[id].rediscoverTry++;
		conf.pairings[id].rediscoverInterval=setInterval(myCommands.reDiscover, rediscoverTime, id, null);
	}
	conf.pairings[id].client.removeAllListeners('event-disconnect');
	conf.pairings[id].client.removeAllListeners('disconnect');
	conf.pairings[id].client.removeAllListeners('event');
	conf.pairings[id].client.close();
	conf.pairings[id].client=null;
}

/* Routing */
const app = express();
var server = null;
var myCommands = {};

myCommands.reDiscover = function(req,res) {
	if(res) {res.type('json');}

	Logger.log("Reçu une demande de reDécouverte"+((req && typeof req == 'string')?" pour "+conf.pairings[req].name+'('+req+')':'')+'...',LogType.Debug); 

	discovery.start();

	if(res) {res.json({'result':'ok'});}
};
myCommands.listDiscover = function(req,res) {
	res.type('json');

	Logger.log("Reçu une demande de listDécouverte...",LogType.Debug); 

	res.json({'result':'ok','list':discovery.list()});
};

myCommands.pair = function(req,res,_next){
	res.type('json');
	
	if ('id' in req.query === false) {Logger.log("Pour appairer, le démon a besoin de l'id",LogType.ERROR); }
	if ('name' in req.query === false) {Logger.log("Pour appairer, le démon a besoin du nom",LogType.ERROR); }
	
	Logger.log("Reçu une demande d'appairage pour "+req.query.name+'('+req.query.id+')...',LogType.INFO); 

	if ('pin' in req.query === false) {Logger.log("Pour appairer, le démon a besoin du PIN Homekit",LogType.ERROR); }
	if ('address' in req.query === false) {Logger.log("Pour appairer, le démon a besoin de l'address",LogType.ERROR); }
	if ('port' in req.query === false) {Logger.log("Pour appairer, le démon a besoin du port",LogType.ERROR); }
	if ('typeId' in req.query === false) {Logger.log("Pour appairer, le démon a besoin du type",LogType.ERROR); }
	if ('pairMethod' in req.query === false) {Logger.log("Pour appairer, le démon a besoin du pairMethod",LogType.ERROR); }

	if (typeof conf.pairings[req.query.id] == 'object') {
		const error="Cet équipement est déjà appairé";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	res.status(202);
	try{
		const client = new HttpClient(req.query.id, req.query.address, req.query.port, {usePersistentConnections: false});
		client.pairSetup(req.query.pin,req.query.pairMethod).then(() => {
			Logger.log("Appairage réussi de "+req.query.name+'('+req.query.id+')',LogType.INFO);
			// Logger.log("getLongTermData : "+JSON.stringify(client.getLongTermData(), null, ''),LogType.DEBUG);
			conf.pairings[req.query.id]={
				'name':req.query.name,
				'pin':req.query.pin,
				'address':req.query.address,
				'port':req.query.port,
				'type':req.query.typeId,
				'pairing':client.getLongTermData(),
				'client':client,
				'eventCharList':null,
			};
			savePairings();
			jsend({eventType: 'pairedEq', result:'ok', id: req.query.id});
			

			getAccessory(req.query.id).then((p) => {
				conf.pairings[p].rediscoverTry=0;
				if(!isEmpty(conf.pairings[p].client)) {
					if(conf.pairings[p].client.listeners('event').length == 0) {
						Logger.log("Ajout d'un écouteur d'évenements pour "+conf.pairings[p].name,LogType.DEBUG);
						conf.pairings[p].client.on('event', eventReceived);
					}
					if(conf.pairings[p].client.listeners('disconnect').length == 0) {
						conf.pairings[p].client.on('disconnect', disconnectReceived);
					}
					if(conf.pairings[p].client.listeners('event-disconnect').length == 0) {
						conf.pairings[p].client.on('event-disconnect', eventDisconnect);
					}
					return startListeningChars(p).then(()=>{
						res.json({'result':'ok'});
					}).catch((e) => res.json({'result':'ko-startListeningChars-pair','msg':JSON.stringify(e)}));
				} else {
					Logger.log("Aucun connecteur pour souscrire",LogType.ERROR);
				}
			}).catch((e) => res.json({'result':'ko-getAccessory','msg':JSON.stringify(e)}));

		}).catch((e) => res.json({'result':'ko-pairSetup','msg':JSON.stringify(e)}));
	} catch (err){
		res.json({'result':'ko-else','msg':JSON.stringify(err)});
	}
};

myCommands.prePair = function(req,res,_next){
	res.type('json');
	
	if ('id' in req.query === false) {Logger.log("Pour appairer, le démon a besoin de l'id",LogType.ERROR); }
	if ('name' in req.query === false) {Logger.log("Pour appairer, le démon a besoin du nom",LogType.ERROR); }
	
	Logger.log("Reçu une demande de pré-appairage pour "+req.query.name+'('+req.query.id+')...',LogType.INFO); 
	
	if ('address' in req.query === false) {Logger.log("Pour appairer, le démon a besoin de l'address",LogType.ERROR); }
	if ('port' in req.query === false) {Logger.log("Pour appairer, le démon a besoin du port",LogType.ERROR); }
	if ('typeId' in req.query === false) {Logger.log("Pour appairer, le démon a besoin du type",LogType.ERROR); }
	if ('pairMethod' in req.query === false) {Logger.log("Pour appairer, le démon a besoin du pairMethod",LogType.ERROR); }

	if (typeof conf.pairings[req.query.id] == 'object') {
		const error="Cet équipement est déjà appairé";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	res.status(202);
	try{
		const client = new HttpClient(req.query.id, req.query.address, req.query.port, {usePersistentConnections: false});
		client.startPairing(req.query.pairMethod).then((data) => {
			Logger.log("Pré-Appairage réussi de "+req.query.name+'('+req.query.id+')',LogType.INFO);
			conf.pairings[req.query.id]={
				'name':req.query.name,
				'pin':req.query.pin,
				'address':req.query.address,
				'port':req.query.port,
				'type':req.query.typeId,
				'pairing':data,
				'prePairing':true,
				'client':client,
				'eventCharList':null,
			};
			savePairings();
			jsend({eventType: 'prepairedEq', result:'ok', id: req.query.id});
			
			res.json({'result':'ok'});
		}).catch((e) => res.json({'result':'ko-prepairSetup','msg':JSON.stringify(e)}));
	} catch (err){
		res.json({'result':'ko-else','msg':JSON.stringify(err)});
	}
};

myCommands.postPair = function(req,res,_next){
	res.type('json');

	if ('id' in req.query === false) {Logger.log("Pour appairer, le démon a besoin de l'id",LogType.ERROR); }
	
	Logger.log("Reçu une demande post-appairage pour "+conf.pairings[req.query.id].name+'('+req.query.id+')...',LogType.INFO); 

	if ('pin' in req.query === false) {Logger.log("Pour appairer, le démon a besoin du PIN Homekit",LogType.ERROR); }

	if (typeof conf.pairings[req.query.id].pairing == 'object' && !conf.pairings[req.query.id].prePairing) {
		const error="Cet équipement est déjà appairé";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}

	if (typeof conf.pairings[req.query.id] != 'object') {
		const error="Cet équipement n'est pas pré-appairé";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	
	if (conf.pairings[req.query.id].prePairing != true) {
		const error="Cet équipement n'est pas pré-appairé";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	
	if (typeof conf.pairings[req.query.id].client != 'object') {
		const error="Cet équipement n'est pas connecté";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	res.status(202);
	try{

		conf.pairings[req.query.id].client.finishPairing(conf.pairings[req.query.id].pairing,req.query.pin).then(() => {
			Logger.log("Post-Appairage réussi de "+conf.pairings[req.query.id].name+'('+req.query.id+')',LogType.INFO);
			conf.pairings[req.query.id].pairing=conf.pairings[req.query.id].client.getLongTermData();
			conf.pairings[req.query.id].prePairing=false;
			savePairings();
			jsend({eventType: 'postpairedEq', result:'ok', id: req.query.id});
			

			getAccessory(req.query.id).then((p) => {
				conf.pairings[p].rediscoverTry=0;
				if(!isEmpty(conf.pairings[p].client)) {
					if(conf.pairings[p].client.listeners('event').length == 0) {
						Logger.log("Ajout d'un écouteur d'évenements pour "+conf.pairings[p].name,LogType.DEBUG);
						conf.pairings[p].client.on('event', eventReceived);
					}
					if(conf.pairings[p].client.listeners('disconnect').length == 0) {
						conf.pairings[p].client.on('disconnect', disconnectReceived);
					}
					if(conf.pairings[p].client.listeners('event-disconnect').length == 0) {
						conf.pairings[p].client.on('event-disconnect', eventDisconnect);
					}
					return startListeningChars(p).then(()=>{
						res.json({'result':'ok'});
					}).catch((e) => res.json({'result':'ko-startListeningChars-postPair','msg':JSON.stringify(e)}));
				} else {
					Logger.log("Aucun connecteur pour souscrire",LogType.ERROR);
				}
			}).catch((e) => res.json({'result':'ko-getAccessory','msg':JSON.stringify(e)}));

		}).catch((e) => res.json({'result':'ko-postpairSetup','msg':JSON.stringify(e)}));
	} catch (err){
		res.json({'result':'ko-else','msg':JSON.stringify(err)});
	}
};


myCommands.unPair = async function(req,res) {
	res.type('json');

	Logger.log("Reçu une demande de désappairage pour "+req.query.id+'...',LogType.INFO); 

	if ('id' in req.query === false) {
		const error="Pour désappairer, le démon a besoin de l'id";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	if (typeof conf.pairings[req.query.id] != 'object') {
		const error="Cet équipement n'est pas appairé";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	res.status(202);
	try{
		var client;
		if(typeof conf.pairings[req.query.id].client != 'object') {
			Logger.log("Reconnecter à "+conf.pairings[req.query.id].name+' ('+req.query.id+','+conf.pairings[req.query.id].address+','+conf.pairings[req.query.id].port+',client:'+JSON.stringify(conf.pairings[req.query.id].client)+',pairing:'+JSON.stringify(conf.pairings[req.query.id].pairing)+')',LogType.DEBUG);
			client = new HttpClient(req.query.id, conf.pairings[req.query.id].address, conf.pairings[req.query.id].port,conf.pairings[req.query.id].pairing, {usePersistentConnections: false});
		} else if(conf.pairings[req.query.id].client){
			Logger.log("Récupération du client existant",LogType.DEBUG);
			client = conf.pairings[req.query.id].client;
		} else {
			client = false;
		}
		
		if(client) {
			if(conf.pairings[req.query.id].client.subscribedCharacteristics && conf.pairings[req.query.id].client.subscribedCharacteristics.length) {
				Logger.log("Désouscription en cours de "+conf.pairings[req.query.id].name+' sur '+conf.pairings[req.query.id].client.subscribedCharacteristics+'...',LogType.DEBUG);
				try {
					await client.unsubscribeCharacteristics();
				} catch(e) { 
					res.json({'result':'ko-unsubscribe','msg':e});
				}
				Logger.log("Désouscription réussie de "+conf.pairings[req.query.id].name+' sur '+conf.pairings[req.query.id].client.subscribedCharacteristics,LogType.DEBUG);
			}	
			
			client.removeAllListeners('event-disconnect');
			client.removeAllListeners('disconnect');
			client.removeAllListeners('event');
			
			Logger.log("Désappairage en cours de "+conf.pairings[req.query.id].name+'('+req.query.id+')...',LogType.INFO);
			try {
				await client.removePairing(client.pairingProtocol.iOSDevicePairingID);
				Logger.log("Désappairage réussi de "+conf.pairings[req.query.id].name+'('+req.query.id+')',LogType.INFO);
				client.close();
				delete conf.pairings[req.query.id];
				savePairings();
				jsend({eventType: 'unPairedEq', result : 'ok', id : req.query.id});
			} catch(e) {
				res.json({'result':'ko-removePairing1','msg':e});
			}
			res.json({'result':'ok'});	

		} else {
			// not connected
			const error = "Impossible de se connecter à "+conf.pairings[req.query.id].name+' ('+req.query.id+','+conf.pairings[req.query.id].address+','+conf.pairings[req.query.id].port+','+JSON.stringify(conf.pairings[req.query.id].pairing)+')';
			Logger.log(error,LogType.ERROR);
			delete conf.pairings[req.query.id];
			savePairings();
			res.json({'result':'ko-noclient','msg':error});
		}
	} catch (err) {
		res.json({'result':'ko-unpair-else','msg':err});
	}
};

myCommands.identify = function(req,res,next) {
	res.type('json');

	Logger.log("Reçu une demande d'identification...",LogType.INFO); 

	if ('id' in req.query === false) {
		const error="Pour identifier, le démon a besoin de l'id";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	if ('char' in req.query === false) {
		const error="Pour identifier, le démon a besoin de la caractéristique";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	if (typeof conf.pairings[req.query.id] != 'object') {
		const error="Cet équipement n'est pas appairé";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	if (typeof conf.pairings[req.query.id].client != 'object' || conf.pairings[req.query.id].client == null) {
		const error="Cet équipement n'est pas connecté (pas de \"Bonjour\" reçu)";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	res.status(202);
	var set={};
	set[req.query.char]=true;
	Logger.log("Identification de "+conf.pairings[req.query.id].name+" sur "+JSON.stringify(set),LogType.DEBUG);
	conf.pairings[req.query.id].client.setCharacteristics(set).then(() => {
		Logger.log(conf.pairings[req.query.id].name+" identifié !",LogType.INFO);
		res.json({'result':'ok'});	
	}).catch(next);
};

myCommands.setAccessories = function(req,res,next) {
	res.type('json');

	Logger.log("Reçu une demande d'action..."+JSON.stringify(req.query),LogType.INFO); 

	if ('id' in req.query === false) {
		const error="Pour faire une action, le démon a besoin de l'id";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	if ('aid' in req.query === false || 'iid' in req.query === false) {
		const error="Pour faire une action, le démon a besoin de la caractéristique (aid et iid)";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	if ('val' in req.query === false) {
		const error="Pour faire une action, le démon a besoin de la valeur";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	if (typeof conf.pairings[req.query.id] != 'object') {
		const error="Cet équipement n'est pas appairé";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	if (typeof conf.pairings[req.query.id].client != 'object' || conf.pairings[req.query.id].client == null) {
		const error="Cet équipement n'est pas connecté (pas de \"Bonjour\" reçu)";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	res.status(202);
	var set={};
	const charact = getChar(conf.pairings[req.query.id].accessories,req.query.aid,req.query.iid);
	const val=sanitizeValue(req.query.val, charact);
	
	set[req.query.aid+'.'+req.query.iid]=val;
	Promise.race([conf.pairings[req.query.id].client.setCharacteristics(set), Promise.delay(10000, 'TimeOut')]).then((r) => {
		if(r=='TimeOut') {
			Logger.log("Action de jeedom pour "+conf.pairings[req.query.id].name+" a fait un timeout (le périphérique n'a pas répondu dans les 10sec, vérifiez le réseau ou le périphérique) "+r,LogType.WARNING);
			Logger.log("Reconnecter à "+conf.pairings[req.query.id].name+' ('+req.query.id+','+conf.pairings[req.query.id].address+','+conf.pairings[req.query.id].port+',client:'+JSON.stringify(conf.pairings[req.query.id].client)+',pairing:'+JSON.stringify(conf.pairings[req.query.id].pairing)+')',LogType.DEBUG);
			conf.pairings[req.query.id].client=new HttpClient(req.query.id, conf.pairings[req.query.id].address, conf.pairings[req.query.id].port,conf.pairings[req.query.id].pairing, {usePersistentConnections: false});
			if(conf.pairings[req.query.id].client.listeners('event').length == 0) {
				Logger.log("Ajout d'un écouteur d'évenements pour "+conf.pairings[req.query.id].name,LogType.DEBUG);
				conf.pairings[req.query.id].client.on('event', eventReceived);
			}
			if(conf.pairings[req.query.id].client.listeners('disconnect').length == 0) {
				conf.pairings[req.query.id].client.on('disconnect', disconnectReceived);
			}
			if(conf.pairings[req.query.id].client.listeners('event-disconnect').length == 0) {
				conf.pairings[req.query.id].client.on('event-disconnect', eventDisconnect);
			}
			Logger.log("Renvoi après reconnection de l'action de jeedom pour "+conf.pairings[req.query.id].name+" : "+ charact.typeLabel+'->'+val,LogType.INFO);
			Promise.race([conf.pairings[req.query.id].client.setCharacteristics(set), Promise.delay(10000, 'TimeOut')]).then((r) => {
				if(r=='TimeOut') {
					Logger.log("Action de jeedom pour "+conf.pairings[req.query.id].name+" a fait un timeout après 1 reconnection (le périphérique n'a pas répondu dans les 10sec, vérifiez le réseau ou le périphérique) "+r,LogType.WARNING);
					res.json({'result':'TimeOut'});
				} else {
					Logger.log("Action de jeedom effectuée pour "+conf.pairings[req.query.id].name+' : '+ charact.typeLabel+'->'+val,LogType.INFO);
					res.json({'result':'ok'});
				}
			}).catch(next);
		} else {
			Logger.log("Action de jeedom effectuée pour "+conf.pairings[req.query.id].name+' : '+ charact.typeLabel+'->'+val,LogType.INFO);
			res.json({'result':'ok'});
		}
	}).catch(next);

};

myCommands.getAccessories = function(req,res,next){
	res.type('json');

	Logger.log("Reçu une demande de refresh d'accessoire...",LogType.INFO); 

	if ('id' in req.query === false) {Logger.log("Pour refresh, le démon a besoin de l'id",LogType.ERROR); }

	if (typeof conf.pairings[req.query.id] != 'object') {
		const error="Cet équipement n'est pas appairé";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	if (typeof conf.pairings[req.query.id].client != 'object' || conf.pairings[req.query.id].client == null) {
		const error="Cet équipement n'est pas connecté (pas de \"Bonjour\" reçu)";
		Logger.log(error,LogType.ERROR); 
		res.json({'result':'ko','msg':error});
		return;
	}
	res.status(202);
	try{
		var p = req.query.id;
		if(typeof conf.pairings[p].client != 'object') {
			Logger.log("Étrange, le client n'existait pas, connexion !");
			conf.pairings[p].client=new HttpClient(p, conf.pairings[p].address, conf.pairings[p].port,conf.pairings[p].pairing, {usePersistentConnections: false});
			if(conf.pairings[p].client.listeners('event').length == 0) {
				Logger.log("Ajout d'un écouteur d'évenements pour "+conf.pairings[p].name,LogType.DEBUG);
				conf.pairings[p].client.on('event', eventReceived);
			}
			if(conf.pairings[p].client.listeners('disconnect').length == 0) {
				conf.pairings[p].client.on('disconnect', disconnectReceived);
			}
			if(conf.pairings[p].client.listeners('event-disconnect').length == 0) {
				conf.pairings[p].client.on('event-disconnect', eventDisconnect);
			}
		}
		getAccessory(p,true).then((p) => {
			conf.pairings[p].rediscoverTry=0;
			Logger.log("Rafraîchissement pour "+conf.pairings[p].name,LogType.DEBUG);
			if(!isEmpty(conf.pairings[p].client)) {
				return startListeningChars(p).then(()=>{
					res.json({'result':'ok'});
				}).catch((e) => res.json({'result':'ko-startListeningChars-getAcc','msg':JSON.stringify(e)}));
			} else {
				Logger.log("Aucun connecteur pour souscrire",LogType.ERROR);
			}
		}).catch(next);
	} catch (err){
		res.json({'result':'ko-else','msg':JSON.stringify(err)});
	}
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
	var unSubscr=[];
	for(var p in conf.pairings) {
		if (hasOwnProperty.call(conf.pairings,p) && conf.pairings[p].client != null && typeof conf.pairings[p].client == 'object') {
			conf.pairings[p].client.removeAllListeners('event-disconnect');
			conf.pairings[p].client.removeAllListeners('disconnect');
			conf.pairings[p].client.removeAllListeners('event');
			if(conf.pairings[p].client.subscribedCharacteristics && conf.pairings[p].client.subscribedCharacteristics.length) {
				Logger.log("Désouscription de "+conf.pairings[p].name+" sur "+conf.pairings[p].client.subscribedCharacteristics,LogType.DEBUG);
				unSubscr.push(conf.pairings[p].client.unsubscribeCharacteristics().catch((error) => { return error; })); 
				unSubscr.push(conf.pairings[p].client.close().catch((error) => { return error; })); 
			}
		}
	}
	discovery.stop();
	Promise.raceAll(unSubscr,(unSubscr.length*1000),'TimedOut').then((r) => {
		if(r.length) {
			var hasError=false;
			for(const p of r) {
				if(p != null) {hasError=true;}
			}
			if(hasError) {
				Logger.log("Unsubscribes Errors (null is ok):"+JSON.stringify(r),LogType.DEBUG);
			}
		}
		res.end();	
		server.close(() => {
			Logger.log("Exit",LogType.INFO);
			process.exit(0);
		});
	});
};

// prepare commands
app.get('/reDiscover', myCommands.reDiscover);
app.get('/listDiscover', myCommands.listDiscover);
app.get('/pair', myCommands.pair);
app.get('/prePair', myCommands.prePair);
app.get('/postPair', myCommands.postPair);
app.get('/unPair', myCommands.unPair);
app.get('/identify', myCommands.identify);
app.get('/getAccessories',myCommands.getAccessories);
app.get('/setAccessories',myCommands.setAccessories);
app.get('/test', myCommands.test);
app.get('/stop', myCommands.stop);
app.use(function(err, req, res, _next) {
	res.type('json');
	Logger.log(err,LogType.ERROR);
	res.json({'result':'ko','msg':err});
});
/** Listen **/
server = app.listen(conf.serverPort, () => {
	Logger.log("Démon prêt et à l'écoute sur "+conf.serverPort+" !",LogType.INFO);
	discovery.start();
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

// -- HStoRGB
// -- Desc : Transofrm HS to RGB
// -- Params --
// -- hue : Hue value
// -- saturation : Saturation value
// -- Return : RGB object
function _HStoRGB(hue, sat) {
	var H,S,V;
	if (arguments.length === 1) {
		sat = hue.s;
		hue = hue.h;
	}
	H = hue / 360.0;
	S = sat / 100.0;
	V = 1;

	const C = V * S;
	H *= 6;
	const m = V - C;
	let x = (H % 2) - 1.0;
	if (x < 0) {
		x = -x;
	}
	x = C * (1.0 - x);
	let R, G, B;
	switch (Math.floor(H) % 6) {
		case 0: R = C + m; G = x + m; B = m; break;
		case 1: R = x + m; G = C + m; B = m; break;
		case 2: R = m; G = C + m; B = x + m; break;
		case 3: R = m; G = x + m; B = C + m; break;
		case 4: R = x + m; G = m; B = C + m; break;
		case 5: R = C + m; G = m; B = x + m; break;
	}
	
	return {
		r : R,
		g : G,
		b : B,
	};
}

// -- RGBtoHS
// -- Desc : Transofrm RGB to HS
// -- Params --
// -- r : Red value
// -- g : Green value
// -- b : Blue value
// -- Return : HS object
function _RGBtoHS(r, g, b) {
	if (arguments.length === 1) {
		r = r.r;
		g = r.g;
		b = r.b;
	}
	var max = Math.max(r, g, b);
	var min = Math.min(r, g, b);
	var d = max - min;
	var h;
	var s = (max === 0.0) ? 0.0 : d / max;

	switch (max) {
	case min:
		h = 0.0;
		break;
	case r:
		h = (g - b) / d;
		if (h < 0) {
			h += 6.0;
		}
		break;
	case g:
		h = (b - r) / d;
		h += 2.0;
		break;
	case b:
		h = (r - g) / d;
		h += 4.0;
		break;
	}

	return {
		h : Math.round(h * 60.0),
		s : Math.round(s * 100.0),
	};
}



function sanitizeValue(currentValue,param) {
	let val=0;
	if(!param) { // just return the value if no param
		return val;
	}
	else if(!param.format) {
		return val;
	}

	switch(param.format) {
			case 'uint8' :
			case 'uint16':
			case 'uint32':
			case 'uint64' :
				val = parseInt(currentValue);
				val = Math.abs(val); // unsigned
				if(!val) {val = 0;}
				if(param.minValue != null && param.minValue != undefined && val < parseInt(param.minValue)) {val = parseInt(param.minValue);}
				if(param.maxValue != null && param.maxValue != undefined && val > parseInt(param.maxValue)) {val = parseInt(param.maxValue);}		
			break;
			case 'int':
				val = parseInt(currentValue);
				if(!val) {val = 0;}
				if(param.minValue != null && param.minValue != undefined && val < parseInt(param.minValue)) {val = parseInt(param.minValue);}
				if(param.maxValue != null && param.maxValue != undefined && val > parseInt(param.maxValue)) {val = parseInt(param.maxValue);}	
			break;
			case 'float':
				val = minStepRound(parseFloat(currentValue),param);
				if(!val) {val = 0.0;}
				if(param.minValue != null && param.minValue != undefined && val < parseFloat(param.minValue)) {val = parseFloat(param.minValue);}
				if(param.maxValue != null && param.maxValue != undefined && val > parseFloat(param.maxValue)) {val = parseFloat(param.maxValue);}
			break;
			case 'bool' :
				val = toBool(currentValue);
				if(val===true && !hasOwnProperty.call(param,'value')) {val=1;}
				if(!val) {val = false;}
			break;
			case 'string' :
				if(currentValue !== undefined) {
					val = currentValue.toString();
				}
				if(!val) {val = '';}
			break;
			case 'tlv8' :
			case 'data' :
				try{
					val=TLV.encodeObject(currentValue);
				}catch(e){
					val=currentValue;	
				}
			break;
			default :
				val = currentValue;
			break;
	}
	return val;
}
function minStepRound(val,param) {
	if(param.minStep == null || param.minStep == undefined) {
		param.minStep = 1;
	}
	const prec = (param.minStep.toString().split('.')[1] || []).length;
	if(val) {
		val = val * Math.pow(10, prec);
		val = Math.round(val); // round to the minStep precision
		val = val / Math.pow(10, prec);
	}
	return val;
}
function toBool(val) {
	if (val == 'false' || val == '0') {
		return false;
	} else {
		return Boolean(val);
	}
}

async function startListeningChars(id,hasConnect=false) {
	if(id) {
		if(conf.pairings[id].client.subscribedCharacteristics && conf.pairings[id].client.subscribedCharacteristics.length && conf.pairings[id].eventCharList && conf.pairings[id].eventCharList.length && JSON.stringify(conf.pairings[id].client.subscribedCharacteristics.sort()) === JSON.stringify(conf.pairings[id].eventCharList.sort()) ) { // if no change, don't need to resubscribe
			Logger.log("Pas de changement de souscription",LogType.DEBUG);
			return new Promise(function(resolve, _reject){resolve();});
		}
			
		if(hasConnect==false && conf.pairings[id].client.subscribedCharacteristics && conf.pairings[id].client.subscribedCharacteristics.length) {
			const presubscribedCharacteristics=conf.pairings[id].client.subscribedCharacteristics;
			Logger.log("Désouscription en cours de "+conf.pairings[id].name+" sur "+conf.pairings[id].client.subscribedCharacteristics+'...',LogType.DEBUG);
			// await Promise.race([conf.pairings[id].client.unsubscribeCharacteristics(), Promise.delay(3000, null)]);
			await conf.pairings[id].client.unsubscribeCharacteristics();
			Logger.log("Désouscription réussie de "+conf.pairings[id].name+" sur "+presubscribedCharacteristics+' !',LogType.DEBUG);
		}
		if(conf.pairings[id].eventCharList && conf.pairings[id].eventCharList.length) {
			Logger.log("Souscription en cours à "+conf.pairings[id].name+" sur "+conf.pairings[id].eventCharList+'...',LogType.DEBUG);
			// await conf.pairings[id].client.subscribeCharacteristics(conf.pairings[id].eventCharList);
			Promise.race([conf.pairings[id].client.subscribeCharacteristics(conf.pairings[id].eventCharList), Promise.delay(10000, 'TimeOut')]).then((r) => {
				if(r=='TimeOut') {
					Logger.log("Souscription à "+conf.pairings[id].name+" a fait un timeout (le périphérique n'a pas répondu dans les 10sec, vérifiez le réseau ou le périphérique) "+r,LogType.WARNING);
				} else {
					Logger.log("Souscrit à "+conf.pairings[id].name+" sur "+conf.pairings[id].eventCharList+' !',LogType.DEBUG);
					if(conf.pairings[id].rediscoverInterval) {
						clearInterval(conf.pairings[id].rediscoverInterval);
						conf.pairings[id].rediscoverInterval=null;
					}
				}
			});
		} else {
			Logger.log("Aucun Event à souscrire",LogType.DEBUG);
		}
		return new Promise(function(resolve, _reject){resolve();});
	} else {
		Logger.log("Aucun id pour souscrire",LogType.ERROR);
		return new Promise(function(_resolve, reject){reject("Aucun id pour souscrire");});
	}
	
}

function getAccessory(id,refresh=false) {
	return new Promise(function(resolve, reject) {
		if(!conf.pairings[id].client) {
			conf.pairings[id].client=new HttpClient(id, conf.pairings[id].address, conf.pairings[id].port,conf.pairings[id].pairing, {usePersistentConnections: false});
			if(conf.pairings[id].client.listeners('event').length == 0) {
				Logger.log("Ajout d'un écouteur d'évenements pour "+conf.pairings[id].name,LogType.DEBUG);
				conf.pairings[id].client.on('event', eventReceived);
			}
			if(conf.pairings[id].client.listeners('disconnect').length == 0) {
				conf.pairings[id].client.on('disconnect', disconnectReceived);
			}
			if(conf.pairings[id].client.listeners('event-disconnect').length == 0) {
				conf.pairings[id].client.on('event-disconnect', eventDisconnect);
			}
		}
		Logger.log("Récupération en cours de la description de l'accessoire...",LogType.DEBUG);
		conf.pairings[id].client.getAccessories().then((acc) => {
			// Logger.log("Description de l'accessoire BF : "+JSON.stringify(acc),LogType.DEBUG);
			[acc,conf.pairings[id].eventCharList]=addTypeLabels(acc);
			Logger.log("Description de l'accessoire reçue : "+JSON.stringify(acc),LogType.INFO);
			conf.pairings[id].accessories=acc.accessories;
			savePairings();

			const cmd = {eventType: 'getAccessories',id: id,refresh: refresh};
			Logger.log("Envoi de l'Accessory brut : "+JSON.stringify(cmd, null, ''),LogType.DEBUG);
			jsend(cmd);
			resolve(id);
		}).catch((e) => reject("Impossible de récupérer les accessoires sur "+id+" erreur :"+JSON.stringify(e)));	
	});
}

function connectAccessories(id=null,refresh=false) {
	try{
		if(!isEmpty(conf.pairings[id].pairing)) {
			if(!conf.pairings[id].client) {
				Logger.log("Connexion à l'accessoire "+conf.pairings[id].name+'('+id+') sur '+conf.pairings[id].address+':'+conf.pairings[id].port+' avec '+JSON.stringify(conf.pairings[id].pairing),LogType.INFO);
				conf.pairings[id].client=new HttpClient(id, conf.pairings[id].address, conf.pairings[id].port,conf.pairings[id].pairing, {usePersistentConnections: false});
			}
			if(conf.pairings[id].client.listeners('event').length == 0) {
				Logger.log("Ajout d'un écouteur d'évenements pour "+conf.pairings[id].name,LogType.DEBUG);
				conf.pairings[id].client.on('event', eventReceived);
			}
			if(conf.pairings[id].client.listeners('disconnect').length == 0) {
				conf.pairings[id].client.on('disconnect', disconnectReceived);
			}
			if(conf.pairings[id].client.listeners('event-disconnect').length == 0) {
				conf.pairings[id].client.on('event-disconnect', eventDisconnect);
			}
			
			Logger.log("Analyse de l'accessoire "+conf.pairings[id].name+' ...',LogType.DEBUG);
			getAccessory(id,false).then((id) => {
				conf.pairings[id].rediscoverTry=0;
				try {
					Logger.log("Souscription pour "+conf.pairings[id].name,LogType.DEBUG);
					startListeningChars(id,refresh).catch((e) => Logger.log("Erreur de souscription 1 : "+JSON.stringify(e),LogType.ERROR));
				}catch(e){
					Logger.log("connectAccessories 2:"+JSON.stringify(e),LogType.ERROR);
				}
			}).catch((e) => Logger.log("connectAccessories 1("+id+'):'+JSON.stringify(e),LogType.ERROR));
		}

	} catch(e){
		Logger.log("connectAccessories 3:"+JSON.stringify(e),LogType.ERROR);
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



function savePairings() {
	var toSave;
	try{
		toSave={};
		for(var p in conf.pairings) {
			if(hasOwnProperty.call(conf.pairings,p)) {
				toSave[p]={
					'name':conf.pairings[p].name,
					'pin':conf.pairings[p].pin,
					'address':conf.pairings[p].address,
					'port':conf.pairings[p].port,
					'type':conf.pairings[p].type,
					'pairing':conf.pairings[p].pairing,
					'prePairing':conf.pairings[p].prePairing,
					'accessories':conf.pairings[p].accessories,
					'client':null,
				};
			}
		}	
		fs.writeFileSync(conf.pairingFile,JSON.stringify(toSave));
	} catch(e){
		Logger.log("Error savePairings : "+JSON.stringify(e),LogType.ERROR);
	}
	toSave=null;
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

// add typeLabel with service and characteristics resolved
function addTypeLabels(acc) {
	console.time('addTypeLabels');
	var eventCharList=[];
	try {
		var thisA,thisS,thisC,_thisModel,newSlabel='',newClabel='',aid='';
		for(thisA of acc.accessories){
			aid=thisA.aid;
			_thisModel=null;
			for(thisS of thisA.services){
				newSlabel=Service.serviceFromUuid(thisS.type);
				if(newSlabel.indexOf('-') == 8 && newSlabel.length == 36 && thisS.description) {
					newSlabel=thisS.description;
				}
				thisS.typeLabel=newSlabel.replace('public.hap.service.','');
				for(thisC of thisS.characteristics) {
					if(thisC.type == '21') {
						_thisModel=thisC.value;
					}
					if(thisC.perms.indexOf('ev') != -1) {
						if(_thisModel != 'HM2-G01') { // HM2-G01 don't like when we register on event of the gateway (but ok for others)
							eventCharList[eventCharList.length]=aid+'.'+thisC.iid;
						}
					}
					newClabel=Characteristic.characteristicFromUuid(thisC.type);
					if(newClabel.indexOf('-') == 8 && newClabel.length == 36 && thisC.description) {
						newClabel=thisC.description;
					}
					thisC.typeLabel=newClabel.replace('public.hap.characteristic.','');
					if(newSlabel.indexOf('-') == 8 && newSlabel.length == 36 && thisC.typeLabel == 'name') {
						thisS.typeLabel=thisC.value;
					}
					if(thisC.typeLabel == 'name') {
						thisS.serviceOriginalName=thisC.value;
					}
					/* if(thisC.format == 'tlv8' || thisC.format == 'data') {
						try{	
							if(thisC.typeLabel,thisC.value.slice(-1) == '=') {
								thisC.value = Buffer.from(thisC.value, 'base64').toString('utf8');
								//console.timeLog('addTypeLabels','  postBuff',thisC.typeLabel,buff);
								//const temp=TLV.decodeBuffer(buff);
								//console.timeLog('addTypeLabels','  postDecode',thisC.typeLabel,temp);
								//thisC.value=JSON.stringify(temp);
								//console.timeLog('addTypeLabels','  postStringify',thisC.typeLabel,thisC.value);
							} else {
								thisC.value=thisC.value.toString('utf8');
							}
						} catch(e){
							thisC.value=thisC.value.toString('utf8');
							// undefined;
						}
					} */
				}
			}
		}
	}catch(e){Logger.log("Error addTypeLabels :"+JSON.stringify(e),Logger.ERROR);}
	console.timeEnd('addTypeLabels');
	return [acc,eventCharList];
}

function getChar(acc,aid,iid) {
	var thisA,thisS,thisC;
	if(hasOwnProperty.call(acc, 'accessories')) {acc=acc.accessories;}
	for(thisA of acc){
		if(thisA.aid != aid) {continue;}
		for(thisS of thisA.services){
			for(thisC of thisS.characteristics) {	
				if(thisC.iid != iid) {continue;}
				thisC.servInfo={type:thisS.type,iid:thisS.iid,typeLabel:thisS.typeLabel};
				return thisC;
			}
		}
	}
	Logger.log("Not found aid :"+aid+" and iid :"+iid+" in "+JSON.stringify(acc),Logger.WARNING);
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

