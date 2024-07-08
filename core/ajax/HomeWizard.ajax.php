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

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect()) {
        throw new Exception(__("401 - Accès non autorisé", __FILE__));
    }
	
	ajax::init();

	if (init('action') ==  'reinstallNodeJS') {
		$daemonState=HomeWizard::deamon_info();
		if($daemonState['state'] != 'ok') {
			ajax::error(__("Le démon n'est pas démarré", __FILE__));
		}

		$ret = HomeWizard::reinstallNodeJS();
		ajax::success($ret);
	} elseif (init('action') == 'sendLoglevel') {
		log::add('HomeWizard','error',"level:".init('level'));
		$level=log::convertLogLevel((init('level')==='default')?log::getConfig('log::level'):init('level'));
		log::add('HomeWizard','error',"levelAfter:".$level);
		HomeWizard::hwConfig('sendLoglevel',["value"=>$level]);
		ajax::success();
	} 
	
	
	

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
    /*     * *********Catch exeption*************** */
} catch (Exception $e) {
	if(version_compare(jeedom::version(), '4.4', '>=')) {
		ajax::error(displayException($e), $e->getCode());
	} else {
		ajax::error(displayExeption($e), $e->getCode());
	}
}
?>
