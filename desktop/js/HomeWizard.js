/* eslint-env jquery, browser */
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

var forbiddenPIN = ["000-00-000","111-11-111","222-22-222","333-33-333","444-44-444","555-55-555","666-66-666","777-77-777","888-88-888","999-99-999","123-45-678","876-54-321"];

 $('#bt_healthhkControl').on('click', function() {
    $('#md_modal').dialog({title: "{{Santé hkControl}}"});
    $('#md_modal').load('index.php?v=d&plugin=hkControl&modal=health').dialog('open');
});

$('#bt_resetEqlogicSearch').on('click', function() {
  $('#in_searchEqlogic').val('');
  $('#in_searchEqlogic').keyup();
});

$('.eqLogicAction[data-action=reDiscover]').on('click', function (e) {
	$.ajax({// fonction permettant de faire de l'ajax
		type: "POST", // methode de transmission des données au fichier php
		url: "plugins/hkControl/core/ajax/hkControl.ajax.php", // url du fichier php
		data: {
			action: "reDiscover"
		},
		dataType: 'json',
		error: function (request, status, error) {
			handleAjaxError(request, status, error);
		},
		success: function (data) { // si l'appel a bien fonctionné
			if (data.state != 'ok') {
				$('#div_alert').showAlert({message: data.result, level: 'danger'});
				return;
			}
			$('#div_alert').showAlert({message: '{{Redécouverte lancée}}', level: 'success'});
	  }
	});
});

for(var i=1;i<($('.searchBox').length+2);i++) {
	if($('#in_searchEqlogic'+i).length) {
		$('#in_searchEqlogic'+i).off('keyup').keyup(function() {
			var n = this.id.replace('in_searchEqlogic','');
			var search = $(this).value().toLowerCase();
			search = search.normalize('NFD').replace(/[\u0300-\u036f]/g, "");
			if(search == ''){
				$('.eqLogicDisplayCard.cont'+n).show();
				$('.eqLogicThumbnailContainer.cont'+n).packery();
				return;
			}
			$('.eqLogicDisplayCard.cont'+n).hide();
			$('.eqLogicDisplayCard.cont'+n+' .name').each(function(){
				var text = $(this).text().toLowerCase();
				text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, "");
				if(text.indexOf(search) >= 0){
					$(this).closest('.eqLogicDisplayCard.cont'+n).show();
				}
			});
			$('.eqLogicThumbnailContainer.cont'+n).packery();
		});
		$('#bt_resetEqlogicSearch'+i).on('click', function() {
			var n = this.id.replace('bt_resetEqlogicSearch','');
			$('#in_searchEqlogic'+n).val('');
			$('#in_searchEqlogic'+n).keyup();
		});
	}
}

$('#bt_Pair').on('click', function() {
	var pin = $('.eqLogicAttr[data-l1key=configuration][data-l2key=pin]')[0].value;
	
	if(pin == "") {
		$('#verif_pairing').removeClass("red green");
		$('#verif_logo').removeClass("fa-question-circle fa-times fa-check").addClass("fa-spinner fa-spin");
		$.ajax({
			type: 'POST',
			url: 'plugins/hkControl/core/ajax/hkControl.ajax.php',
			data: {
				action: 'prePair',
				id: $('.eqLogicAttr[data-l1key=logicalId]')[0].value,
				address:$('.eqLogicAttr[data-l1key=configuration][data-l2key=address]')[0].value,
				port:$('.eqLogicAttr[data-l1key=configuration][data-l2key=port]')[0].value,
				name:$('.eqLogicAttr[data-l1key=name]')[0].value,
				typeId:$('.eqLogicAttr[data-l1key=configuration][data-l2key=typeId]')[0].value,
				pairMethod:$('.eqLogicAttr[data-l1key=configuration][data-l2key=pairMethod]')[0].value
			},
			dataType: 'json',
			global: false,
			error: function(request, status, error) {
				failureMsg(error.message);
			},
			success: function(data) {
				if (data && data.result && data.result.result && data.result.result == 'ok') {
					successMsg('{{Pré-Appairage réussi, Entrez le code PIN affiché sur le périphérique}}');
					$('#bt_Pair').hide();
					$('.eqLogicAttr[data-l1key=configuration][data-l2key=pre-paired]')[0].value=true;
					$('#bt_postPair').show();
				} else {
					if(data && data.result) {
						if(data.result.result == 'ok') {
							failureMsg(data.result);
						} else {
							if(data.result.msg) {
								if(data.result.msg == '"M2: Error: 6"') {
									data.result.msg += "/{{Déjà appairé}}";	
								}
								failureMsg(data.result.result+'/'+data.result.msg);
							} else {
								failureMsg(data.result.result);
							}
						}
					} else {
						failureMsg(JSON.stringify(data));
					}
				}
			}
		});	
		
	} else {
		if(!checkPIN(pin)) { return false; }
		var isPaired = $('.eqLogicAttr[data-l1key=configuration][data-l2key=paired]')[0].value;

		if (isPaired=="false") {
			$('#verif_pairing').removeClass("red green");
			$('#verif_logo').removeClass("fa-question-circle fa-times fa-check").addClass("fa-spinner fa-spin");
			$.ajax({
				type: 'POST',
				url: 'plugins/hkControl/core/ajax/hkControl.ajax.php',
				data: {
					action: 'pair',
					id: $('.eqLogicAttr[data-l1key=logicalId]')[0].value,
					address:$('.eqLogicAttr[data-l1key=configuration][data-l2key=address]')[0].value,
					port:$('.eqLogicAttr[data-l1key=configuration][data-l2key=port]')[0].value,
					name:$('.eqLogicAttr[data-l1key=name]')[0].value,
					typeId:$('.eqLogicAttr[data-l1key=configuration][data-l2key=typeId]')[0].value,
					pairMethod:$('.eqLogicAttr[data-l1key=configuration][data-l2key=pairMethod]')[0].value,
					pin: pin
				},
				dataType: 'json',
				global: false,
				error: function(request, status, error) {
					failureMsg(error.message);
				},
				success: function(data) {
					if (data && data.result && data.result.result && data.result.result == 'ok') {
						successMsg('{{Appairage réussi, Cliquez sur "Sauvegarder"}}');
						$('#bt_Pair').hide();
						$('#bt_unPair').show();
						$('.eqLogicAttr[data-l1key=configuration][data-l2key=paired]')[0].value=true;
						$('.eqLogicAttr[data-l1key=isEnable]')[0].checked=true;
						$('.eqLogicAction[data-action=save]')[0].click();
					} else {
						if(data && data.result) {
							if(data.result.result == 'ok') {
								failureMsg(data.result);
							} else {
								if(data.result.msg) {
									if(data.result.msg == '"M2: Error: 6"') {
										data.result.msg += "/{{Déjà appairé}}";	
									}
									failureMsg(data.result.result+'/'+data.result.msg);
								} else {
									failureMsg(data.result.result);
								}
							}
						} else {
							failureMsg(JSON.stringify(data));
						}
					}
				}
			});
		} else {
			$('#div_alert').showAlert({
				message: '{{Déjà Appairé}}',
				level: 'danger'
			});
		}
	}
});
 $('#bt_postPair').on('click', function() {
	var pin = $('.eqLogicAttr[data-l1key=configuration][data-l2key=pin]')[0].value;
	
	if(!checkPIN(pin)) { return false; }

	
	var isPrePaired = $('.eqLogicAttr[data-l1key=configuration][data-l2key=pre-paired]')[0].value;

	if (isPrePaired=="true") {
		$('#verif_pairing').removeClass("red green");
		$('#verif_logo').removeClass("fa-question-circle fa-times fa-check").addClass("fa-spinner fa-spin");
		$.ajax({
			type: 'POST',
			url: 'plugins/hkControl/core/ajax/hkControl.ajax.php',
			data: {
				action: 'postPair',
				id: $('.eqLogicAttr[data-l1key=logicalId]')[0].value,
				pin: pin
			},
			dataType: 'json',
			global: false,
			error: function(request, status, error) {
				failureMsg(error.message);
			},
			success: function(data) {
				if (data && data.result && data.result.result && data.result.result == 'ok') {
					successMsg('{{Appairage réussi, Cliquez sur "Sauvegarder"}}');
					$('#bt_postPair').hide();
					$('#bt_unPair').show();
					$('.eqLogicAttr[data-l1key=configuration][data-l2key=paired]')[0].value=true;
					$('.eqLogicAttr[data-l1key=configuration][data-l2key=pre-paired]')[0].value=false;
					$('.eqLogicAttr[data-l1key=isEnable]')[0].checked=true;
					$('.eqLogicAction[data-action=save]')[0].click();
				} else {
					if(data && data.result) {
						if(data.result.result == 'ok') {
							failureMsg(data.result);
						} else {
							if(data.result.msg) {
								failureMsg(data.result.result+'/'+data.result.msg);
							} else {
								failureMsg(data.result.result);
							}
						}
					} else {
						failureMsg(JSON.stringify(data));
					}
				}
			}
		});
	} else {
		$('#div_alert').showAlert({
			message: '{{Pas encore pré-Appairé}}',
			level: 'danger'
		});
	}
});
 $('#bt_unPair').on('click', function() {
	var isPaired = $('.eqLogicAttr[data-l1key=configuration][data-l2key=paired]')[0].value;

	if (isPaired=="true") {
		$('#verif_pairing').removeClass("red green");
		$('#verif_logo').removeClass("fa-question-circle fa-times fa-check").addClass("fa-spinner fa-spin");
		$.ajax({
			type: 'POST',
			url: 'plugins/hkControl/core/ajax/hkControl.ajax.php',
			data: {
				action: 'unpair',
				id: $('.eqLogicAttr[data-l1key=logicalId]')[0].value
			},
			dataType: 'json',
			global: false,
			error: function(request, status, error) {
				failureMsg(error.message);
			},
			success: function(data) {
				if (data && data.result && data.result.result && data.result.result == 'ok') {
					successMsg('{{Désappairage réussi}}');
					$('#bt_unPair').hide();
					$('#bt_Pair').show();
					$('.eqLogicAttr[data-l1key=configuration][data-l2key=paired]')[0].value=false;
					$('.eqLogicAttr[data-l1key=isEnable]')[0].checked=false;
					$('.eqLogicAction[data-action=save]')[0].click();
				} else {
					if(data && data.result) {
						if(data.result.result == 'ok') {
							failureMsg(data.result);
						} else {
							if(data.result.msg) {
								failureMsg(data.result.result+'/'+data.result.msg);
							} else {
								failureMsg(data.result.result);
							}
						}
					} else {
						failureMsg(JSON.stringify(data));
					}
				}
			}
		});
	} else {
		$('#div_alert').showAlert({
			message: '{{Déjà Désappairé}}',
			level: 'danger'
		});
	}
});

$('#bt_refresh').on('click', function() {
	var isPaired = $('.eqLogicAttr[data-l1key=configuration][data-l2key=paired]')[0].value;

	if (isPaired=="true") {
		$.ajax({
			type: 'POST',
			url: 'plugins/hkControl/core/ajax/hkControl.ajax.php',
			data: {
				action: 'refresh',
				id: $('.eqLogicAttr[data-l1key=logicalId]')[0].value
			},
			dataType: 'json',
			global: false,
			error: function(request, status, error) {
				failureMsg(error.message);
			},
			success: function(data) {
				if (data && data.result && data.result.result && data.result.result == 'ok') {
					successMsg('{{Refresh réussi}}');
					$('.eqLogicAction[data-action=save]')[0].click();
				} else {
					if(data && data.result) {
						if(data.result.result == 'ok') {
							failureMsg(data.result);
						} else {
							if(data.result.msg) {
								failureMsg(data.result.result+'/'+data.result.msg);
							} else {
								failureMsg(data.result.result);
							}
						}
					} else {
						failureMsg(JSON.stringify(data));
					}
				}
			}
		});
	} 
});
 
function successMsg(msg) {
	$('#verif_pairing').addClass("green");
	$('#verif_logo').removeClass('fa-spinner fa-spin').addClass("fa-check");
	$('#div_alert').showAlert({
		message: msg,
		level: 'success'
	});	
}
function failureMsg(msg) {
	$('#verif_pairing').addClass("red");
	$('#verif_logo').removeClass('fa-spinner fa-spin').addClass("fa-times");
	$('#div_alert').showAlert({
		message: msg,
		level: 'danger'
	});	
}
 
 
 
 
$("#table_cmd").sortable({axis: "y", cursor: "move", items: ".cmd", placeholder: "ui-state-highlight", tolerance: "intersect", forcePlaceholderSize: true});
function addCmdToTable(_cmd) {
    if (!isset(_cmd)) {
        var _cmd = {configuration: {}};
    }
    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td>';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="id" style="display : none;">';
    tr += '<div class="row">';
	tr += '<div class="col-sm-6">';
	tr += '<input class="cmdAttr form-control input-sm" data-l1key="name">';
	tr += '</div>';
	tr += '<div class="col-sm-6">';
	tr += '<a class="cmdAction btn btn-default btn-sm" data-l1key="chooseIcon"><i class="fa fa-flag"></i> Icone</a>';
	tr += '<span class="cmdAttr" data-l1key="display" data-l2key="icon" style="margin-left : 10px;"></span>';
	tr += '</div>';
	tr += '</div>';
    tr += '</td>'; 
	tr += '<td>';
    tr += '<span class="cmdAttr" data-l1key="configuration" data-l2key="aidiid"></span>';
    tr += '</td>'; 
	tr += '<td>';
    tr += '<span class="cmdAttr" data-l1key="configuration" data-l2key="service"></span>';
	if(_cmd.configuration.serviceOriginalName && _cmd.configuration.serviceOriginalName != 'unset' && _cmd.configuration.serviceOriginalName != _cmd.configuration.service) {
			tr += '&nbsp;('+'<span class="cmdAttr" data-l1key="configuration" data-l2key="serviceOriginalName"></span>'+')';
	}
    tr += '</td>'; 
	if (_cmd.configuration.possibleValues && _cmd.configuration.possibleValues != "" && _cmd.type == 'info') {
		tr += '<td>';
		tr += '<span class="cmdAttr" data-l1key="configuration" data-l2key="possibleValues"></span>';
		tr += '</td>'; 		
	} else {
		tr += '<td>';
		tr += '&nbsp;';
		tr += '</td>'; 	
	}
	if(init(_cmd.type) == 'info') {
		tr += '<td>';
		tr += '<input class="form-control input-sm" type="text" data-key="value" placeholder="{{Valeur}}" readonly=true>';
		tr += '</td>';
	} else {
		tr += '<td>';
		tr += '&nbsp;';
		tr += '</td>'; 	
	}
	tr += '<td>';
	if (_cmd.logicalId != 'refresh'){
    tr += '<span><label class="checkbox-inline"><input type="checkbox" class="cmdAttr checkbox-inline" data-l1key="isVisible" checked/>{{Afficher}}</label></span> ';
    }
	if (_cmd.subType == "numeric") {
        tr += '<span><label class="checkbox-inline"><input type="checkbox" class="cmdAttr checkbox-inline" data-l1key="isHistorized" checked/>{{Historiser}}</label></span> ';
    }
	if (_cmd.subType == "binary") {
        tr += '<span><label class="checkbox-inline"><input type="checkbox" class="cmdAttr checkbox-inline" data-l1key="isHistorized" checked/>{{Historiser}}</label></span> ';
    }
	tr += '</td>';
	tr += '<td>';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="type" style="display : none;">';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="subType" style="display : none;">';
    if (is_numeric(_cmd.id)) {
        tr += '<a class="btn btn-default btn-xs cmdAction expertModeVisible" data-action="configure"><i class="fa fa-cogs"></i></a> ';
        tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fa fa-rss"></i> {{Tester}}</a>';
    }
	tr += '<i class="fa fa-minus-circle pull-right cmdAction cursor" data-action="remove"></i></td>';
    tr += '</tr>';
    $('#table_cmd tbody').append(tr);
    $('#table_cmd tbody tr:last').setValues(_cmd, '.cmdAttr');
    jeedom.cmd.changeType($('#table_cmd tbody tr:last'), init(_cmd.subType));
	
	function refreshValue(val,show=true) {
		$('.cmd[data-cmd_id=' + _cmd.id + '] .form-control[data-key=value]').value(val);
		if(show){
			$('.cmd[data-cmd_id=' + _cmd.id + '] .form-control[data-key=value]').attr('style','background-color:#ffff99 !important;');
			setTimeout(function(){
				$('.cmd[data-cmd_id=' + _cmd.id + '] .form-control[data-key=value]').attr('style','');
			},200);
		}
	}

	if (_cmd.id != undefined) {
		if(init(_cmd.type) == 'info') {
			jeedom.cmd.execute({
				id: _cmd.id,
				cache: 0,
				notify: false,
				success: function(result) {
					refreshValue(result,false);
			}});
		
		
			// Set the update value callback
			jeedom.cmd.update[_cmd.id] = function(_options) {
				refreshValue(_options.display_value);
			}
		}
	}	
	
}

$('body').on('hkControl::includeDevice', function(_event,_options) {
    console.log("includeDevice received");
    if (modifyWithoutSave) {
        $('#div_inclusionAlert').showAlert({message: "{{Un périphérique vient d'être inclu. Réactualisation de la page}}", level: 'warning'});
    } else {
            window.location.reload();
        /*} else {
            window.location.href = 'index.php?v=d&p=hkControl&m=hkControl&id=' + _options;*/
        
    }
});

$('.eqLogicAttr[data-l1key=configuration][data-l2key=paired]').on('change',function(a){
	var isPaired = this.value;
	if(isPaired == "true") {$('#bt_Pair').hide();$('#bt_unPair').show();}
	else {$('#bt_unPair').hide();$('#bt_Pair').show();}
});

$('.eqLogicAttr[data-l1key=configuration][data-l2key=type]').on('change',function(a){
	var type = $(this).text();
	if(type){
		if(type == "BridgedAccessory") {
			$('#ipDevice').hide();
			$('#pinDevice').hide();
			$('#refreshBT').hide();
			setTimeout(function(){
				//console.log('toRemove=',$('.eqLogicAttr[data-l1key=configuration][data-l2key=toRemove]'),$('.eqLogicAttr[data-l1key=configuration][data-l2key=toRemove]').val(),$('.eqLogicAttr[data-l1key=configuration][data-l2key=toRemove]')[0].value);
				if($('.eqLogicAttr[data-l1key=configuration][data-l2key=toRemove]')[0].value != "1") {
					$('a[data-action=remove]').hide();
					$('a[data-action=save]').addClass('roundedRight');
				} else {
					$('a[data-action=remove]').show();
					$('a[data-action=save]').removeClass('roundedRight');
				}
			},500);
			//add save roundedRight
		}
		else {
			//if(type == "Bridge") {
				$('#refreshBT').show();
			/*} else {
				$('#refreshBT').hide();
			}*/
			$('#ipDevice').show();
			$('#pinDevice').show();
			$('a[data-action=remove]').show();
			$('a[data-action=save]').removeClass('roundedRight');
			//remove save roundedRight
		}
	}
});

$('.eqLogicAttr[data-l1key=configuration][data-l2key=pin]').on('focusout',function(a){
	var pin = this.value;
	if(pin) {
		this.value=this.value.replace(/-/g,'');
		pin=this.value;
		if (pin.length == 8 && pin.indexOf('-') == -1) {
			this.value=pin.substring(0,3)+'-'+pin.substring(3,5)+'-'+pin.substring(5,8);
			pin=this.value;
		}
		checkPIN(pin,true);	
	}
});


function checkPIN(pin,showAlert=false) {
	if(!pin.match(/^\d\d\d-\d\d-\d\d\d$/)) {
		if(showAlert) {
			$('#div_alert').showAlert({
				message : pin+" : {{Format incorrect (XXX-XX-XXX)}}",
				level : 'danger'
			});
		}
		return false;
	}
	else {
		if(forbiddenPIN.indexOf(pin) != -1) {
			if(showAlert) {
				$('#div_alert').showAlert({
					message : pin+" : {{Code PIN interdit par Apple}}",
					level : 'danger'
				});	
			}
			return false;
		}
		else {
			/*if(showAlert) {
				$('#div_alert').showAlert({
					message : pin+" : {{Format correct}}",
					level : 'success'
				});	
			}*/
			return true;
		}
	}	
}
