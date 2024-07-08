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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>
<style>
pre#pre_eventlog {
    font-family: Menlo, Monaco, Consolas, "Courier New", monospace !important;
}
</style>
<form class="form-horizontal">
	<fieldset>
		<legend>
			<i class="fas fa-wrench"></i> {{Réparations}}
		</legend>
		<center>
			<a class="btn btn-danger btn-sm" id="bt_reinstallNodeJS"><i class="fas fa-recycle"></i> {{Réparation de NodeJS}} </a>
		</center>
	</fieldset>
	<fieldset>
		<legend>
			<i class="fas fa-wrench"></i> {{Configuration}}
		</legend>
		<div class="form-group">
			<label class="col-lg-4 control-label help" data-help="{{Période en miliseconde pour interroger l'équipement, il est déconseillé par le constructeur de descendre sous 1 seconde}}">{{Période}} HWE-SKT</label>
			<div class="col-lg-4">
				<div class="input-group">
					<input class="configKey form-control roundedLeft" data-l1key="period_HWE-SKT" placeholder="5000" /><span class="input-group-addon roundedRight">ms</span>
				</div>
			</div>
		</div>
	</fieldset>
</form>
<script>

	$('#bt_reinstallNodeJS').off('click').on('click', function() {
		bootbox.confirm("{{Etes-vous sûr de vouloir supprimer et reinstaller NodeJS ? <br /> Merci de patienter 10-20 secondes quand vous aurez cliqué...}}", function(result) {
			if (result) {
				$.showLoading();
				$.ajax({
					type : 'POST',
					url : 'plugins/HomeWizard/core/ajax/HomeWizard.ajax.php',
					data : {
						action : 'reinstallNodeJS',
					},
					dataType : 'json',
					global : false,
					error : function(request, status, error) {
						$.hideLoading();
						$('#div_alertPluginConfiguration').showAlert({
							message : error.message,
							level : 'danger'
						});
					},
					success : function(data) {
						$.hideLoading();
						$('li.li_plugin.active').click();
						$('#div_alertPluginConfiguration').showAlert({
							message : "{{Réinstallation NodeJS effectuée, merci de patienter jusqu'à la fin de l'installation des dépendances}}",
							level : 'success'
						});
					}
				});
			}
		});
	});	
	
	$(document).ready(function() {
		btSave = $('#bt_savePluginLogConfig');
		if (!btSave.hasClass('HomeWizardLog')) { // Avoid multiple declaration of the event on the button
			btSave.addClass('HomeWizardLog');
			btSave.on('click', function() {
				var level = $('input.configKey[data-l1key="log::level::HomeWizard"]:checked')
				if (level.length == 1) { // Found 1 log::level::jMQTT input checked
					$.ajax({
						type : 'POST',
						url : 'plugins/HomeWizard/core/ajax/HomeWizard.ajax.php',
						data: {
							action: "sendLoglevel",
							level: level.attr('data-l2key')
						},
						dataType : 'json',
						global : false,
						success: function(data) {
							if (data.state == 'ok')
								$.fn.showAlert({message: "{{Le démon est averti, il n'est pas nécessire de le redémarrer.}}", level: 'success'});
						}
					});
				}
			});
		};
	});
	$('.help').each(function() {
		if ($(this).attr('data-help') != undefined) {
		  $(this).append(' <sup><i class="fas fa-question-circle tooltips" title="'+$(this).attr('data-help')+'"></i></sup>')
		}
	});
	if(jeedomUtils) {jeedomUtils.initTooltips();}else{initTooltips();}
</script>
