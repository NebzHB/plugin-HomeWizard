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


 $('#bt_healthHomeWizard').on('click', function() {
    $('#md_modal').dialog({title: "{{Santé HomeWizard}}"});
    $('#md_modal').load('index.php?v=d&plugin=HomeWizard&modal=health').dialog('open');
});

function openMigrationHomeWizard(_eqLogicId) {
    $('#md_modal').dialog({title: "{{Migration vers l'API v2 HomeWizard}}"});
    $('#md_modal').load('index.php?v=d&plugin=HomeWizard&modal=migration' + ((_eqLogicId) ? '&eqLogic_id=' + _eqLogicId : '')).dialog('open');
}

$('#bt_migrationHomeWizard').on('click', function() {
    openMigrationHomeWizard();
});

$('#bt_migrateEqLogic').on('click', function() {
    if (modifyWithoutSave) {
        jeedomUtils.showAlert({message: "{{Sauvegardez d'abord vos modifications}}", level: 'warning'});
        return;
    }
    openMigrationHomeWizard($('.eqLogicAttr[data-l1key=id]').value());
});

// appelé par Jeedom à l'affichage d'un équipement
function printEqLogic(_eqLogic) {
    var conf = _eqLogic.configuration || {};
    var isV2 = (conf.apiVersion == 'v2');
    var v2Types = ['HWE-P1', 'SDM230-wifi', 'HWE-KWH1', 'SDM630-wifi', 'HWE-KWH3', 'HWE-BAT'];
    var supported = (v2Types.indexOf(conf.type) >= 0);
    var info = '';
    $('#span_apiVersion').text((isV2) ? 'v2' : 'v1').toggleClass('label-success', isV2).toggleClass('label-default', !isV2);
    if (isV2 && conf.tokenInvalid == 1) {
        info = "{{Token refusé par l'équipement, migration à refaire}}";
    } else if (!isV2 && !supported) {
        info = "{{HomeWizard ne propose pas encore l'API v2 pour ce type}}";
    } else if (!isV2 && conf.v2Available != 1) {
        info = "{{L'équipement n'annonce pas l'API v2 : mettez son firmware à jour}}";
    }
    $('#span_apiInfo').text(info);
    $('#bt_migrateEqLogic').toggle(supported && conf.v2Available == 1 && (!isV2 || conf.tokenInvalid == 1));
}

$('#bt_resetEqlogicSearch').on('click', function() {
  $('#in_searchEqlogic').val('');
  $('#in_searchEqlogic').keyup();
});

$('.eqLogicAction[data-action=createCommunityPost]').on('click', function (event) {
    jeedom.plugin.createCommunityPost({
      type: eqType,
      error: function(error) {
        domUtils.hideLoading()
        jeedomUtils.showAlert({
          message: error.message,
          level: 'danger'
        })
      },
      success: function(data) {
        let element = document.createElement('a');
        element.setAttribute('href', data.url);
        element.setAttribute('target', '_blank');
        element.style.display = 'none';
        document.body.appendChild(element);
        element.click();
        document.body.removeChild(element);
      }
    });
    return;
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
	
	function refreshValue(val, show=true, unit='') {
		$('.cmd[data-cmd_id=' + _cmd.id + '] .form-control[data-key=value]').value(val+((unit)?' '+unit:''));
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
					refreshValue(result,false, _cmd.unite);
			}});
		
		
			// Set the update value callback
			jeedom.cmd.update[_cmd.id] = function(_options) {
				refreshValue(_options.display_value,true,_options.unit);
			}
		}
	}	
	
}

$('body').on('HomeWizard::includeDevice', function(_event,_options) {
    console.log("includeDevice received");
    if (modifyWithoutSave) {
        $('#div_inclusionAlert').showAlert({message: "{{Un périphérique vient d'être inclu. Réactualisation de la page}}", level: 'warning'});
    } else {
        window.location.reload();
    }
});



