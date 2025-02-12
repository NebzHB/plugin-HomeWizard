<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}

sendVarToJS('eqType', 'HomeWizard');
$eqLogics = eqLogic::byType('HomeWizard');

?>
<style>
	.eqLogicAttr[data-l2key=serial] {
		filter: blur(3px);
	}
	.eqLogicAttr[data-l2key=serial]:hover, .eqLogicAttr[data-l2key=serial]:empty {
		filter: blur(0px);
	}
</style>
<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i>  {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor logoSecondary eqLogicAction" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br />
				<span>{{Configuration}}</span>
			</div>
			<div class="cursor logoSecondary" id="bt_healthHomeWizard">
				<i class="fas fa-medkit"></i>
				</br />
				<span>{{Santé}}</span>
			</div>
			<?php
			$jeedomVersion=jeedom::version() ?? '0';
			$displayInfo=version_compare($jeedomVersion, '4.4.0', '>=');
			if($displayInfo){
				echo "<div class=\"cursor eqLogicAction warning\" data-action=\"createCommunityPost\" title=\"{{Ouvrir une demande d'aide sur le forum communautaire}}\">";
				echo '<i class="fas fa-ambulance"></i><br>';
				echo '<span>{{Community}}</span>';
				echo '</div>';
			}
			?>
		</div>
		<legend><i class="fas fa-bolt"></i>  {{Mes Equipements HomeWizard}}</legend>
		<div class="input-group" style="margin-bottom:5px;">
			<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic1" />
			<div class="input-group-btn">
				<a id="bt_resetEqlogicSearch1" class="btn roundedRight" style="width:30px"><i class="fas fa-times"></i></a>
			</div>
		</div>
		<div class="panel">
			<div class="panel-body">
				<div class="eqLogicThumbnailContainer cont1">
					<?php
					foreach ($eqLogics as $eqLogic) {
						$opacity = ($eqLogic->getIsEnable()) ? '' : ' disableCard';
						$img=$eqLogic->getImage();
						echo '<div class="eqLogicDisplayCard cursor cont1'.$opacity.'" data-eqLogic_id="' . $eqLogic->getId() . '">';
						echo '<img class="lazy" src="'.$img.'" style="min-height:75px !important;" />';	
						echo "<br />";
						echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
						echo '</div>';
					}
					?>
				</div>
			</div>
		</div>
	</div>
	<div class="col-lg-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default eqLogicAction btn-sm roundedLeft" data-action="configure"><i class="fas fa-cogs"></i> {{Configuration avancée}}</a><a class="btn btn-success eqLogicAction btn-sm" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a><a class="btn btn-danger btn-sm eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a class="eqLogicAction cursor" aria-controls="home" role="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipement}}</a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list-alt"></i> {{Commandes}}</a></li>
		</ul>
		<div class="tab-content" style="height:calc(100% - 50px);overflow:auto;overflow-x: hidden;">
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<div class="row">
					<div class="col-sm-6">
						<form class="form-horizontal">
							<fieldset>
								<div class="form-group">
									</br>
									<label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
									<div class="col-sm-6">
										<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display : none;" />
										<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}"/>
										<input type="text" class="eqLogicAttr form-control" data-l1key="logicalId" style="display : none;" />
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label" >{{Objet parent}}</label>
									<div class="col-sm-6">
										<select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
											<option value="">{{Aucun}}</option>
											<?php
											foreach ((jeeObject::buildTree(null, false)) as $object) {
												echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
											}
											?>
										</select>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">&nbsp;</label>
									<div class="col-sm-6">
										<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Activer}}</label>
										<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}</label>
									</div>
								</div>
								<br />
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Catégorie}}</label>
									<div class="col-sm-6">
										<?php
										foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
											echo '<label class="checkbox-inline">';
											echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" />' . $value['name'];
											echo '</label>';
										}
										?>

									</div>
								</div>
								<br />
								<div class="form-group" id="ipDevice">
									<label class="col-sm-4 control-label">{{Adresse Ip}}</label>
									<div class="col-sm-6">
										<input type="text" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="address" placeholder="{{Adresse Ip}}" readonly />
									</div>
								</div>
							</fieldset>
						</form>
					</div>
					<br />
					<div class="col-sm-6">
						<form class="form-horizontal">
							<fieldset>
								<div class="form-group">
									<label class="col-sm-3 control-label">{{Type}}</label>
									<div class="col-sm-3">
										<span class="eqLogicAttr label label-default" data-l1key="configuration" data-l2key="type"></span>
									</div>
									<label class="col-sm-3 control-label">{{Hostname}}</label>
									<div class="col-sm-3">
										<span class="eqLogicAttr label label-default" data-l1key="configuration" data-l2key="hostname"></span>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-3 control-label">{{Numéro de série}}</label>
									<div class="col-sm-3">
										<span class="eqLogicAttr label label-default" data-l1key="configuration" data-l2key="serial"></span>
									</div>
									<label class="col-sm-3 control-label">{{Version Firmware}}</label>
									<div class="col-sm-3">
										<span class="eqLogicAttr label label-default" data-l1key="configuration" data-l2key="firmware_version"></span>
									</div>
								</div>
							</fieldset>
						</form>
					</div>
				</div>
			</div>
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<legend><i class="fas fa-list-alt"></i>  {{Tableau de commandes}}</legend>
				<table id="table_cmd" class="table table-bordered table-condensed">
					<thead>
						<tr>
							<th>{{Nom}}</th>
							<th>{{Service}}</th>
							<th>{{Valeurs possibles}}</th>
							<th>{{Valeur}}</th>
							<th>{{Action}}</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</div>
<?php include_file('desktop', 'HomeWizard', 'js', 'HomeWizard'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
