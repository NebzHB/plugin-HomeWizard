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

	if (init('action') !=  'reinstallNodeJS') {
		$daemonState=HomeWizard::deamon_info();
		if($daemonState['state'] != 'ok') {
			ajax::error(__("Le démon n'est pas démarré", __FILE__));
		}
	}
	
	if (init('action') == 'pair') {
		$request=[
			'id'=>init('id'),
			'address'=>init('address'),
			'port'=>init('port'),
			'pin'=>init('pin'),
			'typeId'=>init('typeId'),
			'name'=>init('name'),
			'pairMethod'=>init('pairMethod')
		];
		log::add('HomeWizard','info',__("Demande d'appairage... : ", __FILE__).http_build_query($request));
		$ret=HomeWizard::hkExecute('pair',$request);
		log::add('HomeWizard','info',__("Appairage:", __FILE__).$ret['result']);
		ajax::success($ret);
	} elseif (init('action') == 'prePair') {
		$request=[
			'id'=>init('id'),
			'address'=>init('address'),
			'port'=>init('port'),
			'typeId'=>init('typeId'),
			'name'=>init('name'),
			'pairMethod'=>init('pairMethod')
		];
		log::add('HomeWizard','info',__("Demande de pré-appairage... : ", __FILE__).http_build_query($request));
		$ret=HomeWizard::hkExecute('prePair',$request);
		log::add('HomeWizard','info',__("Pré-appairage:", __FILE__).$ret['result']);
		ajax::success($ret);
	} elseif (init('action') == 'postPair') {
		$request=[
			'id'=>init('id'),
			'pin'=>init('pin')
		];
		log::add('HomeWizard','info',__("Demande de post-appairage... : ", __FILE__).http_build_query($request));
		$ret=HomeWizard::hkExecute('postPair',$request);
		log::add('HomeWizard','info',__("Post-appairage:", __FILE__).$ret['result']);
		ajax::success($ret);
	} elseif (init('action') == 'unpair') {
		log::add('HomeWizard','info',__("Demande de désappairage : ", __FILE__).init('id'));
		$ret=HomeWizard::hkExecute('unPair',['id'=>init('id')]);
		log::add('HomeWizard','info',__("Désappairage:", __FILE__).$ret['result']);
		ajax::success($ret);
	} elseif (init('action') == 'removeFromPairings') {
		log::add('HomeWizard','info',__("Demande de suppression d'appairage :", __FILE__).json_encode(init('removeList')));
		$ret=HomeWizard::removePairing(init('removeList'));
		ajax::success($ret);
	} elseif (init('action') == 'refresh') {
		log::add('HomeWizard','info',__("Refresh d'un accessoire :", __FILE__).json_encode(init('id')));
		$eq=HomeWizard::byLogicalId(init('id'),'hkControl');
		$cmd=$eq->getCmd(null,'refresh');
		$cmd->execCmd();
		ajax::success(['result'=>'ok']);
	} elseif (init('action') ==  'reinstallNodeJS') {
		$ret = HomeWizard::reinstallNodeJS();
		ajax::success($ret);
	} elseif (init('action') == 'reDiscover') {
		HomeWizard::hkExecute('reDiscover');
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
