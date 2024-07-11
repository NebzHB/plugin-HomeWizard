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

	public static function event($data) {
		$changed=false;
		$eventType = $data['eventType'];
		log::add('HomeWizard', 'debug', __("Passage dans la fonction event ", __FILE__) . $eventType);
		if ($eventType == 'error'){
			log::add('HomeWizard', 'error', $data['description']);
			return;
		}
		
		switch ($eventType) {
			case 'createEq':
				log::add('HomeWizard', 'info', __("Découverte de :", __FILE__).json_encode($data['mdns']));
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
						"firmware_version"=>$mdns['firmware_version'],
					]
				];
				self::createEq($eq);
			break;
			case 'updateValue':
				$logical=$data['id'];
				$eqp = eqlogic::byLogicalId($logical,'HomeWizard');
				if (is_object($eqp)){
					$val=$data['value'];
					log::add('HomeWizard','debug',json_encode($val));
					$hasNewCmd=false;
					foreach($val as $key=>$value) {
						$newCmd = $eqp->getCmd(null, $key);
						if (!is_object($newCmd)) {
							$keyPart=explode('_',$key);
							$unite='';
							if(count($keyPart) >2) {
								$unite = $keyPart[count($keyPart)-1];
								switch($unite) {
									case 'timestamp':
										$unite='';
									break;
									case 'factor':
										$unite='';
									break;
									case 'kwh':
										$unite="kWh";
									break;
									case 'hz':
										$unite="Hz";
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
							if($key == 'unique_id' || $key == 'wifi_ssid' || $key == 'meter_model' || $key == 'montly_power_peak_timestamp') $cmd['subtype']='other';
							if($key == 'power_on' || $key == 'switch_lock') $cmd['subtype']='binary';
							if($key == 'brightness') {
								$cmd['configuration']['minValue']=0;
								$cmd['configuration']['maxValue']=255;
								unset($cmd['template']);
							}
							if($key == 'total_power_import_kwh' || $key == 'total_power_export_kwh' || $key == 'active_power_w') {
								$cmd['template']['dashboard']='tile';
								$cmd['template']['mobile']='tile';
							}
							$hasNewCmd=$eqp->createCmd($cmd);
						} else {
							switch($key) {
								case "montly_power_peak_timestamp":
									if (preg_match('/^\d{12}$/', $value)) {
										$value = DateTime::createFromFormat('ymdHis', $value)->format('d/m/Y H:i:s');
									}
								break;
							}
							$eqp->checkAndUpdateCmd($key,$value);
						}
					}
					if($hasNewCmd) $eqp->save();
				} else {
					log::add('HomeWizard','warning',__("Aucun équipement trouvé avec l'id = ", __FILE__).$logical);
				}
			break;
			case 'doPing':
				$eqp = eqlogic::byLogicalId($data['id'],'HomeWizard');
				if (is_object($eqp) && $eqp->getIsEnable() == 1){
					if ($eqp->pingHost($eqp->getConfiguration('address')) == false) {
						log::add('HomeWizard', 'debug', __("Offline Réseau : ", __FILE__) . $eqp->getName());
					} else {
						log::add('HomeWizard', 'debug', __("Online Réseau : ", __FILE__) . $eqp->getName());
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
	
	public static function reinstallNodeJS() { // Reinstall NODEJS from scratch (to use if there is errors in dependancy install)
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
		$deamon_info = self::deamon_info();
		if ($deamon_info['state'] == 'ok') {
			log::add('HomeWizard', 'info', __("Arrêt du démon HomeWizard", __FILE__));
			$url="http://" . config::byKey('internalAddr') . ":".config::byKey('socketport', 'HomeWizard')."/stop";
			$request_http = new com_http($url);
			$request_http->setNoReportError(true);
			$request_http->exec(11,1);
			for ($retry = 0; $retry < 5; $retry++) {
				if (self::deamon_info()['state'] != 'ok') { 
					return true;
				}
				sleep(1);
			}
			
			$pid = exec("pgrep -f 'resources/HomeWizard.js'");
			if($pid) {
				exec(system::getCmdSudo().'kill -15 ' . $pid.' > /dev/null 2>&1');
				log::add('hkControl', 'info', __("Arrêt SIGTERM du démon HomeWizard", __FILE__));
				for ($retry = 0; $retry < 3; $retry++) {
					if (self::deamon_info()['state'] != 'ok') { 
						return true;
					}
					sleep(1);
				}
			}
			
			$pid = exec("pgrep -f 'resources/HomeWizard.js'");
			if($pid) {
				exec(system::getCmdSudo().'kill -9 ' . $pid.' > /dev/null 2>&1');
				log::add('hkControl', 'info', __("Arrêt SIGKILL du démon HomeWizard", __FILE__));
			}
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
				$eqp->save();
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
				log::add('HomeWizard', 'warning', __('Problème de communication avec le démon à la demande :', __FILE__) . $url . ' Exception : ' . $e);
			} catch(Error $e) {
				log::add('HomeWizard', 'warning', __('Problème de communication avec le démon à la demande :', __FILE__) . $url . ' Error : ' . $e);
			}
			if ($json === null) {
				log::add('HomeWizard', 'debug', __("Le démon n'a rien répondu à la demande :", __FILE__) . $url);
			}
			if ($json['result'] != 'ok') {
				log::add('HomeWizard', 'debug', __("Erreur du démon :", __FILE__) . $json['msg']);
			}
			log::add('HomeWizard', 'debug', ucfirst($setting) . ' brut : ' . $result);
			return $json['value'];
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
			log::add('HomeWizard','debug',__("Modification commande ", __FILE__).(($cmd['name'])?$cmd['name']:$cmd['logicalId']));
		}
		if(isset($cmd['unite'])) {
			$newCmd->setUnite( $cmd['unite'] );
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
		$this->pingHost($this->getConfiguration('address'));

		$type = $this->getConfiguration('type');
		$eqConfig = 'plugins/HomeWizard/core/config/'.$type.'.json';
		$base = dirname(__FILE__) . '/../../../../';
		if(file_exists($base.$eqConfig)) {
			$content = file_get_contents($base.$eqConfig);
			if($content) {
				$content=translate::exec($content,realpath($base.$eqConfig));
			}
			$extraConfig=json_decode($content,true);
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
			log::add('HomeWizard','error',__("Le démon doit être démarré pour lancer la commande", __FILE__));
			return false;
		}

		switch ($logical) {
			case 'action_power_on' :
				$result = HomeWizard::hwExecute('cmd',['cmd'=>'power_on','id'=>$eqLogical]);
				if($result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).' : '.((is_array($result['error']))?$result['error']['response']:$result['error']));
					return false;
				}
			break;
			case 'action_power_off':
				$result = HomeWizard::hwExecute('cmd',['cmd'=>'power_off','id'=>$eqLogical]);
				if($result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).' : '.((is_array($result['error']))?$result['error']['response']:$result['error']));
					return false;
				}
			break;
			case 'action_lock' :
				$result = HomeWizard::hwExecute('cmd',['cmd'=>'lock','id'=>$eqLogical]);
				if($result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).' : '.((is_array($result['error']))?$result['error']['response']:$result['error']));
					return false;
				}
			break;
			case 'action_unlock':
				$result = HomeWizard::hwExecute('cmd',['cmd'=>'unlock','id'=>$eqLogical]);
				if($result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).' : '.((is_array($result['error']))?$result['error']['response']:$result['error']));
					return false;
				}
			break;
			case 'action_brightness' :
				$result = HomeWizard::hwExecute('cmd',['cmd'=>'brightness','id'=>$eqLogical,'val'=>$_options['slider']]);
				if($result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).' : '.((is_array($result['error']))?$result['error']['response']:$result['error']));
					return false;
				}
			break;
			case 'action_identify' :
				$result = HomeWizard::hwExecute('cmd',['cmd'=>'identify','id'=>$eqLogical]);
				if($result['result']==='ko') {
					log::add('HomeWizard','info',__("Résultat de la commande KO", __FILE__).' : '.((is_array($result['error']))?$result['error']['response']:$result['error']));
					return false;
				}
			break;
			default:
				log::add('HomeWizard','error',__("Commande", __FILE__).' '.$logical.' '.__("inconnue", __FILE__));
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
