<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes**********************************/
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class HomeWizard extends eqLogic {
	/***************************Attributs*******************************/	
	public static $_encryptConfigKey = ['token'];
	
	// Secondes laissées à l'utilisateur pour aller appuyer sur le bouton. C'est un choix du plugin : l'API ne fixe aucune
	// limite avant l'appui (on peut l'interroger aussi longtemps qu'on veut), elle n'impose que 30 secondes APRES l'appui
	// pour demander le token, délai toujours respecté puisque le démon interroge toutes les 2 secondes.
	const V2_PAIR_TIMEOUT = 120;

	public static function backupExclude() {
		return ['resources/node_modules'];
	}

	/*
	 * Commande Online : le démon la passe à 0 après 3 cycles sans message de l'équipement et à 1 au message suivant.
	 * Ici, ce que le démon ne peut pas faire lui-même :
	 * - démon arrêté : tous les équipements sont hors ligne
	 * - équipement silencieux qui ne répond plus au ping : hors ligne
	 * - équipement sans aucun message depuis 5 minutes (jamais revu depuis le démarrage du démon...) : hors ligne
	 */
	public static function cron5() {
		$deamon_info = self::deamon_info();
		if ($deamon_info['state'] != 'ok') {
			self::setAllOffline();
			return;
		}
		// on laisse au démon le temps de retrouver les équipements après son démarrage
		if ((time() - cache::byKey('HomeWizard::daemonStart')->getValue(0)) < 300) return;
		$pollPeriods = config::byKey('pollPeriods', 'HomeWizard', [], true);
		foreach(eqLogic::byType('HomeWizard', true) as $HomeWizard) {
			$onlineCmd = $HomeWizard->getCmd(null, 'online');
			if (!is_object($onlineCmd) || $onlineCmd->execCmd() != 1) continue;
			$period = intval($pollPeriods[$HomeWizard->getConfiguration('type')] ?? 0);
			$cycles = max(10, 3 * (($period >= 100) ? $period : 5000) / 1000);
			$silence = time() - cache::byKey('HomeWizard::lastSeen::'.$HomeWizard->getId())->getValue(0);
			if ($silence <= $cycles) continue;
			// silencieux depuis plus de 3 cycles : le ping tranche, sauf au-delà de 5 minutes où le silence suffit
			if ($silence <= 300 && $HomeWizard->pingHost($HomeWizard->getConfiguration('address')) == true) continue;
			log::add('HomeWizard', 'debug', __("Hors ligne", __FILE__) .': '. $HomeWizard->getName());
			$HomeWizard->checkAndUpdateCmd('online', 0);
		}
	}

	/* Démon arrêté : plus aucune valeur ne remonte, les équipements sont hors ligne */
	public static function setAllOffline() {
		foreach(eqLogic::byType('HomeWizard') as $HomeWizard) {
			$onlineCmd = $HomeWizard->getCmd(null, 'online');
			if (is_object($onlineCmd) && $onlineCmd->execCmd() != 0) {
				$HomeWizard->checkAndUpdateCmd('online', 0);
			}
		}
	}

	public static function getConfigForCommunity()
	{
		$update=update::byTypeAndLogicalId('plugin',__CLASS__);
		$ver=$update->getLocalVersion();
		$conf=$update->getConfiguration();
		//log::add(__CLASS__,'debug',"Installation dépendances sur Jeedom ".jeedom::version()." sur ".trim(shell_exec("lsb_release -d -s")).'/'.trim(shell_exec('dpkg --print-architecture')).'/'.trim(shell_exec('arch')).'/'.trim(shell_exec('getconf LONG_BIT'))." aka '".jeedom::getHardwareName()."' avec nodeJS ".trim(shell_exec('node -v'))." et jsonrpc:".config::byKey('api::core::jsonrpc::mode', 'core', 'enable')." et homebridge ".$ver);
		$CommunityInfo="```\n== Jeedom ".jeedom::version()." sur ".trim(shell_exec("lsb_release -d -s")).'/'.trim(shell_exec('dpkg --print-architecture')).'/'.trim(shell_exec('arch')).'/'.trim(shell_exec('getconf LONG_BIT'))."bits aka '".jeedom::getHardwareName()."' avec nodeJS ".trim(shell_exec('node -v'))." et jsonrpc:".config::byKey('api::core::jsonrpc::mode', 'core', 'enable')." et ".__CLASS__." (".$conf['version'].") ".$ver." (avant:".config::byKey('previousVersion',__CLASS__,'inconnu',true).")\n```";
		return $CommunityInfo;
	}

	public static function event($data) {
		$changed=false;
		$eventType = $data['eventType'];
		log::add('HomeWizard', 'debug', __("Passage dans la fonction event", __FILE__) .' '. $eventType);
		if ($eventType == 'error'){
			log::add('HomeWizard', 'error', $data['description']);
			return;
		}
		
		switch ($eventType) {
			case 'createEq':
				log::add('HomeWizard', 'info', __("Découverte de", __FILE__).': '.json_encode($data['mdns']));
				$mdns = $data['mdns'];
				/*{
				  ip: '192.168.1.100',
				  hostname: 'p1meter-ABABAB.local',
				  fqdn: 'p1meter-ABABAB._hwenergy._tcp.local',
				  txt: {
					api_enabled: '1',
					path: '/api/v1',
					serial: 'abcdserial',
					product_type: 'HWE-P1',
					product_name: 'P1 meter'
				  },
      				  "firmware_version":"5.16"
				}*/
				$eq = [
					"name"=>$mdns['txt']['product_name'].'_'.$mdns['txt']['serial'],
					"logicalId"=>$mdns['txt']['product_type'].'_'.$mdns['txt']['serial'],
					"enable"=>1,
					"visible"=>1,
					"configuration"=>[
						"address"=>$mdns['ip'],
						"hostname"=>$mdns['hostname'],
						"type"=>$mdns['txt']['product_type'],
						"serial"=>$mdns['txt']['serial'],
						"firmware_version"=>$mdns['firmware_version'] ?? null,
					]
				];
				// un équipement uniquement API v2 pas encore appairé ne donne pas son firmware
				$eq['configuration']=array_filter($eq['configuration'],function($v) { return $v !== null; });
				self::createEq($eq);
			break;
			case 'v2Available':
				// l'équipement s'annonce sur _homewizard._tcp : son firmware supporte l'API v2
				$eqp = eqlogic::byLogicalId($data['id'],'HomeWizard');
				if (is_object($eqp)) {
					$apiVersion = $data['mdns']['txt']['api_version'] ?? '';
					if ($eqp->getConfiguration('v2Available') != 1 || $eqp->getConfiguration('v2ApiVersion') != $apiVersion) {
						log::add('HomeWizard', 'info', __("API v2 disponible sur", __FILE__).' '.$eqp->getName().' ('.$apiVersion.')');
						$eqp->setConfiguration('v2Available', 1);
						$eqp->setConfiguration('v2ApiVersion', $apiVersion);
						$eqp->save(true);
					}
				}
			break;
			case 'pairResult':
				$eqp = eqlogic::byLogicalId($data['id'],'HomeWizard');
				if (!is_object($eqp)) {
					log::add('HomeWizard','warning',__("Aucun équipement trouvé avec l'id", __FILE__).' '.$data['id']);
					break;
				}
				if ($data['result'] == 'ok' && !empty($data['token'])) {
					$renamed = $eqp->migrateToV2($data['token'], $data['userName'] ?? '');
					if (in_array($eqp->getConfiguration('type'), self::v2OnlyTypes())) {
						log::add('HomeWizard', 'info', __("Jeedom est autorisé sur", __FILE__).' '.$eqp->getName());
					} else {
						log::add('HomeWizard', 'info', __("Migration vers l'API v2 réussie pour", __FILE__).' '.$eqp->getName().' ('.$renamed.' '.__("commandes renommées", __FILE__).')');
					}
					event::add('HomeWizard::pairResult', ['id'=>$eqp->getId(), 'result'=>'ok', 'renamed'=>$renamed]);
				} else {
					log::add('HomeWizard', 'info', __("Appui sur le bouton non abouti pour", __FILE__).' '.$eqp->getName().' : '.($data['reason'] ?? '').' '.($data['error'] ?? ''));
					event::add('HomeWizard::pairResult', ['id'=>$eqp->getId(), 'result'=>'ko', 'reason'=>$data['reason'] ?? 'error', 'error'=>$data['error'] ?? '']);
				}
			break;
			case 'tokenInvalid':
				$eqp = eqlogic::byLogicalId($data['id'],'HomeWizard');
				if (is_object($eqp) && $eqp->getConfiguration('tokenInvalid') != 1) {
					// une seule alerte par équipement dans le centre de messages, retirée quand l'autorisation est refaite
					$alert = __("Jeedom n'est plus autorisé sur l'équipement", __FILE__).' '.$eqp->getHumanName().' : '.__("il ne remonte plus de valeurs. Refaites l'autorisation dans l'assistant Migration API v2 du plugin (un appui sur le bouton de l'équipement).", __FILE__);
					log::add('HomeWizard', 'warning', $alert);
					message::add('HomeWizard', $alert, '', 'HomeWizard::tokenInvalid::'.$eqp->getId());
					$eqp->setConfiguration('tokenInvalid', 1);
					$eqp->save(true);
				}
			break;
			case 'online':
				// en ligne = un message reçu pendant les 3 derniers cycles (calculé par le démon)
				$eqp = eqlogic::byLogicalId($data['id'],'HomeWizard');
				if (is_object($eqp)) {
					log::add('HomeWizard', 'debug', (($data['value'] == 1)?__("En ligne", __FILE__):__("Hors ligne", __FILE__)).' : '.$eqp->getName());
					if ($data['value'] == 1) cache::set('HomeWizard::lastSeen::'.$eqp->getId(), time());
					$eqp->checkAndUpdateCmd('online', ($data['value'] == 1)?1:0);
				}
			break;
			case 'updateValue':
				$logical=$data['id'];
				$eqp = eqlogic::byLogicalId($logical,'HomeWizard');
				if (is_object($eqp)){
					cache::set('HomeWizard::lastSeen::'.$eqp->getId(), time());
					// Un message reçu = en ligne. Le démon n'envoie Online qu'aux changements d'état : si cron5 a passé
					// l'équipement hors ligne entre-temps (ping sans réponse), c'est ici qu'il revient en ligne.
					// Pas pendant l'arrêt du démon, ses derniers messages ne doivent pas remettre en ligne.
					if (cache::byKey('HomeWizard::daemonStopping')->getValue(0) == 0) {
						$onlineCmd = $eqp->getCmd(null, 'online');
						if (is_object($onlineCmd) && $onlineCmd->execCmd() != 1) $eqp->checkAndUpdateCmd('online', 1);
					}
					$val=$data['value'];
					log::add('HomeWizard','debug',json_encode($val));
					$hasNewCmd=false;
					$isV2=($eqp->getConfiguration('apiVersion') == 'v2');
					foreach($val as $key=>$value) {
						// équipement migré : une valeur API v1 encore en transit ne doit pas recréer les anciennes commandes
						if($isV2 && self::v2LogicalId($key) != $key) continue;
						$newCmd = $eqp->getCmd(null, $key);
						if (!is_object($newCmd)) {
							$keyPart=explode('_',$key);
							$unite='';
							// unités connues (les noms de l'API v2 sont plus courts : power_w, voltage_v...)
							$knownUnits=['kwh'=>'kWh','w'=>'W','v'=>'V','a'=>'A','va'=>'VA','var'=>'VAR','hz'=>'Hz','lpm'=>'L/min','m3'=>'m³','pct'=>'%','db'=>'dB'];
							$lastPart=$keyPart[count($keyPart)-1];
							if(count($keyPart) >1 && isset($knownUnits[$lastPart])) {
								$unite=$knownUnits[$lastPart];
							} elseif(count($keyPart) >2) {
								$unite = $lastPart;
								switch($unite) {
									case 'timestamp':
									case 'factor':
									case 'count':
									case 'id':
									case 'l1':
									case 'l2':
									case 'l3':
										$unite='';
									break;
									case 'kwh':
										$unite="kWh";
									break;
									case 'hz':
										$unite="Hz";
									break;
									case 'lpm':
										$unite="L/min";
									break;
									case 'm3':
										$unite="m³";
									break;
									case 'pct':
										$unite="%";
									break;
									default:
										$unite=strtoupper($unite);
									break;
								}
							}
							$cmd=[
								"name"=>ucfirst($key),
								"logicalId"=>$key,
								"isVisible"=>1,
								"isHistorized"=>0,
								"unite"=>$unite,
								"type"=>"info",
								"subtype"=>"numeric",
								"display"=>[
                							"forceReturnLineBefore"=>1
								],
								"template"=>[
									"dashboard"=>'line',
									"mobile"=>'line'
								]
							];
							if($key == 'unique_id' || $key == 'wifi_ssid' || $key == 'meter_model' || $key == 'montly_power_peak_timestamp' || $key == 'monthly_power_peak_timestamp' || $key == 'gas_unique_id' || $key == 'batteries_mode') $cmd['subtype']='string';
							if($key == 'wifi_strength') $cmd['unite']='%';
							if($key == 'power_on' || $key == 'switch_lock' || $key == 'batteries_charge_allowed' || $key == 'batteries_discharge_allowed' || $key == 'batteries_charge_to_full' || $key == 'cloud_enabled' || $key == 'api_v1_enabled') $cmd['subtype']='binary';
							// réglages système : l'état se lit sur les commandes action liées
							if($key == 'cloud_enabled' || $key == 'status_led_brightness_pct' || $key == 'api_v1_enabled') {
								$cmd['isVisible']=0;
								unset($cmd['template']);
							}
							$generic=self::genericTypeFor($key);
							if($generic != '') $cmd['display']['generic_type']=$generic;
							if($key == 'brightness') {
								unset($cmd['template']);
							}
							if($key == 'total_power_import_kwh' || $key == 'total_power_export_kwh' || $key == 'active_power_w' || $key == 'energy_import_kwh' || $key == 'energy_export_kwh' || $key == 'power_w') {
								$cmd['template']['dashboard']='tile';
								$cmd['template']['mobile']='tile';
							}
							$hasNewCmd=$eqp->createCmd($cmd);
						} else {
							switch($key) {
								case 'montly_power_peak_timestamp':
									if (preg_match('/^\d{12}$/', $value)) {
										$value = DateTime::createFromFormat('ymdHis', $value)->format('d/m/Y H:i:s');
									}
								break;
								case 'monthly_power_peak_timestamp': // API v2 : 2024-06-04T10:11:22
									$date = DateTime::createFromFormat('Y-m-d\\TH:i:s', $value);
									if ($date !== false) {
										$value = $date->format('d/m/Y H:i:s');
									}
								break;
								case 'gaz_timestamp':
									if (preg_match('/^\d{12}$/', $value)) {
										$value = DateTime::createFromFormat('ymdHis', $value)->format('d/m/Y H:i:s');
									}
								break;
								case 'active_tariff':
								case 'tariff': // API v2
									switch($value) {
										case '1':
											$txt=__("HP", __FILE__);
										break;
										case '2':
											$txt=__("HC", __FILE__);
										break;
										default:
											$txt=__("Inconnu", __FILE__).'('.$value.')';
										break;
									}
									$eqp->checkAndUpdateCmd(($key == 'tariff')?'tariff_txt':'active_tariff_txt',$txt);
								break;
							}
							$eqp->checkAndUpdateCmd($key,$value);
						}
					}
					// groupe de Plug-In Batteries piloté par ce compteur : commandes action créées avec les infos
					if(isset($val['batteries_mode'])) $eqp->createBatteriesActions();
					if(isset($val['cloud_enabled']) || isset($val['status_led_brightness_pct']) || isset($val['api_v1_enabled'])) {
						$eqp->createSystemActions();
						$eqp->applyGenericTypes();
					}
					if($hasNewCmd) $eqp->save();
				} else {
					log::add('HomeWizard','warning',__("Aucun équipement trouvé avec l'id", __FILE__).' '.$logical);
				}
			break;
			case 'daemonReady':
				$pollingIntervals = config::byKey('pollPeriods', 'HomeWizard', ["HWE-P1"=>0], true);
				$config=[
					"pollingIntervals" => $pollingIntervals,
					"devices" => self::v2Devices(),
					"userName" => self::v2UserName()
				];
				HomeWizard::hwConfig('initConfig',["value"=>$config]);
			break;
			case 'doPing':
				$eqp = eqlogic::byLogicalId($data['id'],'HomeWizard');
				if (is_object($eqp) && $eqp->getIsEnable() == 1){
					if ($eqp->pingHost($eqp->getConfiguration('address')) == false) {
						log::add('HomeWizard', 'debug', __("Offline Réseau", __FILE__) .': '. $eqp->getName());
					} else {
						log::add('HomeWizard', 'debug', __("Online Réseau", __FILE__) .': '. $eqp->getName());
					}
				}
			break;
		}
	}
	
	public static function dependancy_info() {
		$return = [];
		$return['log'] = __CLASS__ . '_dep';
		$return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependance';
		$return['state'] = 'nok';

		// Check if NodeJS exists
		$nodeJSError=null;
		$out=null;
		exec('type node',$out,$nodeJSError);
		$nodeInstalled=($nodeJSError == 0);
		if(!$nodeInstalled) {		
			return $return;
		}

		// Don't check anything more if buster to avoid dep reinstall and blocking users
		if(trim(shell_exec("lsb_release -c -s")) == "buster" && strtotime(date("Y-m-d")) > strtotime("2024-06-30")) {
			$return['state'] = 'ok';
			return $return;
		}

		// Get package.json
		$packageRequiredVers = file_get_contents(dirname(__FILE__) . '/../../resources/package.json');
		$packageRequiredVers = json_decode($packageRequiredVers,true);

		// Check if NodeJS version is greater or equal the required version
		$nodeVer=trim(shell_exec('node -v'),"v\n\r");
		if(!$nodeVer) {$nodeVer='';}
		preg_match('/(>=|<=|>|<|=)?(\d+(\.\d+){0,2})/', $packageRequiredVers['engines']['node'], $matches);
		$nodeOperator = $matches[1] ?: '==';
		$nodeVersion = $matches[2];
		
		$nodeVersionOK=version_compare($nodeVer,$nodeVersion,$nodeOperator);
		if(!$nodeVersionOK) {
			return $return;
		}

		// Check if NPM version is greater or equal the required version
		$npmVer=trim(shell_exec('npm -v'),"\n\r");
		if(!$npmVer) {$npmVer='';}
		preg_match('/(>=|<=|>|<|=)?(\d+(\.\d+){0,2})/', $packageRequiredVers['engines']['npm'], $matches);
		$npmOperator = $matches[1] ?: '==';
		$npmVersion = $matches[2];
		
		$npmVersionOK=version_compare($npmVer,$npmVersion,$npmOperator);
		if(!$npmVersionOK) {
			return $return;
		}

		// Check if jeedom connect class is present
		if(!file_exists(dirname(__FILE__) . '/../../resources/utils/jeedom.js')) {
			return $return;
		}

		// Check if all dependancies of hap-controller are installed and have the required version
		foreach($packageRequiredVers['dependencies'] as $dep => $requiredVersionSpec) {
		    $depPackageJson = @file_get_contents(dirname(__FILE__) . '/../../resources/node_modules/' . $dep . '/package.json');
		    if (!$depPackageJson) {
		        return $return;
		    }
		
		    $depDetails = json_decode($depPackageJson, true);
		    $installedVersion = $depDetails['version'];
		
		    // dépendance installée depuis GitHub : pas de version dans la spec, la version minimale est dans githubMinVersions
		    if (strpos($requiredVersionSpec, 'github:') === 0) {
		        $requiredVersionSpec = $packageRequiredVers['githubMinVersions'][$dep] ?? '>=0';
		    }
		
		    preg_match('/(>=|<=|>|<|=)?(\d+(\.\d+){0,2})/', $requiredVersionSpec, $matches);
		    $requiredOperator = $matches[1] ?: '==';
		    $requiredVersion = $matches[2];
		
		    if (!version_compare($installedVersion, $requiredVersion, $requiredOperator)) {
		        return $return;
		    }
		}
		
		$return['state'] = 'ok';
		return $return;
	}

	public static function dependancy_install() {
		$dep_info = self::dependancy_info();
		log::remove('HomeWizard' . '_dep');
		$update=update::byTypeAndLogicalId('plugin','HomeWizard');
		$ver=$update->getLocalVersion();
		$conf=$update->getConfiguration();
		//log::add('HomeWizard','debug',"Installation dépendances sur Jeedom ".jeedom::version()." sur ".trim(shell_exec("lsb_release -d -s")).'/'.trim(shell_exec('dpkg --print-architecture')).'/'.trim(shell_exec('arch')).'/'.trim(shell_exec('getconf LONG_BIT'))." aka '".jeedom::getHardwareName()."' avec nodeJS ".trim(shell_exec('node -v'))." et jsonrpc:".config::byKey('api::core::jsonrpc::mode', 'core', 'enable')." et homebridge ".$ver);
		shell_exec('echo "'."== Jeedom ".jeedom::version().' '.__("sur",__FILE__).' '.trim(shell_exec("lsb_release -d -s")).'/'.trim(shell_exec('dpkg --print-architecture')).'/'.trim(shell_exec('arch')).'/'.trim(shell_exec('getconf LONG_BIT'))."bits aka '".jeedom::getHardwareName()."' ".__("avec nodeJS",__FILE__).' '.trim(shell_exec('node -v')).' '.__("et",__FILE__).' jsonrpc:'.config::byKey('api::core::jsonrpc::mode', 'core', 'enable').' '.__("et",__FILE__).' '.__CLASS__." (".$conf['version'].") ".$ver.' ('.__("avant",__FILE__).':'.config::byKey('previousVersion',__CLASS__,__("inconnu",__FILE__),true).')" >> '.log::getPathToLog(__CLASS__ . '_dep'));
		
		return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh' , 'log' => log::getPathToLog('HomeWizard' . '_dep'));
	}
	
	public static function reinstallNodeJS() { // Reinstall NODEJS from scratch (to use if there is errors in dependancy install)
		$thisPlugin = plugin::byId('HomeWizard');
		log::add('HomeWizard', 'info', __("Suppression du Code NodeJS", __FILE__));
		$cmd = system::getCmdSudo() . 'rm -rf ' . dirname(__FILE__) . '/../../resources/node_modules &>/dev/null';
		log::add('HomeWizard', 'info', __("Suppression de NodeJS", __FILE__));
		$cmd = system::getCmdSudo() . 'apt-get -y --purge autoremove npm';
		exec($cmd);
		$cmd = system::getCmdSudo() . 'apt-get -y --purge autoremove nodejs';
		exec($cmd);
		log::add('HomeWizard', 'info', __("Réinstallation des dependances", __FILE__));
		$thisPlugin->dependancy_install();
		return true;
	}
	

	public static function getFreePort() {
		$freePortFound = false;
		while (!$freePortFound) {
			$port = mt_rand(1024, 65535);
			exec('sudo fuser '.$port.'/tcp',$out,$return);
			if ($return==1) {
				$freePortFound = true;
			}
		}
		config::save('socketport',$port,'HomeWizard');
		return $port;
	}
	
	public static function delPIDfile(){
		$pid_file = jeedom::getTmpFolder('HomeWizard').'/daemon.pid';
		if (file_exists($pid_file)) {
			if (!@posix_getsid(trim(file_get_contents($pid_file)))) {
				shell_exec(system::getCmdSudo().'rm -rf '.$pid_file.' &>/dev/null');
			}
		}
	}

	public static function deamon_info() {
		$return = array();
		$return['log'] = 'HomeWizard_deamon';
		$return['state'] = 'nok';
		
		$pid = trim( shell_exec ('ps ax | grep "resources/HomeWizard.js" | grep -v "grep" | wc -l') );
		if ($pid != '' && $pid != '0') {
			$return['state'] = 'ok';
		}
		$return['launchable'] = 'ok';
		return $return;
	}
	
	public static function deamon_start() {
		self::deamon_stop();
		$deamon_info = self::deamon_info();
		if ($deamon_info['launchable'] != 'ok') {
			throw new Exception(__("Veuillez vérifier la configuration", __FILE__));
		}
		log::add('HomeWizard', 'info', __("Lancement du démon", __FILE__).' '.'HomeWizard');
		cache::set('HomeWizard::daemonStart', time());
		cache::set('HomeWizard::daemonStopping', 0);
		$socketport = self::getFreePort();
		$url  = network::getNetworkAccess('internal').'/core/api/jeeApi.php' ;

		$logLevel = log::convertLogLevel(log::getLogLevel('HomeWizard'));
		$debugMsg='';
		$inspect=' --inspect=0.0.0.0:9229 ';
		$inspect='';
		$deamonPath = realpath(dirname(__FILE__) . '/../../resources');
		$cmd = 'nice -n 19 node '.$inspect.' ' . $deamonPath . '/HomeWizard.js ' . $url . ' ' . jeedom::getApiKey('HomeWizard') .' '. $socketport . ' ' . jeedom::getTmpFolder('HomeWizard').'/daemon.pid'. ' ' . $logLevel;

		log::add('HomeWizard', 'debug', __("Lancement du démon", __FILE__).' '.'HomeWizard'.': '.$cmd);

		$result = exec((($logLevel=='debug')?$debugMsg:'NODE_ENV=production ').'nohup ' . $cmd . ' >> ' . log::getPathToLog('HomeWizard'.'_deamon') . ' 2>&1 &');
		if (strpos(strtolower($result), 'error') !== false || strpos(strtolower($result), 'traceback') !== false) {
			log::add('HomeWizard', 'error', $result);
			return false;
		}

		$i = 0;
		while ($i < 30) {
			$deamon_info = self::deamon_info();
			if ($deamon_info['state'] == 'ok') break;
			sleep(1);
			$i++;
		}
		if ($i >= 30) {
			log::add('HomeWizard', 'error', __("Impossible de lancer le démon HomeWizard, relancer le démon en debug et vérifiez le log", __FILE__), 'unableStartDeamon');
			return false;
		}
		message::removeAll('HomeWizard', 'unableStartDeamon');
		log::add('HomeWizard', 'info', __("Démon HomeWizard lancé", __FILE__));
		return true;

	}

	public static function deamon_stop() {
		$deamon_info = self::deamon_info();
		if ($deamon_info['state'] == 'ok') {
			log::add('HomeWizard', 'info', __("Arrêt du démon", __FILE__).' '.'HomeWizard');
			cache::set('HomeWizard::daemonStopping', 1);
			self::setAllOffline();
			$url="http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'HomeWizard')."/stop";
			$request_http = new com_http($url);
			$request_http->setNoReportError(true);
			$request_http->exec(11,1);
			for ($retry = 0; $retry < 5; $retry++) {
				if (self::deamon_info()['state'] != 'ok') {
					HomeWizard::delPIDfile();
					return true;
				}
				sleep(1);
			}
			
			$pid = exec("pgrep -fo 'resources/HomeWizard.js'");
			if($pid) {
				system::kill($pid);
				log::add('HomeWizard', 'info', __("Arrêt SIGTERM du démon", __FILE__).' '.'HomeWizard');
				for ($retry = 0; $retry < 3; $retry++) {
					if (self::deamon_info()['state'] != 'ok') { 
						HomeWizard::delPIDfile();
						return true;
					}
					sleep(1);
				}
			}
			
			$pid = exec("pgrep -fo 'resources/HomeWizard.js'");
			if($pid) {
				system::kill($pid,true);
				log::add('HomeWizard', 'info', __("Arrêt SIGKILL du démon", __FILE__).' '.'HomeWizard');
				HomeWizard::delPIDfile();
			}
		}
	}	
	
	/************************** API v2 **********************************/

	/* Equipements pour lesquels HomeWizard a publié l'API v2 */
	public static function v2SupportedTypes() {
		return ['HWE-P1', 'SDM230-wifi', 'HWE-KWH1', 'SDM630-wifi', 'HWE-KWH3', 'HWE-BAT'];
	}

	/* Equipements qui n'existent qu'en API v2 */
	public static function v2OnlyTypes() {
		return ['HWE-BAT'];
	}

	/* Famille d'équipement, pour l'illustration et la consigne du bouton dans l'assistant de migration */
	public static function v2Family($type) {
		switch($type) {
			case 'HWE-P1':
				return 'p1';
			case 'SDM230-wifi':
			case 'HWE-KWH1':
			case 'SDM630-wifi':
			case 'HWE-KWH3':
				return 'kwh';
			case 'HWE-BAT':
				return 'bat';
		}
		return '';
	}

	/*
	 * Illustration de l'assistant de migration : l'image officielle de l'équipement avec la position de son bouton
	 * (x et y en % de l'image), sur laquelle un doigt animé est superposé.
	 * Sans position connue (nouveau type) : l'image seule, sans animation.
	 */
	public static function v2PairingIllustration($type) {
		$buttons = [
			'HWE-P1'=>[50, 69], // le bouton est la face ronde, au centre de l'anneau lumineux
			'HWE-KWH1'=>[39.3, 61.3], // touche wifi
			'HWE-KWH3'=>[75.7, 36.7], // touche wifi
			'SDM230-wifi'=>[40.7, 61.7], // touche wifi
			'SDM630-wifi'=>[75.7, 36.7], // touche wifi
			'HWE-BAT'=>[41.7, 42.3], // bouton rond au dessus des leds
			// pas encore d'API v2, prêt pour plus tard
			'HWE-WTR'=>[67, 32.5],
			'HWE-SKT'=>[80, 42],
			'HWE-TMP'=>[56.5, 64], // bouton sur le côté
		];
		$image = 'plugins/HomeWizard/core/config/img/'.$type.'.png';
		$hold = (self::v2Family($type) == 'kwh');
		if (!file_exists(dirname(__FILE__).'/../../../../'.$image)) {
			return ['image'=>'plugins/HomeWizard/plugin_info/HomeWizard_icon.png', 'x'=>null, 'y'=>null, 'hold'=>$hold];
		}
		if (!isset($buttons[$type])) {
			return ['image'=>$image, 'x'=>null, 'y'=>null, 'hold'=>$hold];
		}
		return ['image'=>$image, 'x'=>$buttons[$type][0], 'y'=>$buttons[$type][1], 'hold'=>$hold];
	}

	/*
	 * Commandes qui ne seront plus mises à jour après la migration : l'API v2 des kWh meters ne donne plus
	 * les index par tarif (total_power_import_t1_kwh...), seulement les totaux.
	 */
	public function v2StaleCmds() {
		$return = [];
		if (self::v2Family($this->getConfiguration('type')) != 'kwh') return $return;
		foreach($this->getCmd('info') as $cmd) {
			if (preg_match('/^(total_power|energy)_(import|export)_t\d_kwh$/', $cmd->getLogicalId())) {
				$return[] = $cmd->getName();
			}
		}
		return $return;
	}

	/* Empreinte de la machine, vide si elle n'est pas disponible (certains conteneurs) */
	public static function v2MachineId() {
		$id = @file_get_contents('/etc/machine-id');
		$id = ($id === false) ? '' : trim($id);
		return ($id == '') ? '' : sha1($id);
	}

	/*
	 * Ce Jeedom est-il la copie d'un autre (sauvegarde restaurée sur une autre machine, clone de disque...) ?
	 * Les autorisations API v2 copiées restent valables sur les deux Jeedom, mais ils portaient le même nom d'utilisateur :
	 * refaire une autorisation sur l'un aurait invalidé celle de l'autre. On donne donc un nouveau nom à cette copie, on
	 * marque ses tokens comme partagés (ils ne seront pas supprimés de l'équipement par un retour à l'API v1) et on
	 * prévient l'utilisateur dans le centre de messages.
	 */
	public static function v2CheckClone($fromRestore = false) {
		$current = self::v2MachineId();
		$stored = config::byKey('v2MachineId', 'HomeWizard', '');
		$nbV2 = count(self::v2Devices());
		if ($current == '') {
			// impossible de savoir si la machine a changé : on prévient simplement après une restauration
			if ($fromRestore && $nbV2 > 0) {
				message::add('HomeWizard', __("Une sauvegarde vient d'être restaurée. Si elle provient d'un autre Jeedom qui reste en service, les deux partagent les mêmes autorisations API v2 sur vos équipements HomeWizard : elles fonctionnent sur les deux, mais refaire une autorisation (ou revenir à l'API v1) sur l'un coupera l'autre. Si c'est le même Jeedom, il n'y a rien à faire.", __FILE__), '', 'HomeWizard::restore');
			}
			return false;
		}
		if ($stored == $current) return false;
		config::save('v2MachineId', $current, 'HomeWizard');
		if ($stored == '') return false; // première exécution
		log::add('HomeWizard', 'info', __("Ce Jeedom est une copie d'un autre (machine différente) : nouveau nom d'autorisation API v2", __FILE__));
		config::save('v2UserName', self::v2NewUserName(), 'HomeWizard');
		foreach(eqLogic::byType('HomeWizard') as $eqp) {
			if ($eqp->getConfiguration('apiVersion') == 'v2' && $eqp->getConfiguration('token') != '') {
				$eqp->setConfiguration('tokenShared', 1);
				$eqp->save(true);
			}
		}
		if ($nbV2 > 0) {
			message::add('HomeWizard', __("Ce Jeedom est une copie d'un autre Jeedom (sauvegarde restaurée sur une autre machine). Il partage avec lui les autorisations API v2 de vos équipements HomeWizard : tout continue de fonctionner sur les deux. Ce Jeedom a reçu un nouveau nom d'autorisation : refaire une autorisation ici ne coupera plus l'autre Jeedom. Si l'autre Jeedom n'existe plus, il n'y a rien à faire.", __FILE__), '', 'HomeWizard::clone');
		}
		return true;
	}

	/* Appelé par Jeedom après la restauration d'une sauvegarde */
	public static function restore() {
		try {
			self::v2CheckClone(true);
		} catch (Throwable $e) {
			log::add('HomeWizard', 'warning', __("Vérification après restauration impossible", __FILE__).' : '.$e->getMessage());
		}
	}

	/*
	 * Nouveau nom d'utilisateur : jeedom-<nom du Jeedom>-<aléatoire>. Le nom du Jeedom permet de reconnaitre qui a créé
	 * l'utilisateur dans la liste des utilisateurs d'un équipement (l'API ne donne ni IP ni date, seulement ce nom).
	 * Caractères acceptés par l'API : lettres, chiffres, - et _ (40 caractères maximum après local/).
	 */
	public static function v2NewUserName() {
		$jeedomName = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '-', (string) config::byKey('name')));
		$jeedomName = trim(substr($jeedomName, 0, 20), '-');
		return 'jeedom-'.(($jeedomName != '') ? $jeedomName.'-' : '').bin2hex(random_bytes(4));
	}

	/* Utilisateurs (autorisations) enregistrés sur l'équipement, avec leur origine déduite du nom */
	public function v2Users() {
		$result = self::hwExecute('users', ['id'=>$this->getLogicalId()]);
		if (!is_array($result) || !isset($result['result'])) {
			throw new Exception(__("Pas de réponse du démon HomeWizard, consultez le log HomeWizard", __FILE__));
		}
		if ($result['result'] != 'ok') {
			throw new Exception($result['error'] ?? __("Erreur du démon", __FILE__));
		}
		return self::v2DescribeUsers($result['users'] ?? []);
	}

	public function v2DeleteUser($name) {
		$result = self::hwExecute('userDelete', ['id'=>$this->getLogicalId(), 'name'=>$name]);
		if (!is_array($result) || !isset($result['result'])) {
			throw new Exception(__("Pas de réponse du démon HomeWizard, consultez le log HomeWizard", __FILE__));
		}
		if ($result['result'] != 'ok') {
			throw new Exception($result['error'] ?? __("Erreur du démon", __FILE__));
		}
		return self::v2DescribeUsers($result['users'] ?? []);
	}

	public static function v2DescribeUsers($users) {
		$return = [];
		foreach($users as $user) {
			$name = $user['name'] ?? '';
			if (!empty($user['current'])) {
				$origin = 'current';
			} elseif (strpos($name, 'cloud/') === 0) {
				$origin = 'cloud';
			} elseif (strpos($name, 'local/jeedom') === 0) {
				$origin = 'jeedom';
			} else {
				$origin = 'local';
			}
			$return[] = ['name'=>$name, 'origin'=>$origin];
		}
		return $return;
	}

	/* Commandes action du groupe de Plug-In Batteries piloté par un compteur P1 ou un kWh Meter (API v2) */
	/* Types génériques posés à la création (ceux que Homebridge sait utiliser) : la puissance et la consommation totales, la charge de la batterie */
	public static function genericTypeFor($_logicalId) {
		$generic = [
			'active_power_w'=>'POWER', 'power_w'=>'POWER',
			'total_power_import_kwh'=>'CONSUMPTION', 'energy_import_kwh'=>'CONSUMPTION',
			'state_of_charge_pct'=>'BATTERY', // charge de la Plug-In Battery : niveau de batterie dans HomeKit
		];
		return $generic[$_logicalId] ?? '';
	}

	/* Une seule fois par équipement : types génériques des commandes créées avant qu'ils existent (sans toucher à ceux déjà choisis) */
	public function applyGenericTypes() {
		if ($this->getConfiguration('genericTypes') == 2) return;
		foreach($this->getCmd('info') as $cmd) {
			$generic = self::genericTypeFor($cmd->getLogicalId());
			if ($generic != '' && $cmd->getGeneric_type() == '') {
				$cmd->setGeneric_type($generic);
				$cmd->save(true);
			}
		}
		$this->setConfiguration('genericTypes', 2);
		$this->save(true);
	}

	/* Identifier et redémarrer : selon le type et l'API de l'équipement */
	public function canIdentify() {
		// API v2 : P1 Meter et Plug-In Battery (la librairie n'a pas identify() pour les kWh Meters)
		if ($this->getConfiguration('apiVersion') == 'v2') return in_array(self::v2Family($this->getConfiguration('type')), ['p1', 'bat']);
		return in_array($this->getConfiguration('type'), ['HWE-P1', 'HWE-SKT']);
	}

	public function canReboot() {
		return ($this->getConfiguration('apiVersion') == 'v2' && in_array(self::v2Family($this->getConfiguration('type')), ['p1', 'kwh']));
	}

	/* Commandes action des réglages système (cloud, led d'état), d'identification et de redémarrage */
	public function createSystemActions() {
		$actions = [];
		$writable = !in_array($this->getConfiguration('type'), self::v2OnlyTypes()); // cloud en lecture seule sur la Plug-In Battery
		if (is_object($this->getCmd(null, 'cloud_enabled')) && $writable) {
			$actions[] = ['action_cloud_on', __("Cloud activer", __FILE__), 'other', 'cloud_enabled', null, 1];
			$actions[] = ['action_cloud_off', __("Cloud désactiver", __FILE__), 'other', 'cloud_enabled', ['actionConfirm'=>1], 1];
		}
		// API v2, P1 Meter et kWh Meters : couper l'API v1 réserve l'équipement aux clients autorisés par le bouton
		if (is_object($this->getCmd(null, 'api_v1_enabled')) && $writable && $this->getConfiguration('apiVersion') == 'v2') {
			$actions[] = ['action_api_v1_on', __("API v1 activer", __FILE__), 'other', 'api_v1_enabled', null, 0];
			$actions[] = ['action_api_v1_off', __("API v1 désactiver", __FILE__), 'other', 'api_v1_enabled', ['actionConfirm'=>1], 0];
		}
		if (is_object($this->getCmd(null, 'status_led_brightness_pct'))) {
			$actions[] = ['action_status_led_brightness', __("Luminosité led", __FILE__), 'slider', 'status_led_brightness_pct', ['minValue'=>0, 'maxValue'=>100], 1];
		}
		if ($this->canIdentify()) $actions[] = ['action_identify', __("Identifier", __FILE__), 'other', '', null, 1];
		if ($this->canReboot()) $actions[] = ['action_reboot', __("Redémarrer", __FILE__), 'other', '', ['actionConfirm'=>1], 0];
		// Identifier créé sur un kWh Meter en API v2 par une version précédente : il ne peut pas fonctionner
		$identify = $this->getCmd('action', 'action_identify');
		if (is_object($identify) && !$this->canIdentify() && $this->getConfiguration('apiVersion') == 'v2') $identify->remove();
		foreach($actions as $action) {
			if (is_object($this->getCmd(null, $action[0]))) continue;
			$cmd = ['logicalId'=>$action[0], 'name'=>$action[1], 'type'=>'action', 'subtype'=>$action[2], 'isVisible'=>$action[5]];
			if ($action[3] != '') $cmd['value'] = $action[3];
			if ($action[4]) $cmd['configuration'] = $action[4];
			if ($action[2] == 'slider') $cmd['unite'] = '%';
			$this->createCmd($cmd);
		}
	}

	public function createBatteriesActions() {
		if (is_object($this->getCmd(null, 'action_batteries_charge_to_full_off'))) return;
		$actions = [
			['action_batteries_mode', __("Batteries mode", __FILE__), 'select', 'batteries_mode', ['listValue'=>'zero|'.__("Zéro (autoconsommation)", __FILE__).';to_full|'.__("Charge complète", __FILE__).';standby|'.__("Veille", __FILE__).';predictive|'.__("Prédictif", __FILE__)]],
			['action_batteries_charge_on', __("Batteries autoriser la charge", __FILE__), 'other', 'batteries_charge_allowed', null],
			['action_batteries_charge_off', __("Batteries interdire la charge", __FILE__), 'other', 'batteries_charge_allowed', null],
			['action_batteries_discharge_on', __("Batteries autoriser la décharge", __FILE__), 'other', 'batteries_discharge_allowed', null],
			['action_batteries_discharge_off', __("Batteries interdire la décharge", __FILE__), 'other', 'batteries_discharge_allowed', null],
			['action_batteries_charge_to_full_on', __("Batteries lancer la charge complète", __FILE__), 'other', 'batteries_charge_to_full', null],
			['action_batteries_charge_to_full_off', __("Batteries arrêter la charge complète", __FILE__), 'other', 'batteries_charge_to_full', null],
		];
		foreach($actions as $action) {
			if (is_object($this->getCmd(null, $action[0]))) continue;
			$cmd = ['logicalId'=>$action[0], 'name'=>$action[1], 'type'=>'action', 'subtype'=>$action[2], 'value'=>$action[3], 'isVisible'=>1];
			if ($action[4]) $cmd['configuration'] = $action[4];
			$this->createCmd($cmd);
		}
	}

	/* Nom de l'utilisateur créé sur les équipements. Unique par Jeedom : deux Jeedom avec le même nom s'invalideraient leur token */
	public static function v2UserName() {
		self::v2CheckClone();
		$name = config::byKey('v2UserName', 'HomeWizard', '');
		if ($name == '') {
			$name = self::v2NewUserName();
			config::save('v2UserName', $name, 'HomeWizard');
		}
		return $name;
	}

	/* Equipements migrés en API v2, envoyés au démon */
	public static function v2Devices() {
		$devices = [];
		foreach(eqLogic::byType('HomeWizard') as $eqp) {
			if ($eqp->getConfiguration('apiVersion') == 'v2' && $eqp->getConfiguration('token') != '') {
				$devices[$eqp->getLogicalId()] = ['apiVersion'=>'v2', 'token'=>$eqp->getConfiguration('token')];
			}
		}
		return $devices;
	}

	/* LogicalId d'une commande de l'API v1 dans l'API v2 (les données ont changé de nom) */
	public static function v2LogicalId($logicalId) {
		$specific = [
			'smr_version'=>'protocol_version',
			'active_tariff'=>'tariff',
			'active_tariff_txt'=>'tariff_txt',
			'active_power_average_w'=>'average_power_15m_w',
			'montly_power_peak_w'=>'monthly_power_peak_w',
			'montly_power_peak_timestamp'=>'monthly_power_peak_timestamp',
			'active_liter_lpm'=>'active_liter_lpm', // Watermeter : pas encore d'API v2
		];
		if (isset($specific[$logicalId])) return $specific[$logicalId];
		// total_power_import_t1_kwh => energy_import_t1_kwh
		if (strpos($logicalId, 'total_power_') === 0) return 'energy_'.substr($logicalId, 12);
		// active_power_l1_w => power_l1_w, active_voltage_v => voltage_v...
		if (strpos($logicalId, 'active_') === 0) return substr($logicalId, 7);
		return $logicalId;
	}

	/* L'inverse, pour le retour à l'API v1 */
	public static function v1LogicalId($logicalId) {
		$specific = [
			'protocol_version'=>'smr_version',
			'tariff'=>'active_tariff',
			'tariff_txt'=>'active_tariff_txt',
			'average_power_15m_w'=>'active_power_average_w',
			'monthly_power_peak_w'=>'montly_power_peak_w',
			'monthly_power_peak_timestamp'=>'montly_power_peak_timestamp',
			'power_on'=>'power_on', // Energy Socket : pas encore d'API v2
		];
		if (isset($specific[$logicalId])) return $specific[$logicalId];
		if (strpos($logicalId, 'energy_') === 0) return 'total_power_'.substr($logicalId, 7);
		// voltage_sag_* et voltage_swell_* ont le même nom dans les deux API
		if (preg_match('/^(power|voltage|current|frequency|apparent|reactive)_/', $logicalId) && !preg_match('/^voltage_(sag|swell)_/', $logicalId)) return 'active_'.$logicalId;
		return $logicalId;
	}

	/* Liste des équipements et de leur état de migration, pour l'assistant */
	public static function migrationList() {
		$return = [];
		foreach(eqLogic::byType('HomeWizard') as $eqp) {
			$type = $eqp->getConfiguration('type');
			$return[] = [
				'id'=>$eqp->getId(),
				'name'=>$eqp->getHumanName(),
				'type'=>$type,
				'image'=>$eqp->getImage(),
				'isEnable'=>$eqp->getIsEnable(),
				'family'=>self::v2Family($type),
				'state'=>$eqp->getMigrationState(),
				'canRevert'=>($eqp->getConfiguration('apiVersion') == 'v2' && !in_array($type, self::v2OnlyTypes())),
				'apiVersion'=>(in_array($type, self::v2OnlyTypes()))?'v2':$eqp->getConfiguration('apiVersion', 'v1'),
				'v2ApiVersion'=>$eqp->getConfiguration('v2ApiVersion'),
				'staleCmds'=>$eqp->v2StaleCmds(),
				'v2Only'=>in_array($type, self::v2OnlyTypes()),
				'authOnly'=>($eqp->getMigrationState() == 'pair')
			];
		}
		return $return;
	}

	/*
	 * v2 : déjà migré, invalid : migré mais le token n'est plus accepté, ready : migration possible,
	 * pair : rien à migrer (équipement uniquement API v2, ou nouvel équipement sans commande API v1), il attend seulement
	 *        l'autorisation par son bouton,
	 * firmware : type supporté mais l'équipement n'annonce pas (encore) l'API v2, unsupported : pas d'API v2 pour ce type
	 */
	public function getMigrationState() {
		$type = $this->getConfiguration('type');
		if ($this->getConfiguration('apiVersion') == 'v2') {
			return ($this->getConfiguration('tokenInvalid') == 1)?'invalid':'v2';
		}
		if (in_array($type, self::v2OnlyTypes())) return 'pair';
		if (!in_array($type, self::v2SupportedTypes())) return 'unsupported';
		if ($this->getConfiguration('v2Available') != 1) return 'firmware';
		// nouvel équipement jamais utilisé en API v1 (API locale non activée) : rien à migrer, on l'autorise directement en v2
		if (!$this->hasV1Cmds()) return 'pair';
		return 'ready';
	}

	/* L'équipement a des commandes de l'API v1, donc quelque chose à migrer */
	public function hasV1Cmds() {
		foreach($this->getCmd() as $cmd) {
			// active_tariff_txt vient du fichier de config du type, elle existe aussi sur un équipement tout neuf
			if ($cmd->getLogicalId() == 'active_tariff_txt') continue;
			if (self::v2LogicalId($cmd->getLogicalId()) != $cmd->getLogicalId()) return true;
		}
		return false;
	}

	/* Demande au démon d'attendre l'appui sur le bouton de l'équipement */
	public function startPairing() {
		$state = $this->getMigrationState();
		if (!in_array($state, ['ready', 'pair', 'invalid'])) {
			throw new Exception(__("L'API v2 n'est pas disponible pour cet équipement", __FILE__).' ('.$state.')');
		}
		$result = self::hwExecute('pair', ['id'=>$this->getLogicalId(), 'timeout'=>self::V2_PAIR_TIMEOUT]);
		if (!is_array($result) || !isset($result['result'])) {
			throw new Exception(__("Pas de réponse du démon HomeWizard, consultez le log HomeWizard", __FILE__));
		}
		if ($result['result'] != 'ok') {
			throw new Exception($result['error'] ?? __("Erreur du démon", __FILE__));
		}
		return [
			'timeout'=>$result['timeout'] ?? self::V2_PAIR_TIMEOUT,
			'family'=>self::v2Family($this->getConfiguration('type')),
			'illustration'=>self::v2PairingIllustration($this->getConfiguration('type')),
			'staleCmds'=>$this->v2StaleCmds()
		];
	}

	public function cancelPairing() {
		self::hwExecute('pairCancel', ['id'=>$this->getLogicalId()]);
		return true;
	}

	/* Renomme les logicalId (et les noms non personnalisés) des commandes, l'id donc l'historique et les scénarios sont conservés */
	public function renameCmdsForApi($toV2 = true) {
		$renamed = 0;
		foreach($this->getCmd() as $cmd) {
			$old = $cmd->getLogicalId();
			$new = ($toV2)?self::v2LogicalId($old):self::v1LogicalId($old);
			if ($new == $old) continue;
			if (is_object($this->getCmd(null, $new))) {
				log::add('HomeWizard', 'debug', __("Commande non renommée, existe déjà", __FILE__).' : '.$old.' => '.$new);
				continue;
			}
			log::add('HomeWizard', 'debug', __("Commande renommée", __FILE__).' : '.$old.' => '.$new);
			$cmd->setLogicalId($new);
			// le nom ne suit que si l'utilisateur ne l'a pas personnalisé
			if ($cmd->getName() == ucfirst($old) && !$this->cmdNameExist(ucfirst($new))) {
				$cmd->setName(ucfirst($new));
			}
			$cmd->save();
			$renamed++;
		}
		return $renamed;
	}

	public function migrateToV2($token, $userName = '') {
		$this->setConfiguration('apiVersion', 'v2');
		$this->setConfiguration('token', $token);
		$this->setConfiguration('v2UserName', $userName);
		$this->setConfiguration('tokenInvalid', 0);
		message::removeAll('HomeWizard', 'HomeWizard::tokenInvalid::'.$this->getId());
		$this->setConfiguration('tokenShared', 0);
		$this->save(true);
		$renamed = $this->renameCmdsForApi(true);
		$this->save(); // postSave : commandes additionnelles avec leur nom API v2
		return $renamed;
	}

	/* Retour à l'API v1 : l'utilisateur créé sur l'équipement est supprimé par le démon */
	public function revertToV1() {
		if (in_array($this->getConfiguration('type'), self::v2OnlyTypes())) {
			throw new Exception(__("Cet équipement n'existe qu'en API v2", __FILE__));
		}
		// token copié d'un autre Jeedom (voir v2CheckClone) : on ne supprime pas l'utilisateur sur l'équipement, l'autre s'en sert
		$result = self::hwExecute('unpair', ['id'=>$this->getLogicalId(), 'keepUser'=>($this->getConfiguration('tokenShared') == 1)?1:0]);
		if (!is_array($result) || !isset($result['result'])) {
			throw new Exception(__("Pas de réponse du démon HomeWizard, consultez le log HomeWizard", __FILE__));
		}
		$this->setConfiguration('apiVersion', 'v1');
		$this->setConfiguration('token', '');
		$this->setConfiguration('tokenInvalid', 0);
		message::removeAll('HomeWizard', 'HomeWizard::tokenInvalid::'.$this->getId());
		$this->setConfiguration('tokenShared', 0);
		$this->save(true);
		$renamed = $this->renameCmdsForApi(false);
		$this->save();
		return ['renamed'=>$renamed, 'userDeleted'=>$result['userDeleted'] ?? false];
	}

	public static function devicesParameters($device = '') {
		$path = dirname(__FILE__) . '/../config/devices/' . $device;
		if (!is_dir($path)) {
			return false;
		}
		try {
			$file = $path . '/' . $device.'.json';
			$content = file_get_contents($file);
			if($content) {
				$content=translate::exec($content,realpath($file));
			}
			$return = json_decode($content, true);
		} catch (Exception $e) {
			return false;
		}
		return $return;
	}

	public static function nameExists($name,$objectId=null) {
		$allHK = eqLogic::byObjectId($objectId,false);
		foreach ($allHK as $u) {
			if ($name == $u->getName()) return true;
		}
		return false;
	}
	public static function createEq($eq,$event=true) {
		$eqp = eqlogic::byLogicalId($eq['logicalId'],'HomeWizard');
		if (!is_object($eqp)){
			if($eq['name']) {
				if(HomeWizard::nameExists($eq['name'],null)) {
					$name=$eq['name'];
					$eq['name']=$eq['name'].'_'.$eq['logicalId'];
					log::add('HomeWizard', 'debug', __("Nom en double", __FILE__) .' '. $name .' '. __("renommé en", __FILE__) .' '. $eq['name']);
				}
				log::add('HomeWizard', 'info', __("Création de l'équipement", __FILE__) .' '. $eq['name'] .'('. $eq['logicalId'] . ')');
				$eqp = new HomeWizard();
				$eqp->setEqType_name('HomeWizard');
				$eqp->setLogicalId($eq['logicalId']);
				$eqp->setName($eq['name']);
				foreach($eq['configuration'] as $c => $v) {
					$eqp->setConfiguration($c, $v);
				}
				$eqp->setIsEnable($eq['enable']);
				$eqp->setIsVisible($eq['visible']);
				$eqp->save(true);
				if($event) event::add('HomeWizard::includeDevice');
			} else {
				log::add('HomeWizard', 'warning', __("Etrange, l'équipement", __FILE__) .' '. $eq['name'] .'('. $eq['logicalId'] .') '. __("n'a pas de nom... vérifiez qu'il est bien appairé !", __FILE__).' : '.json_encode($eq));
			}
		} else {
			if($eq['name']) {
				log::add('HomeWizard', 'info', __("Modification de l'équipement", __FILE__) .' '. $eq['name'] .'('. $eq['logicalId'] . ')');	
				foreach($eq['configuration'] as $c => $v) {
					$eqp->setConfiguration($c, $v);
				}
				$eqp->save();
			} else {
				log::add('HomeWizard', 'warning', __("Etrange, l'équipement", __FILE__) .' '. $eq['name'] .'('. $eq['logicalId'] .') '. __("n'a pas de nom... vérifiez qu'il est bien appairé !", __FILE__).' : '.json_encode($eq));
			}
		}
		return $eqp;
	}
	
	public static function getBranch() {
		$update=update::byTypeAndLogicalId('plugin','HomeWizard');
		$conf=$update->getConfiguration();
		return (($conf['version']=='beta')?'beta':'master');
	}
	
	
	public static function hwExecute($cmd,$params=[]) {
		if($cmd) {
			$url="http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'HomeWizard')."/";
			$url.=$cmd.((count($params))?"?".http_build_query($params):'');
			try {
				//$json = file_get_contents($url);
				$request_http = new com_http($url);
				$json=$request_http->exec(90,1);
			} catch( Exception $e) {
				log::add('HomeWizard','error',__("Problème de communication avec le démon à la demande", __FILE__).' '.$url. ' Exception : '.$e);
			} catch( Error $e) {
				log::add('HomeWizard','error',__("Problème de communication avec le démon à la demande", __FILE__).' '.$url. ' Error : '.$e);
			}
			if($json === '') log::add('HomeWizard','debug',__("Le démon n'a rien répondu à la demande", __FILE__).': '.$url);
			log::add('HomeWizard','debug',ucfirst($cmd).' '.__("brut",__FILE__).': '.$json);
			return json_decode($json, true);
		}
	}
	
	public static function hwConfig($setting, $params = []) {
		if ($setting) {
			$daemonState = HomeWizard::deamon_info();
			if ($daemonState['state'] != 'ok') {
				return false;
			}
			log::add('HomeWizard', 'debug', __("Configuration", __FILE__). ' ' . ucfirst($setting) . '...');
			$url = "http://" . config::byKey('internalAddr') . ":" . config::byKey('socketport', 'HomeWizard') . "/";
			$url .= 'config?setting=' . $setting . ((count($params)) ? "&" . http_build_query($params) : '');
			try {
				//$json = file_get_contents($url);
				$request_http = new com_http($url);
				$result = $request_http->exec(60, 1);
				$json = json_decode($result, true);
			} catch(Exception $e) {
				log::add('HomeWizard', 'warning', __("Problème de communication avec le démon à la demande", __FILE__).' :'. preg_replace('/token%5D=[^&]*/', 'token%5D=***', $url) . ' Exception : ' . $e);
			} catch(Error $e) {
				log::add('HomeWizard', 'warning', __("Problème de communication avec le démon à la demande", __FILE__).' :'. preg_replace('/token%5D=[^&]*/', 'token%5D=***', $url) . ' Error : ' . $e);
			}
			if ($json === null) {
				log::add('HomeWizard', 'debug', __("Le démon n'a rien répondu à la demande", __FILE__).' :'. preg_replace('/token%5D=[^&]*/', 'token%5D=***', $url));
			}
			if ($json['result'] != 'ok') {
				log::add('HomeWizard', 'debug', __("Erreur du démon", __FILE__) .': '. $json['msg']);
			}
			log::add('HomeWizard', 'debug', ucfirst($setting) . ' '. __("brut", __FILE__) . ': ' . $result);
			return $json['value'];
		}
	}
	
	/* Test réseau, utilisé par cron5 pour trancher quand un équipement est silencieux */
	public function pingHost($host, $timeout = null) {
		$timeoutValue="";
		if($timeout) {
			$timeoutValue=" timeout -s 9 --preserve-status --foreground ".$timeout."s ";
		}
		exec(system::getCmdSudo(). $timeoutValue . "ping -c1 " . $host, $output, $return_var);
		return ($return_var == 0);
	}	
	
	public function getImage(){
		$type=$this->getConfiguration('type');
		$catIcon = 'plugins/HomeWizard/core/config/img/'.$type.'.png';
		$base = dirname(__FILE__) . '/../../../../';
		
		if(file_exists($base.$catIcon)) return $catIcon;
		else return 'plugins/HomeWizard/plugin_info/HomeWizard_icon.png';
	}
	
	/* Widget du plugin : la mesure principale, les totaux, les phases, l'état, puis les commandes action visibles */
	public function toHtml($_version = 'dashboard') {
		$replace = $this->preToHtml($_version);
		if (!is_array($replace)) return $replace;
		$version = jeedom::versionAlias($_version);
		$cmds = [];
		foreach($this->getCmd('info') as $cmd) $cmds[$cmd->getLogicalId()] = $cmd;
		// première commande existante parmi les noms API v2 puis v1
		$find = function($ids) use ($cmds) {
			foreach((array)$ids as $id) {
				if (isset($cmds[$id])) return $cmds[$id];
			}
			return null;
		};
		$span = function($cmd, $kind = 'number', $decimals = 0) {
			$history = ($cmd->getIsHistorized() == 1)?' history cursor':'';
			return '<span class="hw-value'.$history.'" data-cmd_id="'.$cmd->getId().'" data-hw_cmd="'.$cmd->getId().'" data-hw_kind="'.$kind.'" data-hw_dec="'.$decimals.'" data-hw_value="'.htmlspecialchars((string)$cmd->execCmd(), ENT_QUOTES, 'UTF-8').'"></span>';
		};
		$tile = function($label, $content) {
			return '<div class="hw-tile"><div class="hw-tile-label">'.$label.'</div><div class="hw-tile-value">'.$content.'</div></div>';
		};
		$family = self::v2Family($this->getConfiguration('type'));

		// mesure principale
		$main = '';
		$power = $find(['power_w', 'active_power_w']);
		$water = $find('active_liter_lpm');
		if (is_object($power)) {
			$signed = in_array($family, ['p1', 'kwh']); // compteurs : négatif = injection
			$main = '<div class="hw-main-value">'.$span($power, $signed?'power':'number').' <small>W</small></div>';
			$main .= '<div class="hw-main-label">'.($signed?'<span class="hw-lbl-import">'.__("Soutirage", __FILE__).'</span><span class="hw-lbl-export">'.__("Injection", __FILE__).'</span>':__("Puissance", __FILE__)).'</div>';
		} elseif (is_object($water)) {
			$main = '<div class="hw-main-value">'.$span($water, 'number', 1).' <small>L/min</small></div><div class="hw-main-label">'.__("Débit", __FILE__).'</div>';
		} else {
			$main = '<div class="hw-main-label">'.__("En attente de valeurs", __FILE__).'</div>';
		}

		// prise : bouton marche/arrêt
		$switch = '';
		$state = $find('power_on');
		$on = $this->getCmd('action', 'action_power_on');
		$off = $this->getCmd('action', 'action_power_off');
		if (is_object($state) && is_object($on) && is_object($off)) {
			$switch = '<i class="fas fa-power-off hw-switch" data-hw_cmd="'.$state->getId().'" data-hw_kind="switch" data-hw_value="'.htmlspecialchars((string)$state->execCmd(), ENT_QUOTES, 'UTF-8').'" data-hw_on="'.$on->getId().'" data-hw_off="'.$off->getId().'" title="'.__("Allumer / Eteindre", __FILE__).'"></i>';
		}

		// tuiles
		$tiles = '';
		$cmd = $find('state_of_charge_pct');
		if (is_object($cmd)) $tiles .= $tile(__("Charge", __FILE__), $span($cmd).' <small>%</small><div class="hw-bar"><div data-hw_cmd="'.$cmd->getId().'" data-hw_kind="bar" data-hw_value="'.htmlspecialchars((string)$cmd->execCmd(), ENT_QUOTES, 'UTF-8').'"></div></div>');
		$cmd = $find('cycles');
		if (is_object($cmd)) $tiles .= $tile(__("Cycles", __FILE__), $span($cmd));
		$cmd = $find(['energy_import_kwh', 'total_power_import_kwh']);
		if (is_object($cmd)) $tiles .= $tile(($family == 'bat')?__("Energie chargée", __FILE__):__("Import", __FILE__), $span($cmd, 'number', 1).' <small>kWh</small>');
		$cmd = $find(['energy_export_kwh', 'total_power_export_kwh']);
		if (is_object($cmd)) $tiles .= $tile(($family == 'bat')?__("Energie restituée", __FILE__):__("Export", __FILE__), $span($cmd, 'number', 1).' <small>kWh</small>');
		$cmd = $find('total_gas_m3');
		if (is_object($cmd)) $tiles .= $tile(__("Gaz", __FILE__), $span($cmd, 'number', 2).' <small>m³</small>');
		$cmd = $find('total_liter_m3');
		if (is_object($cmd)) $tiles .= $tile(__("Eau", __FILE__), $span($cmd, 'number', 3).' <small>m³</small>');
		$cmd = $find(['tariff_txt', 'active_tariff_txt']);
		if (is_object($cmd) && is_object($find(['tariff', 'active_tariff']))) $tiles .= $tile(__("Tarif", __FILE__), $span($cmd, 'text'));
		$cmd = $find(['batteries_mode']);
		if (is_object($cmd)) $tiles .= $tile(__("Batteries", __FILE__), $span($cmd, 'text'));
		if ($tiles != '') $tiles = '<div class="hw-tiles">'.$tiles.'</div>';

		// phases (seulement s'il y en a plusieurs)
		$phases = '';
		if (is_object($find(['power_l2_w', 'active_power_l2_w']))) {
			foreach([1, 2, 3] as $phase) {
				$row = '';
				foreach([[['voltage_l'.$phase.'_v', 'active_voltage_l'.$phase.'_v'], 'V', 0], [['current_l'.$phase.'_a', 'active_current_l'.$phase.'_a'], 'A', 1], [['power_l'.$phase.'_w', 'active_power_l'.$phase.'_w'], 'W', 0]] as $col) {
					$cmd = $find($col[0]);
					$row .= '<td>'.(is_object($cmd)?$span($cmd, 'number', $col[2]).' '.$col[1]:'').'</td>';
				}
				$phases .= '<tr><td>L'.$phase.'</td>'.$row.'</tr>';
			}
			$phases = '<table class="hw-phases">'.$phases.'</table>';
		}

		// pied : en ligne, wifi, API
		$foot = '';
		$cmd = $find('online');
		if (is_object($cmd)) $foot .= '<span><span class="hw-dot" data-hw_cmd="'.$cmd->getId().'" data-hw_kind="online" data-hw_value="'.htmlspecialchars((string)$cmd->execCmd(), ENT_QUOTES, 'UTF-8').'"></span> <span class="hw-lbl-online">'.__("En ligne", __FILE__).'</span><span class="hw-lbl-offline">'.__("Hors ligne", __FILE__).'</span></span>';
		$cmd = $find('wifi_strength');
		if (is_object($cmd)) $foot .= '<span><i class="fas fa-wifi"></i> '.$span($cmd).' %</span>';
		// toutes les autres commandes info visibles : dans un popup ouvert par un bouton du pied
		preg_match_all('/data-hw_cmd="(\d+)"/', $main.$switch.$tiles.$phases.$foot, $shown);
		$others = '';
		foreach($this->getCmd('info', null, true) as $cmd) {
			if (in_array($cmd->getId(), $shown[1])) continue;
			$others .= '<tr><td>'.$cmd->getName().'</td><td>'.$span($cmd, ($cmd->getSubType() == 'numeric')?'number':'text', 2).' '.$cmd->getUnite().'</td></tr>';
		}
		// à droite : l'API utilisée, puis le bouton des autres valeurs
		$foot .= '<span class="hw-grow"></span><span>API '.(($this->getConfiguration('apiVersion') == 'v2')?'v2':'v1').'</span>';
		if ($others != '') {
			$foot .= '<span class="hw-more cursor" title="'.__("Autres valeurs", __FILE__).'"><i class="fas fa-list-ul"></i></span>';
			$others = '<div class="hw-popup"><table>'.$others.'</table></div>';
		}
		$replace['#hw_others#'] = $others;

		$replace['#hw_image#'] = $this->getImage();
		$replace['#hw_main#'] = $main;
		$replace['#hw_switch#'] = $switch;
		$replace['#hw_tiles#'] = $tiles;
		$replace['#hw_phases#'] = $phases;
		// les autres commandes action visibles (réglages, identifier, batteries...) gardent leur widget Jeedom
		$actions = '';
		foreach($this->getCmd('action', null, true) as $cmd) {
			if (in_array($cmd->getLogicalId(), ['action_power_on', 'action_power_off']) && $switch != '') continue;
			$actions .= $cmd->toHtml($_version, '');
		}
		if ($actions != '') $actions = '<div class="hw-actions">'.$actions.'</div>';

		$replace['#hw_actions#'] = $actions;
		$replace['#hw_foot#'] = $foot;
		return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'eqLogic', 'HomeWizard')));
	}

	public function createCmd($cmd) {
		$changed=false;
		$order=intval($this->getConfiguration('orderCmd',1));
		
		$isNew=false;
		
		$newCmd = $this->getCmd(null, $cmd['logicalId']);
		if (!is_object($newCmd)) {
			$isNew=true;
			log::add('HomeWizard','info',__("Création commande", __FILE__).' '.$order.':'.$cmd['name']);
			$newCmd = new HomeWizardCmd();
			$newCmd->setLogicalId($cmd['logicalId']);
			$newCmd->setIsVisible($cmd['isVisible']);
			if($cmd['type'] == 'info' && isset($cmd['isHistorized'])) $newCmd->setIsHistorized($cmd['isHistorized']);
			$newCmd->setOrder($order);
			
			$origName=$cmd['name'];
			$c=2;
			while($this->cmdNameExist($cmd['name'])) {
				$cmd['name']=$origName.'_'.$c;	
				$c++;
			}
			
			$newCmd->setName(__($cmd['name'], __FILE__));
			$newCmd->setEqLogic_id($this->getId());
		} else {
			log::add('HomeWizard','debug',__("Modification commande", __FILE__).' '.(($cmd['name'])?$cmd['name']:$cmd['logicalId']));
		}
		if(isset($cmd['unite'])) {
			$newCmd->setUnite($cmd['unite']);
		}
		if(isset($cmd['type'])) {
			$newCmd->setType($cmd['type']);
		}
		if(isset($cmd['configuration'])) {
			foreach($cmd['configuration'] as $configuration_type=>$configuration_value) {
				$newCmd->setConfiguration($configuration_type, $configuration_value);
			}
		} 
		if(isset($cmd['template'])) {
			foreach($cmd['template'] as $template_type=>$template_value) {
				$newCmd->setTemplate($template_type, $template_value);
			}

		} 
		if(isset($cmd['display'])) {
			foreach($cmd['display'] as $display_type=>$display_value) {
				if($display_type == 'generic_type' && method_exists($newCmd, 'setGeneric_type')) {
					$newCmd->setGeneric_type($display_value);
					continue;
				}
				$newCmd->setDisplay($display_type, $display_value);
			}
		}
		if(isset($cmd['subtype'])) {
			$newCmd->setSubType($cmd['subtype']);
		}
		if($cmd['type'] == 'action' && isset($cmd['value'])) {
			$linkStatus = $this->getCmd(null, $cmd['value']);
			if(is_object($linkStatus))
				$newCmd->setValue($linkStatus->getId());
		}
		$newCmd->save();
		if($isNew) {
			$this->setConfiguration('orderCmd',++$order);
			$this->save(true);			
		}
		return $isNew;
	}
	

	public function cmdNameExist($name) {
		$cmd=cmd::byEqLogicIdCmdName($this->getId(),$name);
		if(is_object($cmd)) { 
			return true;
		}
		return false;
	}	

	/* Suppression de l'équipement : on retire aussi l'autorisation de Jeedom sur l'équipement HomeWizard */
	public function preRemove() {
		if ($this->getConfiguration('apiVersion') != 'v2' || $this->getConfiguration('token') == '') return;
		try {
			if (self::deamon_info()['state'] != 'ok') {
				log::add('HomeWizard', 'warning', __("Démon arrêté : l'autorisation API v2 n'a pas pu être retirée de l'équipement", __FILE__).' '.$this->getName());
				return;
			}
			// token copié d'un autre Jeedom : l'autre s'en sert encore, on le laisse sur l'équipement
			$keepUser = ($this->getConfiguration('tokenShared') == 1)?1:0;
			$result = self::hwExecute('unpair', ['id'=>$this->getLogicalId(), 'keepUser'=>$keepUser, 'forget'=>1]);
			if ($keepUser) {
				log::add('HomeWizard', 'info', __("Autorisation API v2 partagée avec un autre Jeedom, laissée sur l'équipement", __FILE__).' '.$this->getName());
			} elseif (is_array($result) && !empty($result['userDeleted'])) {
				log::add('HomeWizard', 'info', __("Autorisation API v2 retirée de l'équipement", __FILE__).' '.$this->getName());
			} else {
				log::add('HomeWizard', 'warning', __("L'autorisation API v2 n'a pas pu être retirée de l'équipement (injoignable ?)", __FILE__).' '.$this->getName());
			}
		} catch (Throwable $e) {
			log::add('HomeWizard', 'warning', __("L'autorisation API v2 n'a pas pu être retirée de l'équipement", __FILE__).' '.$this->getName().' : '.$e->getMessage());
		}
	}

	public function postSave() {
		$online=$this->getCmd(null, 'online');
		if(!is_object($online)) {
			$cmd=[
				"name"=>"Online",
				"logicalId"=>'online',
				"type"=>"info",
				"subtype"=>"binary",
				"display"=> [
					"generic_type"=>"ONLINE",
					"forceReturnLineBefore"=>1
				],
				"isVisible"=>1,
				"isHistorized"=>1
			];
			$this->createCmd($cmd);	
		}
		$type = $this->getConfiguration('type');
		$eqConfig = 'plugins/HomeWizard/core/config/'.$type.'.json';
		$base = dirname(__FILE__) . '/../../../../';
		if(file_exists($base.$eqConfig)) {
			$content = file_get_contents($base.$eqConfig);
			if($content) {
				$content=translate::exec($content,realpath($base.$eqConfig));
			}
			$extraConfig=json_decode($content,true);
			if($this->getConfiguration('apiVersion') == 'v2') {
				// les fichiers de config utilisent les noms de l'API v1
				foreach(['modifyCommands','additionnalCommands'] as $section) {
					foreach($extraConfig[$section] as $i => $cmdConfig) {
						$extraConfig[$section][$i]['logicalId']=self::v2LogicalId($cmdConfig['logicalId']);
						if(isset($cmdConfig['value'])) $extraConfig[$section][$i]['value']=self::v2LogicalId($cmdConfig['value']);
					}
				}
			}
			foreach($extraConfig['modifyCommands'] as $cmdModif) {
				$existCmd = $this->getCmd(null, $cmdModif['logicalId']);
				if (is_object($existCmd)) {
					$this->createCmd($cmdModif);
				}
			}

			foreach($extraConfig['additionnalCommands'] as $cmdConfig) {
				$cmdToCreate=$this->getCmd(null, $cmdConfig['logicalId']);
				if(!is_object($cmdToCreate)) {
					$this->createCmd($cmdConfig);
				}
			}
		}
	}
}

class HomeWizardCmd extends cmd {
	/***************************Attributs*******************************/


	/*************************Methode static****************************/

	/***********************Methode d'instance**************************/

	public function execute($_options = null) {
		if ($this->getType() == '') {
			return '';
		}
		$eqLogic = $this->getEqlogic();
		$logical = $this->getLogicalId();
		$result=null;

		$eqLogical=$eqLogic->getLogicalId();

		
		$daemonState=HomeWizard::deamon_info();
		if($daemonState['state'] != 'ok') {
			log::add('HomeWizard','error',__("Le démon doit être démarré pour lancer la commande", __FILE__).' '.$logical);
			return false;
		}

		switch ($logical) {
			case 'action_power_on' :
				$result = HomeWizard::hwExecute('cmd',['cmd'=>'power_on','id'=>$eqLogical]);
				if($result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).': '.((is_array($result['error']))?$result['error']['response']:$result['error']));
					return false;
				}
			break;
			case 'action_power_off':
				$result = HomeWizard::hwExecute('cmd',['cmd'=>'power_off','id'=>$eqLogical]);
				if($result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).': '.((is_array($result['error']))?$result['error']['response']:$result['error']));
					return false;
				}
			break;
			case 'action_lock' :
				$result = HomeWizard::hwExecute('cmd',['cmd'=>'lock','id'=>$eqLogical]);
				if($result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).': '.((is_array($result['error']))?$result['error']['response']:$result['error']));
					return false;
				}
			break;
			case 'action_unlock':
				$result = HomeWizard::hwExecute('cmd',['cmd'=>'unlock','id'=>$eqLogical]);
				if($result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).': '.((is_array($result['error']))?$result['error']['response']:$result['error']));
					return false;
				}
			break;
			case 'action_brightness' :
				$result = HomeWizard::hwExecute('cmd',['cmd'=>'brightness','id'=>$eqLogical,'val'=>$_options['slider']]);
				if($result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).': '.((is_array($result['error']))?$result['error']['response']:$result['error']));
					return false;
				}
			break;
			case 'action_identify' :
				$result = HomeWizard::hwExecute('cmd',['cmd'=>'identify','id'=>$eqLogical]);
				if($result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).': '.((is_array($result['error']))?$result['error']['response']:$result['error']));
					return false;
				}
			break;
			case 'action_reboot':
			case 'action_cloud_on':
			case 'action_cloud_off':
			case 'action_api_v1_on':
			case 'action_api_v1_off':
			case 'action_status_led_brightness':
				// réglages système de l'équipement
				if ($logical == 'action_reboot') {
					$request = ['cmd'=>'reboot', 'id'=>$eqLogical];
				} elseif ($logical == 'action_status_led_brightness') {
					$request = ['cmd'=>'led', 'id'=>$eqLogical, 'val'=>intval($_options['slider'] ?? 0)];
				} elseif (strpos($logical, 'action_api_v1_') === 0) {
					$request = ['cmd'=>'apiv1', 'id'=>$eqLogical, 'val'=>($logical == 'action_api_v1_on')?1:0];
				} else {
					$request = ['cmd'=>'cloud', 'id'=>$eqLogical, 'val'=>($logical == 'action_cloud_on')?1:0];
				}
				$result = HomeWizard::hwExecute('cmd',$request);
				if(!is_array($result) || $result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).': '.((is_array($result) && isset($result['error']))?json_encode($result['error']):''));
					return false;
				}
			break;
			case 'action_batteries_mode':
			case 'action_batteries_charge_on':
			case 'action_batteries_charge_off':
			case 'action_batteries_discharge_on':
			case 'action_batteries_discharge_off':
			case 'action_batteries_charge_to_full_on':
			case 'action_batteries_charge_to_full_off':
				// groupe de Plug-In Batteries piloté par ce compteur
				$on = (substr($logical, -3) == '_on')?1:0;
				if ($logical == 'action_batteries_mode') {
					$request = ['cmd'=>'batteries_mode', 'id'=>$eqLogical, 'val'=>$_options['select'] ?? ''];
				} elseif (strpos($logical, 'action_batteries_charge_to_full') === 0) {
					$request = ['cmd'=>'batteries_charge_to_full', 'id'=>$eqLogical, 'val'=>$on];
				} else {
					$request = ['cmd'=>'batteries_permission', 'id'=>$eqLogical, 'val'=>(strpos($logical, '_discharge_') !== false)?'discharge_allowed':'charge_allowed', 'state'=>$on];
				}
				$result = HomeWizard::hwExecute('cmd',$request);
				if(!is_array($result) || $result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).': '.((is_array($result) && isset($result['error']))?json_encode($result['error']):''));
					return false;
				}
			break;
			default:
				log::add('HomeWizard','error',__("Commande inconnue", __FILE__).': '.$logical);
				return false;
			break;
		}
		log::add('HomeWizard','info',__("Résultat de la commande OK", __FILE__));
		return true;
		//log::add('HomeWizard','debug',$logical);

		//$eqLogic->getHomeWizardInfo(null,null,$hasToCheckPlaying);
	}

	/************************Getteur Setteur****************************/
}
?>
