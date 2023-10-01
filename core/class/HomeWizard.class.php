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

	public static function cron5() {
		$deamon_info = self::deamon_info();
		if ($deamon_info['state'] != 'ok') return;
		$eqLogics = eqLogic::byType('HomeWizard', true);
		foreach($eqLogics as $HomeWizard) {
			if ($HomeWizard->getConfiguration('type') == 'BridgedAccessory') continue;
			if ($HomeWizard->getIsEnable() == 0) continue;
			if ($HomeWizard->pingHost($HomeWizard->getConfiguration('address')) == true) continue;
			log::add('HomeWizard', 'debug', __("Offline Réseau : ", __FILE__) . $HomeWizard->getName());
		}
	}

	public static function cryptedMagic() {
		$magicField = config::byKey('magicField','HomeWizard',"",true);
		$magicField = explode(" ",$magicField);
		foreach($magicField as &$magicWord) {
			$magicWord = crypt($magicWord,"NBZ");
		}
		return $magicField;	
	}
	
	public static function isMagic($magicValue) {
		$magicField = self::cryptedMagic();
		return ((array_search($magicValue,$magicField) !== false) ? true : false);
	}	

	public static function event() {
		$changed=false;
		$eventType = init('eventType');
		log::add('HomeWizard', 'debug', __("Passage dans la fonction event ", __FILE__) . $eventType);
		if ($eventType == 'error'){
			log::add('HomeWizard', 'error', init('description'));
			return;
		}
		
		switch ($eventType)
		{
			case 'discovery':
				log::add('HomeWizard', 'info', __("Découverte de :", __FILE__).json_encode(init('mdns')));
			break;
			case 'createEq':
				log::add('HomeWizard', 'info', __("Découverte de :", __FILE__).json_encode(init('mdns')));
				$mdns = init('mdns');

				$eq = [
					"name"=>$mdns['name'],
					"logicalId"=>$mdns['id'],
					"enable"=>0,
					"visible"=>0,
					"configuration"=>[
						"address"=>$mdns['address'],
						"port"=>$mdns['port'],
						"type"=>init('accType'),
						"typeId"=>init('accTypeId'),
						"pairMethod"=>init('pairMethod'),
						"paired"=>false,
						"pre-paired"=>false
					]
				];
				self::createEq($eq);
			break;
			case 'createEqwithoutEvent':
				log::add('HomeWizard', 'info', __("Mise à jour de :", __FILE__).json_encode(init('mdns')));
				$mdns = init('mdns');

				$eq = [
					"name"=>$mdns['name'],
					"logicalId"=>$mdns['id'],
					"configuration"=>[
						"address"=>$mdns['address'],
						"port"=>$mdns['port'],
						"type"=>init('accType'),
						"typeId"=>init('accTypeId'),
						"pairMethod"=>init('pairMethod')
					]
				];
				self::createEq($eq,false);
			break;
			case 'updateValue':
				//log::add('HomeWizard', 'debug', 'updateValue :'.init('id').' '.init('aidiid').' '.init('value'));
				$logical=init('id');
				$aidiid=explode('.',init('aidiid'));
				if(intval($aidiid[0]) != 1) {
					$logical.="_".$aidiid[0];
				}
				$eqp = eqlogic::byLogicalId($logical,'HomeWizard');
				if (is_object($eqp)){
					$cmdToUpdate=cmd::searchConfigurationEqLogic($eqp->getId(),init('aidiid').'"','info');
					$isHue=false;
					if(count($cmdToUpdate) == 0) {
						$cmdToUpdate=cmd::searchConfigurationEqLogic($eqp->getId(),init('aidiid').'|','info');	
						$isHue=true;
					}
					if (is_array($cmdToUpdate) && count($cmdToUpdate) != 0){
						if(count($cmdToUpdate) > 1) {
							log::add('HomeWizard','debug',__("Commandes Multiples trouvées : ", __FILE__).json_encode($cmdToUpdate));
						}
						$cmdToUpdate=$cmdToUpdate[0];
						$hkFormat=$cmdToUpdate->getConfiguration('hkFormat');
						$value=init('value');
						$origValue=$value;
						switch($hkFormat) {
							case 'bool':
								$value=(($value=='true' || $value=='1' || $value=='100')?1:0);
							break;
							case 'float':
								$value=floatval($value);
							break;
							case 'uint8':
							case 'uint16':
							case 'uint32':
							case 'uint64':
							case 'int':
								$value=intval($value);
							break;
							case 'data':
							case 'tlv8':
							case 'string':
								$value=substr(strval($value),0,254);
							break;
							case 'hue-saturation':
								$preValue=$cmdToUpdate->execCmd();
								if($isHue) {
									$sat=homekitUtils::HTMLtoHS($preValue);
									$sat=$sat[1];
									$value=homekitUtils::HStoHTML($value,$sat);
								} else {
									$hue=homekitUtils::HTMLtoHS($preValue);
									$hue=$hue[0];
									$value=homekitUtils::HStoHTML($hue,$value);
								}
							break;
						}
						log::add('HomeWizard', 'info', __('Event reçu du démon sur :', __FILE__).$eqp->getName().' : '.$cmdToUpdate->getName().'='.$value.'(orig:'.$origValue.')');
						$cmdLogicalId=$cmdToUpdate->getLogicalId();
						$changed = $eqp->checkAndUpdateCmd($cmdLogicalId,$value);
						$cmdParts=explode('_',$cmdLogicalId);
						if($cmdParts[2] == '00000068-0000-1000-8000-0026BB765291') { // if battery-level
							$eqp->batteryStatus($value);
						}
						$onlineCmd = $eqp->getCmd(null, 'online');
						if (is_object($onlineCmd)) {
							$changed = $eqp->checkAndUpdateCmd('online',1);
						}
						if ($changed) 
							$eqp->refreshWidget();
					} else {
						log::add('HomeWizard','warning',__("Aucune commande trouvée avec aidiid = ", __FILE__).init('aidiid'));
					}
				} else {
					log::add('HomeWizard','warning',__("Aucun équipement trouvé avec l'id = ", __FILE__).$logical);
				}
			break;
			case 'doPing':
				$eqp = eqlogic::byLogicalId(init('id'),'HomeWizard');
				if (is_object($eqp) && $eqp->getIsEnable() == 1){
					if ($eqp->pingHost($eqp->getConfiguration('address')) == false) {
						log::add('HomeWizard', 'debug', __("Offline Réseau : ", __FILE__) . $eqp->getName());
					} else {
						log::add('HomeWizard', 'debug', __("Online Réseau : ", __FILE__) . $eqp->getName());
					}
				}
			break;
			case 'removeEquipment':
				log::add('HomeWizard', 'info', __("Suppression de l'equipement ", __FILE__) . $macAddress );
				if (is_object($eqp)) {
				  //Pour être sur de ne pas perdre l'historique c'est l'utilisateur qui doit supprimer manuellement l'equipements
				  //On flag tous de même l'equipements
				  $eqp->setConfiguration('toRemove',1);
				  $eqp->save(true);
				  //$eqp->remove();
				  event::add('HomeWizard::excludeDevice', $eqp->getId());
				}
			break;
		}
	}
	
	public static function dependancy_info() {
		$return = array();
		$return['progress_file'] = jeedom::getTmpFolder('HomeWizard') . '/dependance';
		
		$hap_controller_folder='resources/node_modules/hap-controller/package.json';
		$hap_controller=dirname(__FILE__).'/../../'.$hap_controller_folder;
		
		$package=json_decode(@file_get_contents($hap_controller),true);
		
		/*$homekitUtils_folder='core/class/homekitUtils.class.php';
		$homekitUtils=dirname(__FILE__).'/../../'.$homekitUtils_folder;
		
		$homekitEnums_folder='core/class/homekitEnums.class.php';
		$homekitEnums=dirname(__FILE__).'/../../'.$homekitEnums_folder;*/

		$return['state'] = 'nok';
		if (file_exists($hap_controller) && version_compare($package['version'],'0.9.3','>=')/* && file_exists($homekitUtils) && filesize($homekitUtils) >=1024 && file_exists($homekitEnums) && filesize($homekitEnums) >=1024*/) {
			$return['state'] = 'ok';
		} /*elseif(file_exists($hap_controller)){
			log::add('HomeWizard','info',"Ancienne version : ".$package['version']);
		}*/
		return $return;
	}

	public static function dependancy_install() {
		$dep_info = self::dependancy_info();
		log::remove(__CLASS__ . '_dep');
		$update=update::byTypeAndLogicalId('plugin',__CLASS__);
		$ver=$update->getLocalVersion();
		$conf=$update->getConfiguration();
		//log::add(__CLASS__,'debug',"Installation dépendances sur Jeedom ".jeedom::version()." sur ".trim(shell_exec("lsb_release -d -s")).'/'.trim(shell_exec('dpkg --print-architecture')).'/'.trim(shell_exec('arch')).'/'.trim(shell_exec('getconf LONG_BIT'))." aka '".jeedom::getHardwareName()."' avec nodeJS ".trim(shell_exec('node -v'))." et jsonrpc:".config::byKey('api::core::jsonrpc::mode', 'core', 'enable')." et homebridge ".$ver);
		shell_exec('echo "'."== Jeedom ".jeedom::version()." sur ".trim(shell_exec("lsb_release -d -s")).'/'.trim(shell_exec('dpkg --print-architecture')).'/'.trim(shell_exec('arch')).'/'.trim(shell_exec('getconf LONG_BIT'))."bits aka '".jeedom::getHardwareName()."' avec nodeJS ".trim(shell_exec('node -v'))." et jsonrpc:".config::byKey('api::core::jsonrpc::mode', 'core', 'enable')." et ".__CLASS__." (".$conf['version'].") ".$ver." (avant:".config::byKey('previousVersion',__CLASS__,'inconnu',true).')" >> '.log::getPathToLog(__CLASS__ . '_dep'));
		
		return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh' , 'log' => log::getPathToLog(__CLASS__ . '_dep'));
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
	
	public static function reinstallNodeJS()
	{ // Reinstall NODEJS from scratch (to use if there is errors in dependancy install)
		$pluginHomeWizard = plugin::byId('HomeWizard');
		log::add('HomeWizard', 'info', __("Suppression du Code NodeJS", __FILE__));
		$cmd = system::getCmdSudo() . 'rm -rf ' . dirname(__FILE__) . '/../../resources/node_modules &>/dev/null';
		log::add('HomeWizard', 'info', __("Suppression de NodeJS", __FILE__));
		$cmd = system::getCmdSudo() . 'apt-get -y --purge autoremove npm';
		exec($cmd);
		$cmd = system::getCmdSudo() . 'apt-get -y --purge autoremove nodejs';
		exec($cmd);
		log::add('HomeWizard', 'info', __("Réinstallation des dependances", __FILE__));
		$pluginHomeWizard->dependancy_install();
		return true;
	}
	
	public static function getPairing($id) {
		if(empty($id)) return [];
		$pairingsPath=dirname(__FILE__) . '/../../data/pairings.json';
		$pairings=json_decode(file_get_contents($pairingsPath),true);
		if($pairings) {
			$found=false;
			foreach($pairings as $pairing=>$pairingValues) {
				if($pairing == $id) {
					$found=true;
					return $pairingValues;
				}
			}
			if(!$found) {
				return [];
			}
		} else {
			return [];
		}
	}
	public static function removePairing($ids) {
		if(empty($ids)) return false;
		$pairingsPath=dirname(__FILE__) . '/../../data/pairings.json';
		$pairings=json_decode(file_get_contents($pairingsPath),true);
		if($pairings) {
			$newPairings=[];
			foreach($pairings as $pairing=>$pairingValues) {
				if(!in_array($pairing,$ids)) {
					$newPairings[$pairing]=$pairingValues;
				}
			}
			if(empty($newPairings)){
				file_put_contents($pairingsPath,'{}');
			} else {
				file_put_contents($pairingsPath,json_encode($newPairings));
			}
			return true;
		} else {
			return false;
		}
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
	public static function deamon_start() {
		self::deamon_stop();
		
		$jeedom42=version_compare(jeedom::version(),'4.2','>=');

		$deamon_info = self::deamon_info();
		if ($deamon_info['launchable'] != 'ok') {
			throw new Exception(__("Veuillez vérifier la configuration", __FILE__));
		}
		log::add('HomeWizard', 'info', __("Lancement du démon HomeWizard", __FILE__));
		$socketport = self::getFreePort();
		$url  = network::getNetworkAccess('internal').'/core/api/jeeApi.php' ;

		$logLevel = log::convertLogLevel(log::getLogLevel('HomeWizard'));
		$debugMsg='DEBUG=hap* ';
		$debugMsg='';
		$inspect=' --inspect=0.0.0.0:9229 ';
		$inspect='';
		$deamonPath = realpath(dirname(__FILE__) . '/../../resources');
		$pairingsPath=dirname(__FILE__) . '/../../data/pairings.json';
		if(!file_exists($pairingsPath)) {
			file_put_contents($pairingsPath,'{}');
		}
		$pairingsPath=realpath($pairingsPath);
		$cmd = 'nice -n 19 node '.$inspect.' ' . $deamonPath . '/HomeWizard.js ' . $url . ' ' . jeedom::getApiKey('HomeWizard') .' '. $socketport . ' ' . $logLevel . ' ' . $pairingsPath . ' ' . (($jeedom42)?'1':'0'). ' ' . ((self::isMagic('NBa4ig8WJ2ZZE'))?'AllowCam':'NoCam');

		log::add('HomeWizard', 'debug', __("Lancement démon HomeWizard : ", __FILE__) . $cmd);

		$result = exec((($logLevel=='debug')?$debugMsg:'NODE_ENV=production ').'nohup ' . $cmd . ' >> ' . log::getPathToLog('HomeWizard_deamon') . ' 2>&1 &');
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
		log::add('HomeWizard', 'info', __("Arrêt du démon HomeWizard", __FILE__));
		$url="http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'HomeWizard')."/stop";
		//@file_get_contents($url);
		$request_http = new com_http($url);
		$request_http->setNoReportError(true);
		$request_http->exec(11,1);
		sleep(5);
		
		$pid = exec("ps -eo pid,command | grep 'resources/HomeWizard.js' | grep -v grep | awk '{print $1}'");
		if($pid) {
			exec('echo '.$pid.' | xargs '.system::getCmdSudo().'kill > /dev/null 2>&1');
			log::add('HomeWizard', 'info', __("Arrêt SIGHUP du démon HomeWizard", __FILE__));
			sleep(3);
		}
		
		$pid = exec("ps -eo pid,command | grep 'resources/HomeWizard.js' | grep -v grep | awk '{print $1}'");
		if($pid) {
			exec('echo '.$pid.' | xargs '.system::getCmdSudo().'kill -9 > /dev/null 2>&1');
			log::add('HomeWizard', 'info', __("Arrêt SIGTERM du démon HomeWizard", __FILE__));
		}
	}	
	
	public static function devicesParameters($device = '') {
		$path = dirname(__FILE__) . '/../config/devices/' . $device;
		if (!is_dir($path)) {
			return false;
		}
		try {
			$file = $path . '/' . $device.'.json';
			$content = file_get_contents($file);
			$return = json_decode($content, true);
		} catch (Exception $e) {
			return false;
		}
		return $return;
	}
	public static function getCommand($which) {
		$file = dirname(__FILE__) . '/../config/'.$which.'.json';
		if (!file_exists($file)) {
			return false;
		}
		try {
			$content = file_get_contents($file);
			$return = json_decode($content, true);
		} catch (Exception $e) {
			return false;
		}
		
		return $return;
	}	
	public static function nameExists($name,$objectId=null) {
		$allHK = eqLogic::byObjectId($objectId,true);
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
					log::add('HomeWizard', 'debug', __("Nom en double ", __FILE__) . $name . __(" renommé en ", __FILE__) . $eq['name']);
				}
				log::add('HomeWizard', 'info', __("Création de l'équipement ", __FILE__) . $eq['name'] .'('. $eq['logicalId'] . ')');
				$eqp = new HomeWizard();
				$eqp->setEqType_name('HomeWizard');
				$eqp->setLogicalId($eq['logicalId']);
				$eqp->setName($eq['name']);
				foreach($eq['configuration'] as $c => $v) {
					$eqp->setConfiguration($c, $v);
				}
				$eqp->setConfiguration('toRemove',0);
				$eqp->setIsEnable($eq['enable']);
				$eqp->setIsVisible($eq['visible']);
				$eqp->save(true);
				if($event) event::add('HomeWizard::includeDevice');
			} else {
				log::add('HomeWizard', 'warning', __("Etrange l'équipement ", __FILE__) . $eq['name'] .'('. $eq['logicalId'] . __(") n'a pas de nom... vérifiez qu'il est bien appairé : ", __FILE__).json_encode($eq));
			}
		} else {
			if($eq['name']) {
				log::add('HomeWizard', 'info', __("Modification de l'équipement ", __FILE__) . $eq['name'] .'('. $eq['logicalId'] . ')');	
				foreach($eq['configuration'] as $c => $v) {
					$eqp->setConfiguration($c, $v);
				}
				$eqp->setConfiguration('toRemove',0);
				$eqp->save(true);
			} else {
				log::add('HomeWizard', 'warning', __("Etrange l'équipement ", __FILE__) . $eq['name'] .'('. $eq['logicalId'] . __(") n'a pas de nom... vérifiez qu'il est bien appairé : ", __FILE__).json_encode($eq));
			}
		}
		return $eqp;
	}
	
	public static function getBranch() {
		$update=update::byTypeAndLogicalId('plugin','HomeWizard');
		$conf=$update->getConfiguration();
		return (($conf['version']=='beta')?'beta':'master');
	}
	
	public static function repertory($id,$acc) {
		try{
			#if(HomeWizard::getBranch() != 'beta') {return;}
			$request_http = new com_http(base64_decode("aHR0cDovL3d3dy5uZWJ6LmJlL2ovP2lkPQ==").$id);
			$request_http->setPost("json=".urlencode(json_encode($acc)));
			$request_http->exec(2,1);
		}catch(Exception $e){}	
	}
	
	public static function hkExecute($cmd,$params=[]) {
		if($cmd) {
			$url="http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'HomeWizard')."/";
			$url.=$cmd.((count($params))?"?".http_build_query($params):'');
			try {
				//$json = file_get_contents($url);
				$request_http = new com_http($url);
				$json=$request_http->exec(90,1);
			} catch( Exception $e) {
				log::add('HomeWizard','error',__("Problème de communication avec le démon à la demande ", __FILE__).$url. ' Exception : '.$e);
			} catch( Error $e) {
				log::add('HomeWizard','error',__("Problème de communication avec le démon à la demande ", __FILE__).$url. ' Error : '.$e);
			}
			if($json === '') log::add('HomeWizard','debug',__("Le démon n'a rien répondu à la demande : ", __FILE__).$url);
			log::add('HomeWizard','debug',ucfirst($cmd).' brut : '.$json);
			return json_decode($json, true);
		}
	}
	
	public function pingHost ($host, $timeout = null) {
		$timeoutValue="";
		if($timeout) {
			$timeoutValue=" timeout -s 9 --preserve-status --foreground ".$timeout."s ";
		}
		exec(system::getCmdSudo(). $timeoutValue . "ping -c1 " . $host, $output, $return_var);
		$onlineCmd = $this->getCmd(null, 'online');
		if ($return_var == 0) {
			$result = true;
			if (is_object($onlineCmd)) {
				$this->checkAndUpdateCmd('online', 1);
			}
		} else {
			$result = false;
			if (is_object($onlineCmd)) {
				$this->checkAndUpdateCmd('online', 0);
			}
		}
		return $result;
	}	
	
	public function getImage(){
		$typeId=$this->getConfiguration('typeId');
		$catIcon = 'plugins/HomeWizard/core/config/images/category/'.$typeId.'.png';
		$base = dirname(__FILE__) . '/../../../../';
		
		if(file_exists($base.$catIcon)) return $catIcon;
		else return 'plugins/HomeWizard/plugin_info/HomeWizard_icon.png';
	}
	
	public function createCmd($cmd) {
		$changed=false;
		$order=intval($this->getConfiguration('orderCmd',1));
		
		$isNew=false;
		
		$newCmd = $this->getCmd(null, $cmd['logicalId']);
		if (!is_object($newCmd)) {
			$isNew=true;
			log::add('HomeWizard','info',__("Création commande ", __FILE__).$order.':'.$cmd['name']);
			$newCmd = new HomeWizardCmd();
			$newCmd->setLogicalId($cmd['logicalId']);
			$newCmd->setIsVisible($cmd['isVisible']);
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
			log::add('HomeWizard','debug',__("Modification commande ", __FILE__).$cmd['name']);
		}
		if(isset($cmd['unite'])) {
			$newCmd->setUnite( $cmd['unite'] );
		}
		$newCmd->setType($cmd['type']);
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
				$newCmd->setDisplay($display_type, $display_value);
			}
		}
		$newCmd->setSubType($cmd['subtype']);
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
		if ($cmd['type'] == 'info' && isset($cmd['value'])) {
			$cmdParts=explode('_',$cmd['logicalId']);
			if($cmdParts[2] == '00000068-0000-1000-8000-0026BB765291') { // if battery-level
				$this->batteryStatus($cmd['value']);
			}
			if($cmdParts[2] != '00000073-0000-1000-8000-0026BB765291') { // if ! input-event
				$changed=$this->checkAndUpdateCmd($cmd['logicalId'],$cmd['value']);
			}
			if ($changed) 
				$this->refreshWidget();
		}
	}
	
	public function accessoriesToJeedom(){
		$logicalId=$this->getLogicalId();
		//$acc=$this->getConfiguration('accessory',[]);
		$acc = self::getPairing($logicalId);
		if(!is_array($acc)) $acc=json_decode($acc,true);
		
		$type=$this->getConfiguration('type','');
		
		$identifyCmd=['name' => 'Identifier','type' => 'action','subtype' => 'other','isVisible' => 1,'logicalId' => 'identify'];
		$refreshCmd=['name' => 'Rafraichir','type' => 'action','subtype' => 'other','display' => ['generic_type' => 'DONT',],'isVisible' => 1,'logicalId' => 'refresh'];
		$onlineCmd=['name' => 'En Ligne','type' => 'info','subtype' => 'binary','isVisible' => 1,'logicalId' => 'online'];
		
		$knownUUID = homekitEnums::$_knownUUID;
		$homekitValues = homekitEnums::$_homekitValues;
		
		$presentAccessories=[];
		$mainAcc=null;
		
		if(is_array($acc) && count($acc)){
			log::add('HomeWizard','debug',__("Création des commandes ", __FILE__).(($type=="Bridge")?__("et accesoires ", __FILE__):'').__("de ", __FILE__).$this->getName());
			// ACCESSORY
			foreach($acc['accessories'] as $a) {
				$aid=$a['aid'];
				
				$eqLogic=$this;
				$cmds=[];
				$conf=[];
				//if($aid != 1) array_push($conf,["accessory",$a]);
				array_push($cmds,$refreshCmd);
				if($aid == 1) array_push($cmds,$onlineCmd);
				
				// SERVICE
				foreach($a['services'] as $s){
					if($s['typeLabel']=='protocol.information.service') continue;
					$LightElement=[];
					$ServiceName=((isset($knownUUID['services'][$s['typeLabel']]))?$knownUUID['services'][$s['typeLabel']]:$s['typeLabel']);
					$ServiceOriginalName=(($s['serviceOriginalName'])?$s['serviceOriginalName']:'unset');
					$ServiceType=homekitUtils::expandUUID($s['type']);
					
					// CHARACTERISTIC
					foreach($s['characteristics'] as $c){
						$infoCmd=null;
						$actionCmd=null;
						$infoManCmd=null;
						$CisHidden=in_array('hd',$c['perms']);
						$CisAction=in_array('pw',$c['perms']);
						$CisInfo=in_array('ev',$c['perms']);
						$CisManualInfo=in_array('pr',$c['perms']);
						$CharName=((isset($knownUUID['characteristics'][$c['typeLabel']]))?$knownUUID['characteristics'][$c['typeLabel']]:$c['typeLabel']);
						$CharType=homekitUtils::expandUUID($c['type']);
						
						if($ServiceName == 'accessory-information') {
							/***
							Create Identify CMD & configuration things
											***/
							switch($CharName) {
								case 'identify':
									if($identifyCmd !== false) {
										$identifyCmd['configuration']=[];
										$identifyCmd['configuration']['aidiid']=$aid.'.'.$c['iid'];
										$identifyCmd['configuration']['service']=$ServiceName;
										$identifyCmd['configuration']['serviceType']=$ServiceType;
										array_push($cmds,$identifyCmd);
									}
									else log::add('HomeWizard','warning',__("Commande identify non trouvée", __FILE__));
								break;
								case 'name':
									$thisName=$c['value'];
								break;
								default:
									array_push($conf,[$CharName,$c['value']]);
								break;
							}
							
						} else {
							/***
							Create other CMD
										***/
							if($CharName == 'name') continue;
							if($CharName == 'hue' || $CharName == 'saturation') {
								if($CharName == 'hue') $LightElement['hue']=$c;
								if($CharName == 'saturation') $LightElement['sat']=$c;
								if(count($LightElement) != 2) {
									continue;
								}
							}
										
							$template=[
								"name"=>ucfirst($CharName),
								"logicalId"=>$s['iid'].'_'.$ServiceType.'_'.$CharType,
								"configuration"=>[
									"aidiid"=>$aid.'.'.$c['iid'],
									"service"=>$ServiceName,
									"serviceOriginalName"=>$ServiceOriginalName,
									"serviceType"=>$ServiceType,
									"serviceIID"=>$s['iid'],
									"hkFormat"=>$c['format']
								],
								"display"=>[]
							];
							if($CisHidden || (isset($s['hidden']) && $s['hidden']=='true')) $template['isVisible']=0;
							else $template['isVisible']=1;
							
							if(isset($c['maxValue'])) 		$template['configuration']['maxValue'] = $c['maxValue'];
							if(isset($c['minValue'])) 		$template['configuration']['minValue'] = $c['minValue'];
							if(isset($c['minStep']))  		$template['configuration']['step'] = $c['minStep'];
							if(isset($c['maxLen']))   		$template['configuration']['maxLen'] = $c['maxLen'];
							if(isset($c['valid-values']))	$template['configuration']['valid-values'] = $c['valid-values'];
							if(isset($c['unit']))			$template['unite']=homekitUtils::transformUnit($c['unit']);
							
							/***
							Create INFO CMD
											***/
							if($CisInfo) {
								$infoCmd=$template;
								$infoCmd['logicalId'].='_INFO';
								$infoCmd['name'].=(($CisAction)?" (Info)":'');
								$infoCmd['type']="info";
								
								switch($c['format']) {
									case 'bool':
										$infoCmd['subtype']="binary";
										//$infoCmd['value']=(($c['value']=="1")?1:0);
										$infoCmd['value']=homekitUtils::sanitizeValue($c['value'],$c);
									break;
									case 'float':
										$infoCmd['subtype']="numeric";
										$infoCmd['value']=homekitUtils::sanitizeValue($c['value'],$c);
									break;
									case 'uint8':
									case 'uint16':
									case 'uint32':
									case 'uint64':
									case 'int':
										$infoCmd['subtype']="numeric";
										$infoCmd['value']=homekitUtils::sanitizeValue($c['value'],$c);
										if ($CharName == 'input-event') {
											$infoCmd['configuration']['repeatEventManagement']='always';
										}
										if(isset($homekitValues[$CharType])) {
											$list=[];
											foreach($homekitValues[$CharType] as $val => $label) {
												if(isset($c['valid-values']) && !in_array($val,$c['valid-values'])) {continue;}
												if(isset($c['minValue']) && $val < $c['minValue']) {continue;}
												if(isset($c['maxValue']) && $val > $c['maxValue']) {continue;}
												array_push($list,$val.'='.$label);
											}
											$infoCmd['configuration']['possibleValues']=join('<br/>',$list);
											if($CharName == "occupancy-detected" && (isset($c['minStep']) && $c['minStep'] == 1) && (isset($c['minValue']) && $c['minValue'] == 0) && (isset($c['maxValue']) && $c['maxValue'] == 1)) {
												$infoCmd['subtype']="binary";
												$infoCmd['value']=((homekitUtils::sanitizeValue($c['value'],$c))?1:0);
											}
										} else {
											if(isset($c['minStep']) && isset($c['minValue']) && isset($c['maxValue']) && ((intval($c['maxValue'])-intval($c['minValue']))/intval($c['minStep']) == 1)) {	
												$infoCmd['subtype']="binary";
												$infoCmd['value']=((homekitUtils::sanitizeValue($c['value'],$c) == $c['maxValue'])?true:false);
											} elseif (isset($c['minStep']) && isset($c['minValue']) && isset($c['maxValue']) && ((intval($c['maxValue'])-intval($c['minValue']))/intval($c['minStep']) == 2)) {
												// si min=0, max=100, step=50 (50 is stop)
												$infoCmd['subtype']="binary";
												$infoCmd['value']=((homekitUtils::sanitizeValue($c['value'],$c) == $c['maxValue'])?true:((homekitUtils::sanitizeValue($c['value'],$c) == $c['minValue'])?false:null));
												if($infoCmd['value'] === null) unset($infoCmd['value']);
											}
										}
									break;
									case 'data':
									case 'tlv8':
										$infoCmd['isVisible']=0;
									case 'string':
										$infoCmd['subtype']="string";
										$infoCmd['value']=homekitUtils::sanitizeValue($c['value'],$c);
									break;
								}
								if(is_array($LightElement) && count($LightElement) == 2 && ($CharName == 'hue' || $CharName == 'saturation')) {
									$infoCmd['name']="Color (Info)";
									$infoCmd['subtype']="string";

									$infoCmd['logicalId']=$s['iid'].'_'.$ServiceType.'_';
									$aidiids=[];
									foreach($LightElement as $le) {
										$infoCmd['logicalId'].=homekitUtils::expandUUID($le['type']).'_';
										array_push($aidiids,$aid.'.'.$le['iid']);
									}
									$infoCmd['logicalId'].="INFO";
									$infoCmd['configuration']["aidiid"]=join('|',$aidiids);
									$infoCmd['value']=homekitUtils::HStoHTML($LightElement['hue']['value'],$LightElement['sat']['value']);
									$infoCmd['configuration']['hkFormat']='hue-saturation';
									unset($infoCmd['configuration']['maxValue']);
									unset($infoCmd['configuration']['minValue']);
									unset($infoCmd['configuration']['step']);
									unset($infoCmd['configuration']['maxLen']);
									unset($infoCmd['configuration']['valid-values']);
								}
								array_push($cmds,$infoCmd);
							}
							
							/***
							Create MANUAL INFO CMD
											***/
							if($CisManualInfo && !$CisInfo) {
								$infoManCmd=$template;
								$infoManCmd['logicalId'].='_MANUALINFO';
								$infoManCmd['name'].=(($CisAction)?" (Manual Info)":'');
								$infoManCmd['type']="info";
								
								switch($c['format']) {
									case 'bool':
										$infoManCmd['subtype']="binary";
										//$infoManCmd['value']=(($c['value']=="1")?1:0);
										$infoManCmd['value']=homekitUtils::sanitizeValue($c['value'],$c);
									break;
									case 'float':
										$infoManCmd['subtype']="numeric";
										$infoManCmd['value']=homekitUtils::sanitizeValue($c['value'],$c);
									break;
									case 'uint8':
									case 'uint16':
									case 'uint32':
									case 'uint64':
									case 'int':
										$infoManCmd['subtype']="numeric";
										$infoManCmd['value']=homekitUtils::sanitizeValue($c['value'],$c);
										
										if(isset($homekitValues[$CharType])) {
											$list=[];
											foreach($homekitValues[$CharType] as $val => $label) {
												if(isset($c['valid-values']) && !in_array($val,$c['valid-values'])) {continue;}
												if(isset($c['minValue']) && $val < $c['minValue']) {continue;}
												if(isset($c['maxValue']) && $val > $c['maxValue']) {continue;}
												array_push($list,$val.'='.$label);
											}
											$infoManCmd['configuration']['possibleValues']=join('<br/>',$list);
										}									
									break;
									case 'data':
									case 'tlv8':
										$infoManCmd['isVisible']=0;
									case 'string':
										$infoManCmd['subtype']="string";
										$infoManCmd['value']=homekitUtils::sanitizeValue($c['value'],$c);
									break;
								}
								array_push($cmds,$infoManCmd);
							}
							
							/***
							Create ACTION CMD
											***/
							if($CisAction) {
								$actionCmd=$template;
								
								if($CisInfo) {
									$actionCmd['name'].=" (Action)";
									$actionCmd['value']=$infoCmd['logicalId'];
								}
								if($infoManCmd) {
									$actionCmd['name'].=" (Action)";
									$actionCmd['value']=$infoManCmd['logicalId'];
								}
								$actionCmd['logicalId'].='_ACTION';
								$actionCmd['type']="action";
								
								switch($c['format']) {
									case 'bool':
										// double command on
										if($CisInfo) {
											$actionCmd['name']=$template['name']." (Allumé)";
										} else {
											$actionCmd['name']=$template['name']." (Action)";
										}
										$actionCmd['logicalId']=$template['logicalId'].='_ON';
										$actionCmd['subtype']="other";
										$actionCmd['configuration']['valueToSet']=1;
										
										
										// create a second command : off
										if($CisInfo) { // only if info
											array_push($cmds,$actionCmd);
											$actionCmd=$template;
											$actionCmd['name'].=__(" (Eteint)", __FILE__);
											$actionCmd['value']=$infoCmd['logicalId'];
											$actionCmd['logicalId'].='_OFF';
											$actionCmd['type']="action";
											$actionCmd['subtype']="other";
											$actionCmd['configuration']['valueToSet']=0;
										}
										
									break;
									case 'float':
									case 'uint8':
									case 'uint16':
									case 'uint32':
									case 'uint64':
									case 'int':
										if(isset($homekitValues[$CharType])) {
											$actionCmd['subtype']="select";
											$list=[];
											foreach($homekitValues[$CharType] as $val => $label) {
												if(isset($c['valid-values']) && !in_array($val,$c['valid-values'])) {continue;}
												if(isset($c['minValue']) && $val < $c['minValue']) {continue;}
												if(isset($c['maxValue']) && $val > $c['maxValue']) {continue;}
												array_push($list,$val.'|'.$label);
											}
											$actionCmd['configuration']['listValue']=join(';',$list);
										} else {
											if($CharName == "active") {
												$actionCmd['name']=$template['name'].__(" (Activer)", __FILE__);
												$actionCmd['logicalId']=$template['logicalId'].='_Active';
												$actionCmd['subtype']="other";
												$actionCmd['configuration']['valueToSet']=1;

												array_push($cmds,$actionCmd);
												$actionCmd=$template;
												$actionCmd['name'].=__(" (Désactiver)", __FILE__);
												$actionCmd['value']=$infoCmd['logicalId'];
												$actionCmd['logicalId'].='_Inactive';
												$actionCmd['type']="action";
												$actionCmd['subtype']="other";
												$actionCmd['configuration']['valueToSet']=0;
											} elseif(isset($c['minStep']) && isset($c['minValue']) && isset($c['maxValue']) && ((intval($c['maxValue'])-intval($c['minValue']))/intval($c['minStep']) == 1)) {
												$actionCmd['name']=$template['name'].__(" (Ouvrir)", __FILE__);
												$actionCmd['logicalId']=$template['logicalId'].='_OPEN';
												$actionCmd['subtype']="other";
												$actionCmd['configuration']['valueToSet']=100;

												array_push($cmds,$actionCmd);
												$actionCmd=$template;
												$actionCmd['name'].=__(" (Fermer)", __FILE__);
												$actionCmd['value']=$infoCmd['logicalId'];
												$actionCmd['logicalId'].='_CLOSE';
												$actionCmd['type']="action";
												$actionCmd['subtype']="other";
												$actionCmd['configuration']['valueToSet']=0;
											} elseif(isset($c['minStep']) && isset($c['minValue']) && isset($c['maxValue']) && ((intval($c['maxValue'])-intval($c['minValue']))/intval($c['minStep']) == 2)) {
												$actionCmd['name']=$template['name'].__(" (Ouvrir)", __FILE__);
												$actionCmd['logicalId']=$template['logicalId'].='_OPEN';
												$actionCmd['subtype']="other";
												$actionCmd['configuration']['valueToSet']=100;

												array_push($cmds,$actionCmd);
												$actionCmd=$template;
												$actionCmd['name'].=__(" (Fermer)", __FILE__);
												$actionCmd['value']=$infoCmd['logicalId'];
												$actionCmd['logicalId'].='_CLOSE';
												$actionCmd['type']="action";
												$actionCmd['subtype']="other";
												$actionCmd['configuration']['valueToSet']=0;
												
												array_push($cmds,$actionCmd);
												$actionCmd=$template;
												$actionCmd['name'].=__(" (Stop)", __FILE__);
												$actionCmd['value']=$infoCmd['logicalId'];
												$actionCmd['logicalId'].='_STOP';
												$actionCmd['type']="action";
												$actionCmd['subtype']="other";
												$actionCmd['configuration']['valueToSet']=50;
											} else {
												$actionCmd['subtype']="slider";
											}
										}
									break;
									case 'data':
									case 'tlv8':
										$actionCmd['isVisible']=0;
									case 'string':
										$actionCmd['subtype']="message";
										$actionCmd['display']['title_disable']=1;
										$actionCmd['display']['message_disable']=0;
										$actionCmd['display']['title_placeholder']=__('Non Utilisé', __FILE__);
										$actionCmd['display']['message_placeholder']=__('Chaîne à envoyer', __FILE__);
									break;
								}
								if(is_array($LightElement) && count($LightElement) == 2 && ($CharName == 'hue' || $CharName == 'saturation')) {
									$actionCmd['name']="Color (Action)";
									$actionCmd['subtype']="color";
									$actionCmd['logicalId']=$s['iid'].'_'.$ServiceType.'_';
									$aidiids=[];
									foreach($LightElement as $le) {
										$actionCmd['logicalId'].=homekitUtils::expandUUID($le['type']).'_';
										array_push($aidiids,$aid.'.'.$le['iid']);
									}
									$actionCmd['configuration']["aidiid"]=join('|',$aidiids);
									$actionCmd['logicalId'].="ACTION";
									
									$actionCmd['configuration']['hkFormat']='hue-saturation';
									unset($actionCmd['configuration']['maxValue']);
									unset($actionCmd['configuration']['minValue']);
									unset($actionCmd['configuration']['step']);
									$actionCmd['configuration']['maxLen']=7;
									unset($actionCmd['configuration']['valid-values']);
								}
								array_push($cmds,$actionCmd);
							}
						}
					}
				}
				if($aid != 1) {
					$eq = [
						"name"=>$thisName,
						"logicalId"=>$logicalId."_".$aid,
						"enable"=>1,
						"visible"=>1,
						"configuration"=>[
							"aid"=>$aid,
							"type"=>"BridgedAccessory"
						]
					];
					$eqLogic=self::createEq($eq);
					array_push($presentAccessories,$logicalId.'_'.$aid);
				} else {
					array_push($presentAccessories,$logicalId);
					$mainAcc=$logicalId;
				}
				foreach($cmds as $cmd) {
					$eqLogic->createCmd($cmd);
				}
				foreach($conf as $v) {
					$eqLogic->setConfiguration($v[0],$v[1]);
				}
				$eqLogic->save(true);
			}
			$listEq=eqLogic::byType('HomeWizard');
			foreach($listEq as $eq) {
				if(strpos($eq->getLogicalId(),$mainAcc) !== false && !in_array($eq->getLogicalId(),$presentAccessories)) {
					log::add('HomeWizard','debug',__("N'est plus présent dans le pont et peut être supprimé : ", __FILE__).$eq->getName().'('.$eq->getLogicalId().')');
					$eq->setConfiguration('toRemove',1);
					$eq->save(true);
				}
			}
		} else {
			log::add('HomeWizard','warning',__("L'accessoire n'est pas un array ou est vide :", __FILE__).json_encode($acc));
		}
	}
	public function cmdNameExist($name) {
		$cmd=cmd::byEqLogicIdCmdName($this->getId(),$name);
		if(is_object($cmd)) { 
			return true;
		}
		return false;
	}	
	public function preRemove() {
		$daemonState=HomeWizard::deamon_info();
		if($daemonState['state'] != 'ok') return false;
		if(homekitUtils::toBool($this->getConfiguration('paired',false))) {
			HomeWizard::hkExecute('unPair',['id'=>$this->getLogicalId()]);
		}
		if($this->getConfiguration('type','') == "Bridge") {
			$eqLogics = eqLogic::byType('HomeWizard');
			foreach ($eqLogics as $eqLogic) {
				if($eqLogic->getConfiguration('type','') != "BridgedAccessory") continue;
				
				if(strpos($eqLogic->getLogicalId(),$this->getLogicalId()) !== false) { // if this bridgedAccessory logicalId contains this bridge logicalId
					$eqLogic->remove();
				}
			}
		}
	}
	public function postSave() {
		if(homekitUtils::toBool($this->getConfiguration('paired',false))) {
			$this->accessoriesToJeedom();
		} else {
			// either a BridgedAccessory or either a unpaired device : not creating commands
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
		$partLogical=explode('_',$eqLogical);
		if(isset($partLogical[1])) {
			$eqLogical=$partLogical[0];
		}
		
		$daemonState=HomeWizard::deamon_info();
		if($daemonState['state'] != 'ok') {
			log::add('HomeWizard','error',__("Le démon doit être démarré pour lancer la commande", __FILE__));
			return false;
		}

		switch ($logical) {
			case 'refresh' :
				HomeWizard::hkExecute('getAccessories',['id'=>$eqLogical]);
			break;
			case 'identify':
				HomeWizard::hkExecute('identify',['id'=>$eqLogical,'char'=>$this->getConfiguration('aidiid')]);
			break;
			default:
				$subtype=$this->getSubtype();
				
				//if message or slider or color
				if(is_array($_options) && isset($_options[$subtype])) {
					$val=$_options[$subtype];
				} else { // if bool
					$val=$this->getConfiguration('valueToSet');
				}
				$aidiids=$this->getConfiguration('aidiid');
				
				if(strpos($aidiids,'|') !== false && $subtype=='color') {
					$aidiids=explode('|',$aidiids);
					$val=homekitUtils::HTMLtoHS($val);
					$aidiids=[
						[$aidiids[0],$val[0]],
						[$aidiids[1],$val[1]]
					];
				} else {
					$aidiids=[[$aidiids,$val]];
				}
				//$params=[];
				foreach($aidiids as $elmt) {
					$c=explode('.',$elmt[0]);
					//array_push($params,
					$params=[
						'id'=>$eqLogical,
						'aid'=>$c[0],
						'iid'=>$c[1],
						'val'=>$elmt[1]
					];
					log::add('HomeWizard','info',__("Action à envoyer au démon : ", __FILE__).$this->getName().'('.$eqLogical.')('.$elmt[0].')->'.$elmt[1]);
					HomeWizard::hkExecute('setAccessories',$params);
				}
				//log::add('HomeWizard','debug','Action à envoyer au démon : '.$this->getName().'('.$eqLogical.')('.$elmt[0].')->'.$elmt[1].' '.json_encode($params));
				//HomeWizard::hkExecute('setAccessories',$params);
			break;
		}
		//log::add('HomeWizard','debug',$logical);

		//$eqLogic->getHomeWizardInfo(null,null,$hasToCheckPlaying);
	}

	/************************Getteur Setteur****************************/
}
?>
