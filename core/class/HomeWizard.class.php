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
			if ($HomeWizard->getIsEnable() == 0) continue;
			if ($HomeWizard->pingHost($HomeWizard->getConfiguration('address')) == true) continue;
			log::add('HomeWizard', 'debug', __("Offline Réseau : ", __FILE__) . $HomeWizard->getName());
		}
	}

	public static function event() {
		$changed=false;
		$eventType = init('eventType');
		log::add('HomeWizard', 'debug', __("Passage dans la fonction event ", __FILE__) . $eventType);
		if ($eventType == 'error'){
			log::add('HomeWizard', 'error', init('description'));
			return;
		}
		
		switch ($eventType) {
			case 'createEq':
				log::add('HomeWizard', 'info', __("Découverte de :", __FILE__).json_encode(init('mdns')));
				$mdns = init('mdns');
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
				  }
				}*/
				$eq = [
					"name"=>$mdns['txt']['product_name'],
					"logicalId"=>$mdns['txt']['product_type'].'_'.$mdns['txt']['serial'],
					"enable"=>1,
					"visible"=>1,
					"configuration"=>[
						"address"=>$mdns['ip'],
						"hostname"=>$mdns['hostname'],
						"type"=>$mdns['txt']['product_type'],
						"serial"=>$mdns['txt']['serial'],
					]
				];
				self::createEq($eq);
			break;
			case 'createEqwithoutEvent':
				log::add('HomeWizard', 'info', __("Mise à jour de :", __FILE__).json_encode(init('mdns')));
				$mdns = init('mdns');

				$eq = [
					"name"=>$mdns['txt']['product_name'],
					"logicalId"=>$mdns['txt']['product_type'].'_'.$mdns['txt']['serial'],
					"configuration"=>[
						"address"=>$mdns['ip'],
						"hostname"=>$mdns['hostname'],
						"type"=>$mdns['txt']['product_type'],
						"serial"=>$mdns['txt']['serial'],
					]
				];
				self::createEq($eq,false);
			break;
			case 'updateValue':
				//log::add('HomeWizard', 'debug', 'updateValue :'.init('id').' '.init('aidiid').' '.init('value'));
				$logical=init('id');
				$eqp = eqlogic::byLogicalId($logical,'HomeWizard');
				if (is_object($eqp)){
					$val=init('value');
					log::add('HomeWizard','debug',json_encode($val));
					$hasNewCmd=false;
					foreach($val as $key=>$value) {
						$newCmd = $eqp->getCmd(null, $key);
						if (!is_object($newCmd)) {
							$keyPart=explode('_',$key);
							$unite='';
							if(count($keyPart) >2) {
								$unite = $keyPart[count($keyPart)];
								if($unite=='timestamp') {$unite='';}
							}	
							$cmd=[
								"name"=>ucfirst($key),
								"logicalId"=>$key,
								"isVisible"=>1,
								"unite"=>$unite,
								"type"=>"info",
								"subtype"=>"numeric"
							];
							if($key == 'unique_id' || $key == 'wifi_ssid' || $key == 'meter_model') $cmd['subtype']='other';
							$hasNewCmd=$eqp->createCmd($cmd);
						} else {
							$eqp->checkAndUpdateCmd($key,$value);
						}
					}
					if($hasNewCmd) $eqp->save(true);
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

		// Get package.json
		$hapControllerRequiredVers = file_get_contents(dirname(__FILE__) . '/../../resources/package.json');
		$hapControllerRequiredVers = json_decode($hapControllerRequiredVers,true);

		// Check if NodeJS version is greater or equal the required version
		$nodeVer=trim(shell_exec('node -v'),"v\n\r");
		if(!$nodeVer) {$nodeVer='';}
		preg_match('/(>=|<=|>|<|=)?(\d+(\.\d+){0,2})/', $hapControllerRequiredVers['engines']['node'], $matches);
		$operator = $matches[1] ?: '==';
		$specifiedVersion = $matches[2];
		
		$nodeVersionOK=version_compare($nodeVer,$specifiedVersion,$operator);
		if(!$nodeVersionOK) {
			return $return;
		}

		// Check if jeedom connect class is present
		if(!file_exists(dirname(__FILE__) . '/../../resources/utils/jeedom.js')) {
			return $return;
		}

		// Check if all dependancies of hap-controller are installed and have the required version
		foreach($hapControllerRequiredVers['dependencies'] as $dep => $requiredVersionSpec) {
		    $depPackageJson = file_get_contents(dirname(__FILE__) . '/../../resources/node_modules/' . $dep . '/package.json');
		    if (!$depPackageJson) {
		        return $return;
		    }
		
		    $depDetails = json_decode($depPackageJson, true);
		    $installedVersion = $depDetails['version'];
		
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
		$debugMsg='';
		$inspect=' --inspect=0.0.0.0:9229 ';
		$inspect='';
		$deamonPath = realpath(dirname(__FILE__) . '/../../resources');
		$cmd = 'nice -n 19 node '.$inspect.' ' . $deamonPath . '/HomeWizard.js ' . $url . ' ' . jeedom::getApiKey('HomeWizard') .' '. $socketport . ' ' . $logLevel;

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
	
	
	public static function hwExecute($cmd,$params=[]) {
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
	
	public function pingHost($host, $timeout = null) {
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
		$type=$this->getConfiguration('type');
		$catIcon = 'plugins/HomeWizard/core/config/img/'.$type.'.png';
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
		return $isNew;
	}
	

	public function cmdNameExist($name) {
		$cmd=cmd::byEqLogicIdCmdName($this->getId(),$name);
		if(is_object($cmd)) { 
			return true;
		}
		return false;
	}	

	public function preSave() {
		$online=$this->getCmd(null, 'online');
		if(!is_object($online)) {
			$cmd=[
				"name"=>"Online",
				"logicalId"=>'online',
				"type"=>"info",
				"subtype"=>"binary",
				"display"=> [
					"generic_type"=>"ONLINE"
				],
				"isVisible"=>1,
				"isHistorized"=>1
			];
			$this->createCmd($cmd);
			$this->pingHost($this->getConfiguration('address'));
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
				HomeWizard::hwExecute('getAccessories',['id'=>$eqLogical]);
			break;
			case 'identify':
				HomeWizard::hwExecute('identify',['id'=>$eqLogical,'char'=>$this->getConfiguration('aidiid')]);
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
					HomeWizard::hwExecute('setAccessories',$params);
				}
				//log::add('HomeWizard','debug','Action à envoyer au démon : '.$this->getName().'('.$eqLogical.')('.$elmt[0].')->'.$elmt[1].' '.json_encode($params));
				//HomeWizard::hwExecute('setAccessories',$params);
			break;
		}
		//log::add('HomeWizard','debug',$logical);

		//$eqLogic->getHomeWizardInfo(null,null,$hasToCheckPlaying);
	}

	/************************Getteur Setteur****************************/
}
?>
