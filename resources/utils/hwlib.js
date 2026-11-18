/* jshint esversion: 11,node: true,-W041: false */
"use strict";
/*
 * Adaptateur entre le démon et la librairie homewizard-energy-api 3.x.
 *
 * La 3.x sépare l'API v2 (racine du paquet) de l'API v1 (homewizard-energy-api/v1, inchangée) et change toute l'API v2
 * (identifiants regroupés, poll(), WebSocket, HomeWizardError). Ce module présente au démon une seule interface pour
 * les deux : getBasicInformation(), polling.getData, websocket, getUsers()/deleteUser(), erreurs avec errorCode...
 * C'est le seul fichier qui dépend de l'API de la librairie.
 */
const EventEmitter = require('events');
const HW = require('homewizard-energy-api');
const V1 = require('homewizard-energy-api/v1');

// Certificat racine accepté pour les équipements (tests : faux équipement signé par une autre autorité)
const options = {ca: undefined};

const v2Classes = {
	"HWE-P1": HW.P1Meter,
	"SDM230-wifi": HW.KwhMeter1Phase,
	"HWE-KWH1": HW.KwhMeter1Phase,
	"SDM630-wifi": HW.KwhMeter3Phase,
	"HWE-KWH3": HW.KwhMeter3Phase,
	"HWE-BAT": HW.PlugInBattery,
};
const v1Classes = {
	"HWE-P1": V1.P1MeterApi,
	"HWE-SKT": V1.EnergySocketApi,
	"HWE-WTR": V1.WaterMeterApi,
	"SDM230-wifi": V1.KwhMeter1PhaseApi,
	"HWE-KWH1": V1.KwhMeter1PhaseApi,
	"SDM630-wifi": V1.KwhMeter3PhaseApi,
	"HWE-KWH3": V1.KwhMeter3PhaseApi,
};

// Les erreurs de la 3.x ont un code (user:unauthorized...) : le démon le lit dans errorCode, comme avant
function mapError(error) {
	if (error && typeof error == 'object' && error.code !== undefined && error.errorCode === undefined) {
		try {
			error.errorCode = error.code;
		} catch (e) {
			// propriété en lecture seule : le message suffit
		}
	}
	return error;
}
const wrap = (promise) => promise.catch((error) => {
	throw mapError(error);
});

function connectionOptions(extra) {
	const o = Object.assign({}, extra);
	if (options.ca) {o.ca = options.ca;}
	return o;
}

/* WebSocket : la 3.x se connecte dès l'ouverture, le démon branche ses écouteurs puis appelle start() */
class SocketAdapter extends EventEmitter {
	constructor(device) {
		super();
		this.device = device;
		this.socket = null;
		this.topics = new Set();
		this.on('error', () => {}); // jamais d'erreur non écoutée
	}

	get isAuthorized() {
		return Boolean(this.socket && this.socket.isAuthorized);
	}

	subscribe(topic) {
		this.topics.add(topic);
		if (this.socket) {this.socket.subscribe(topic);}
	}

	start() {
		if (this.socket) {return;}
		// reconnexion après 1s puis de plus en plus espacée, jamais plus de 5s : un équipement redémarré revient vite
		const socket = this.device.openWebSocket({maxReconnectDelay: 5000});
		this.socket = socket;
		for (const topic of this.topics) {
			socket.on(topic, (data) => this.emit(topic, data));
			socket.subscribe(topic);
		}
		socket.on('authorized', () => this.emit('authorized'));
		socket.on('error', (error) => this.emit('error', mapError(error)));
		socket.on('close', (code, reason) => {
			if (this.socket === socket) {this.emit('close', code, reason);}
		});
	}

	stop() {
		const socket = this.socket;
		this.socket = null;
		if (socket) {socket.close();}
	}
}

/* Equipement en API v2 */
class V2Device {
	constructor(type, host, param) {
		const Cls = v2Classes[type];
		this.type = type;
		this.host = host;
		this.identifier = param.identifier;
		this.device = new Cls({host, token: param.token || '', identifier: param.identifier}, connectionOptions());
		this.poller = HW.poll(() => this.device.getMeasurement(), {interval: param.interval, stopOnError: true});
		const poller = this.poller;
		this.polling = {
			getData: {
				start: () => poller.start(),
				stop: () => poller.stop(),
				on(event, listener) {
					poller.on(event, (event == 'error') ? (error) => listener(mapError(error)) : listener);
					return this;
				},
				removeAllListeners() {
					// poll() n'a pas de removeAllListeners : un poller arrêté n'émet plus rien
					poller.stop();
					return this;
				},
			},
		};
		this.isPolling = {get getData() {return poller.isPolling;}};
		this.websocket = new SocketAdapter(this.device);
		// seulement ce que l'équipement sait faire (identify() n'existe pas sur les kWh Meters dans la librairie)
		for (const method of ['getSystem', 'updateSystem', 'identify', 'reboot', 'getBatteries', 'updateBatteries']) {
			if (typeof this.device[method] == 'function') {
				this[method] = (...args) => wrap(this.device[method](...args));
			}
		}
	}

	getBasicInformation() {
		return wrap(this.device.getDevice());
	}

	getData() {
		return wrap(this.device.getMeasurement());
	}

	getUsers() {
		return wrap(this.device.users.list());
	}

	// sans nom : l'utilisateur de ce Jeedom (celui du token)
	async deleteUser(name) {
		let user = name;
		if (!user) {
			const current = (await this.getUsers()).find((u) => u.current);
			if (!current) {throw new Error("Utilisateur de ce Jeedom introuvable sur l'équipement");}
			user = current.name;
		}
		return wrap(this.device.users.delete(user));
	}

	// Attend l'appui sur le bouton. Renvoie {token, name} : le nom tel que l'équipement l'enregistre
	async pair(name, o) {
		const credentials = await wrap(HW.pair(this.host, name, connectionOptions({
			pairTimeout: o.timeout,
			interval: o.interval,
			signal: o.signal,
			identifier: this.identifier,
		})));
		let userName = name;
		const device = new v2Classes[this.type](credentials, connectionOptions());
		try {
			const current = (await device.users.list()).find((u) => u.current);
			if (current) {userName = current.name;}
		} catch (e) {
			// le nom demandé fera l'affaire
		} finally {
			device.close().catch(() => {});
		}
		return {token: credentials.token, name: userName};
	}

	async close() {
		this.poller.stop();
		this.websocket.stop();
		await this.device.close();
	}
}

/* Equipement en API v1 : les classes v1 de la 3.x n'ont plus isPolling ni removeAllListeners sur polling.getData */
function wrapV1(api) {
	const emitter = api.eventEmitter;
	const polling = api.polling.getData;
	const wrapped = {
		getData: {
			start: () => polling.start(),
			stop: () => polling.stop(),
			on(event, listener) {
				polling.on(event, listener);
				return this;
			},
			removeAllListeners() {
				emitter.removeAllListeners('getData:response');
				emitter.removeAllListeners('getData:error');
				return this;
			},
		},
	};
	// polling est un getter de la classe : on le masque sur l'instance
	Object.defineProperty(api, 'polling', {value: wrapped, configurable: true});
	api.isPolling = {get getData() {return Boolean(api.activePollingRuns && api.activePollingRuns.getData);}};
	return api;
}

function newDevice(type, ip, apiVersion, param) {
	if (apiVersion == 2) {
		return (v2Classes[type]) ? new V2Device(type, ip, param) : null;
	}
	const Cls = v1Classes[type];
	if (!Cls) {return null;}
	return wrapV1(new Cls('http://' + ip, {polling: {interval: param.interval, stopOnError: true}}));
}

/* Découverte mDNS : _hwenergy._tcp (API v1) et _homewizard._tcp (API v2), un seul flux de réponses pour le démon */
const MdnsDiscovery = Object.getPrototypeOf(V1.HomeWizardEnergyDiscovery);

// Depuis la 3.0.1, la librairie lit le TXT du service annoncé quand un équipement annonce ses deux services dans le
// même paquet (et depuis la 3.0.2 elle associe les enregistrements par leur nom exact).
const V1Discovery = V1.HomeWizardEnergyDiscovery;
class V2Discovery extends MdnsDiscovery {
	constructor() {
		super('homewizard');
	}
}

class Discovery {
	constructor() {
		this.parts = [new V1Discovery(), new V2Discovery()];
	}

	start() {
		for (const part of this.parts) {part.start();}
	}

	stop() {
		for (const part of this.parts) {part.stop();}
	}

	on(event, callback) {
		for (const part of this.parts) {part.on(event, callback);}
	}

	// la prochaine annonce de cet équipement sera de nouveau transmise (même si rien n'a changé)
	removeCachedResponseByFqdn(fqdn) {
		for (const part of this.parts) {part.removeCachedResponseByFqdn(fqdn);}
	}
}

// Annonce du service API v2 (_homewizard._tcp)
function isMdnsTxtRecordV2(txt) {
	return Boolean(txt && typeof txt == 'object' && 'api_version' in txt && 'id' in txt);
}

module.exports = {
	options,
	newDevice,
	Discovery,
	isMdnsTxtRecordV2,
	getCertificateIdentifier: HW.getCertificateIdentifier,
	isSameIdentifier: HW.isSameIdentifier,
	isApiVersionAtLeast: HW.isApiVersionAtLeast,
	v2Types: Object.keys(v2Classes),
};
