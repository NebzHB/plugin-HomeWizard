"use strict";

const axios = require('axios');

var busy = false;
var jeedomSendQueue = [];

var thisUrl="";
var thisApikey="";
var thisType="";
var thislogLevel="";

var processJeedomSendQueue = function()
{
	// console.log('Nombre de messages en attente de traitement : ' + jeedomSendQueue.length);
	var nextMessage = jeedomSendQueue.shift();

	if (!nextMessage) {
		busy = false;
		return;
	}
	// console.log('Traitement du message : ' + JSON.stringify(nextMessage));
	axios.post(thisUrl,nextMessage.data,{headers:{"Content-Type": "multipart/form-data"}}).then(response => {
		if(response.data.error) {
			console.error("Erreur communication avec Jeedom (retry "+nextMessage.tryCount+"/5): ",response.data.error.code+' : '+response.data.error.message);
			if (nextMessage.tryCount < 5)
			{
				nextMessage.tryCount++;
				jeedomSendQueue.unshift(nextMessage);
			}
			setTimeout(processJeedomSendQueue, 1000+(1000*nextMessage.tryCount));
			return;
		}
		if(thislogLevel == 'debug' && response.data) { console.log("Réponse de Jeedom : ", response); }
		setTimeout(processJeedomSendQueue, 0.01*1000);
	}).catch(err => {
		if(err) { console.error("Erreur communication avec Jeedom (retry "+nextMessage.tryCount+"/5): ",err.code+' : '+err.response.status+' '+err.response.statusText); }
		if (nextMessage.tryCount < 5)
		{
			nextMessage.tryCount++;
			jeedomSendQueue.unshift(nextMessage);
		}
		setTimeout(processJeedomSendQueue, 1000+(1000*nextMessage.tryCount));
	});
};

var sendToJeedom = function(data)
{
	// console.log("sending with "+thisUrl+" and "+thisApikey);

	data.type = 'event';
	data.apikey= thisApikey;
	data.plugin= thisType;

	var message = {};
	message.data = data;
	message.tryCount = 0;
	// console.log("Ajout du message " + JSON.stringify(message) + " dans la queue des messages a transmettre a Jeedom");
	jeedomSendQueue.push(message);
	if (busy) {return;}
	busy = true;
	processJeedomSendQueue();
};


module.exports = ( type, url, apikey, logLevel ) => { 
	// console.log("importing jeedom with "+url+" and "+apikey);
	thisUrl=url;
	thisApikey=apikey;
	thisType=type;
	thislogLevel=logLevel;
	return sendToJeedom;
};
