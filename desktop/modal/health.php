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

if (!isConnect('admin')) {
	throw new Exception('401 Unauthorized');
}
$eqLogics = eqLogic::byType('HomeWizard');
?>

<table class="table table-condensed tablesorter" id="table_healthaTVremote">
	<thead>
		<tr>
			<th>{{Module}}</th>
			<th>{{ID}}</th>
			<th>{{Type}}</th>
			<th>{{ID Logique}}</th>
			<th>{{Adresse}}</th>
			<th>{{Serial}}</th>
			<th>{{En Ligne}}</th>
			<th>{{Date création}}</th>
		</tr>
	</thead>
	<tbody>
	 <?php
foreach ($eqLogics as $eqLogic) {
	$type=$eqLogic->getConfiguration('type');
	displayHealthLine($eqLogic);
	foreach ($eqLogics as $BridgedeqLogic) {
		$Bridgedtype=$BridgedeqLogic->getConfiguration('type');
		if($Bridgedtype != 'BridgedAccessory') continue;
		$log=explode('_',$BridgedeqLogic->getLogicalId());
		if($log[0] == $eqLogic->getLogicalId()) {
			displayHealthLine($BridgedeqLogic,'<i class="fas fa-level-up-alt fa-rotate-90"></i>&nbsp;&nbsp;');
		}
	}
}

function displayHealthLine($eqLogic,$tab='') {
	$type=$eqLogic->getConfiguration('type');
	if($eqLogic->getIsEnable()) {
		echo '<tr>';
	} else {
		echo '<tr style="background-color:lightgrey !important;">';
	}
	echo '<td>'.$tab.'<a href="' . $eqLogic->getLinkToConfiguration() . '" style="text-decoration: none;">' . $eqLogic->getHumanName(true) . ((!$eqLogic->getIsvisible())?'&nbsp;<i class="fas fa-eye-slash"></i>':''). '</a></td>';
	echo '<td><span class="label label-info" style="font-size : 1em;width:100%">' . $eqLogic->getId() . '</span></td>';
	echo '<td><span class="label label-info" style="font-size : 1em;width:100%">' . $type . '</span></td>';
	echo '<td><span class="label label-info" style="font-size : 1em;width:100%">' . $eqLogic->getLogicalId() . '</span></td>';
	echo '<td><span class="label label-info" style="font-size : 1em;width:100%">' . $eqLogic->getConfiguration('address') . '</span></td>';
	echo '<td><span class="label label-info" style="font-size : 1em;width:100%">' . $eqLogic->getConfiguration('serial') . '</span></td>';
	$onlineCmd = $eqLogic->getCmd(null, 'online');
	if (is_object($onlineCmd)) {
		$online = $onlineCmd->execCmd();
		if($online == 1) {
			$online_status='<span class="label label-success" style="font-size : 1em; cursor : default;width:100%">{{OK}}</span>';
		} else {
			$online_status='<span class="label label-danger" style="font-size : 1em; cursor : default;width:100%">{{KO}}</span>';
		}
	} else {
		$online_status='<span class="label label-primary" style="font-size : 1em; cursor : default;width:100%">{{Inconnu}}</span>';
	}
	echo '<td>' . $online_status . '</td>';
	echo '<td><span class="label label-info" style="font-size : 1em;width:100%">' . $eqLogic->getConfiguration('createtime') . '</span></td></tr>';	
}
?>
	</tbody>
</table>
