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
$hwMigrationList = HomeWizard::migrationList();
$hwMigrationStart = init('eqLogic_id');
?>
<style>
	#div_hwMigration .hwMig_steps { display:flex; justify-content:center; gap:8px; margin:4px 0 18px 0; }
	#div_hwMigration .hwMig_step { display:flex; align-items:center; gap:8px; opacity:.45; }
	#div_hwMigration .hwMig_step.active, #div_hwMigration .hwMig_step.done { opacity:1; }
	#div_hwMigration .hwMig_step .num { width:26px; height:26px; line-height:26px; border-radius:50%; text-align:center; font-weight:bold; color:#fff; background:var(--al-info-color, #2e9bff); }
	#div_hwMigration .hwMig_step.done .num { background:var(--al-success-color, #94ca02); }
	#div_hwMigration .hwMig_step + .hwMig_step::before { content:''; width:36px; height:2px; margin-right:8px; background:currentColor; opacity:.3; }
	#div_hwMigration .hwMig_view { display:none; }
	#div_hwMigration .hwMig_view.active { display:block; }
	#div_hwMigration #table_hwMigration img { height:44px; }
	#div_hwMigration #table_hwMigration td { vertical-align:middle; }
	#div_hwMigration .hwMig_press { display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:24px; }
	#div_hwMigration .hwMig_press .illu { flex:0 1 340px; }
	#div_hwMigration .hwMig_device { position:relative; border-radius:18px; background:#eef1f5; overflow:hidden; }
	#div_hwMigration .hwMig_device img { display:block; width:100%; height:auto; }
	#div_hwMigration .hwMig_overlay { position:absolute; inset:0; width:100%; height:100%; display:none; }
	#div_hwMigration .hwMig_overlay.press, #div_hwMigration .hwMig_overlay.hold { display:block; }
	#div_hwMigration .hwMig_overlay .ripple, #div_hwMigration .hwMig_overlay .ring, #div_hwMigration .hwMig_overlay .holdLabel { opacity:0; }
	#div_hwMigration .hwMig_overlay .ripple { transform-box:fill-box; transform-origin:center; }
	#div_hwMigration .hwMig_overlay .ring { stroke-dasharray:201; }
	#div_hwMigration .hwMig_overlay.press .hand { animation:hwMigHandPress 3.2s ease-in-out infinite; }
	#div_hwMigration .hwMig_overlay.press .ripple { animation:hwMigRipplePress 3.2s ease-out infinite; }
	#div_hwMigration .hwMig_overlay.hold .hand { animation:hwMigHandHold 4.2s ease-in-out infinite; }
	#div_hwMigration .hwMig_overlay.hold .ripple { animation:hwMigRippleHold 4.2s ease-out infinite; }
	#div_hwMigration .hwMig_overlay.hold .ring { animation:hwMigRing 4.2s linear infinite; }
	#div_hwMigration .hwMig_overlay.hold .holdLabel { animation:hwMigHoldLabel 4.2s linear infinite; }
	@keyframes hwMigHandPress { 0%,15% { transform:translate(44px,50px); } 32%,44% { transform:translate(0,0); } 62%,100% { transform:translate(44px,50px); } }
	@keyframes hwMigRipplePress { 0%,43% { transform:scale(.4); opacity:0; } 44% { transform:scale(.4); opacity:.7; } 80%,100% { transform:scale(1.5); opacity:0; } }
	@keyframes hwMigHandHold { 0%,12% { transform:translate(44px,50px); } 26%,74% { transform:translate(0,0); } 90%,100% { transform:translate(44px,50px); } }
	@keyframes hwMigRippleHold { 0%,74% { transform:scale(.4); opacity:0; } 75% { transform:scale(.4); opacity:.7; } 100% { transform:scale(1.5); opacity:0; } }
	@keyframes hwMigRing { 0%,27% { stroke-dashoffset:201; opacity:0; } 28% { opacity:1; } 72% { stroke-dashoffset:0; opacity:1; } 80%,100% { stroke-dashoffset:0; opacity:0; } }
	@keyframes hwMigHoldLabel { 0%,24% { opacity:0; } 30%,72% { opacity:1; } 80%,100% { opacity:0; } }
	@media (prefers-reduced-motion:reduce) { #div_hwMigration .hwMig_overlay * { animation:none !important; } #div_hwMigration .hwMig_overlay.hold .holdLabel { opacity:1; } }
	#div_hwMigration .hwMig_press .todo { flex:1 1 320px; max-width:460px; }
	#div_hwMigration .hwMig_press ol { padding-left:22px; font-size:15px; line-height:1.5; }
	#div_hwMigration .hwMig_press ol li { margin-bottom:8px; }
	#div_hwMigration .hwMig_press ol li.main { font-weight:bold; font-size:17px; }
	#div_hwMigration .hwMig_wait { display:flex; align-items:center; gap:14px; margin:16px 0; }
	#div_hwMigration .hwMig_countdown { position:relative; width:76px; height:76px; flex:none; }
	#div_hwMigration .hwMig_countdown svg { width:76px; height:76px; transform:rotate(-90deg); }
	#div_hwMigration .hwMig_countdown circle { fill:none; stroke-width:6; }
	#div_hwMigration .hwMig_countdown .track { stroke:currentColor; opacity:.15; }
	#div_hwMigration .hwMig_countdown .bar { stroke:var(--al-info-color, #2e9bff); stroke-linecap:round; stroke-dasharray:213.6; transition:stroke-dashoffset 1s linear; }
	#div_hwMigration .hwMig_countdown.ending .bar { stroke:var(--al-warning-color, #ff9b00); }
	#div_hwMigration .hwMig_countdown .sec { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:bold; }
	#div_hwMigration .hwMig_result { text-align:center; padding:10px 0 4px 0; }
	#div_hwMigration .hwMig_result .icon { font-size:64px; margin-bottom:8px; }
	#div_hwMigration .hwMig_result .icon.ok { color:var(--al-success-color, #94ca02); animation:hwMigPop .45s ease-out; }
	#div_hwMigration .hwMig_result .icon.ko { color:var(--al-warning-color, #ff9b00); }
	#div_hwMigration .hwMig_result h3 { margin:4px 0 10px 0; }
	#div_hwMigration .hwMig_result ul { display:inline-block; text-align:left; margin-bottom:14px; }
	@keyframes hwMigPop { 0% { transform:scale(.3); opacity:0; } 70% { transform:scale(1.15); opacity:1; } 100% { transform:scale(1); } }
	@media (prefers-reduced-motion:reduce) { #div_hwMigration .hwMig_result .icon.ok { animation:none; } #div_hwMigration .hwMig_countdown .bar { transition:none; } }
</style>
<div id="div_hwMigration">
	<div class="hwMig_steps">
		<div class="hwMig_step" data-step="1"><span class="num">1</span><span>{{Choix de l'équipement}}</span></div>
		<div class="hwMig_step" data-step="2"><span class="num">2</span><span>{{Appui sur le bouton}}</span></div>
		<div class="hwMig_step" data-step="3"><span class="num">3</span><span>{{Terminé}}</span></div>
	</div>

	<!-- Etape 1 : liste des équipements -->
	<div class="hwMig_view" data-view="list">
		<div class="alert alert-info">
			{{HomeWizard remplace son API locale v1 par l'API v2 : connexion chiffrée (https) et mesures poussées en temps réel par l'équipement.}}
			{{Pour autoriser Jeedom, il faut appuyer une fois sur le bouton de l'équipement : l'assistant vous guide et attend votre appui.}}
			{{Vos commandes sont conservées avec leur historique, vos scénarios et designs continuent de fonctionner.}}
			{{Un nouvel équipement, ou un équipement qui n'existe qu'en API v2 (Plug-In Battery), n'a rien à migrer : il a seulement besoin de cette autorisation pour envoyer ses valeurs.}}
		</div>
		<a class="btn btn-success pull-right" id="bt_hwMigAll" style="display:none;"><i class="fas fa-magic"></i> {{Enchainer tous les équipements possibles}} (<span class="nb"></span>)</a>
		<br /><br />
		<table class="table table-condensed" id="table_hwMigration">
			<thead>
				<tr>
					<th></th>
					<th>{{Équipement}}</th>
					<th>{{Type}}</th>
					<th>{{API utilisée}}</th>
					<th>{{État}}</th>
					<th></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>

	<!-- Etape 2 : attente de l'appui sur le bouton -->
	<div class="hwMig_view" data-view="press">
		<div class="hwMig_press">
			<div class="illu">
				<div class="hwMig_device">
					<img id="img_hwMigIllu" src="" alt="" />
					<!-- doigt animé superposé à l'image officielle, placé sur le bouton (coordonnées de l'image : 400 x 400) -->
					<svg class="hwMig_overlay" viewBox="0 0 400 400" aria-hidden="true">
						<g class="anchor">
							<circle class="ripple" r="52" fill="none" stroke="#35d07f" stroke-width="5"></circle>
							<circle class="ring" r="32" fill="none" stroke="#2f3640" stroke-width="5" stroke-linecap="round" transform="rotate(-90)"></circle>
							<g class="holdLabel"><rect x="44" y="-58" width="84" height="34" rx="17" fill="#2f3640"></rect><text x="86" y="-35" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="19" font-weight="bold" fill="#fff">1–3 s</text></g>
							<g class="hand"><g transform="rotate(-28) scale(1.5)">
								<rect x="-25" y="46" width="52" height="46" rx="16" fill="#fff" stroke="#4a5360" stroke-width="3"></rect>
								<ellipse cx="-27" cy="60" rx="9" ry="15" fill="#fff" stroke="#4a5360" stroke-width="3" transform="rotate(-18 -27 60)"></ellipse>
								<rect x="-10" y="-3" width="20" height="66" rx="10" fill="#fff" stroke="#4a5360" stroke-width="3"></rect>
								<rect x="-5" y="2" width="10" height="9" rx="4" fill="#dfe4ea"></rect>
								<path d="M4 62v18M16 64v16" stroke="#4a5360" stroke-width="2.5" stroke-linecap="round" fill="none"></path>
							</g></g>
						</g>
					</svg>
				</div>
			</div>
			<div class="todo">
				<h3 id="hwMig_pressName"></h3>
				<ol>
					<li>{{Allez près de l'équipement.}}</li>
					<li class="main" data-family="p1">{{Appuyez une fois sur le bouton du P1 Meter.}}</li>
					<li class="main" data-family="kwh">{{Maintenez le bouton du kWh Meter enfoncé entre 1 et 3 secondes, puis relâchez.}}</li>
					<li class="main" data-family="bat">{{Appuyez une fois sur le bouton de la batterie.}}</li>
					<li>{{C'est tout : Jeedom détecte l'appui automatiquement.}}</li>
				</ol>
				<div class="hwMig_wait">
					<div class="hwMig_countdown">
						<svg viewBox="0 0 76 76"><circle class="track" cx="38" cy="38" r="34"></circle><circle class="bar" cx="38" cy="38" r="34"></circle></svg>
						<div class="sec"></div>
					</div>
					<div>
						<i class="fas fa-spinner fa-spin"></i> {{En attente de l'appui sur le bouton...}}<br />
					</div>
				</div>
				<div class="alert alert-warning hwMig_stale" style="display:none;">
					<i class="fas fa-exclamation-triangle"></i> {{Exception pour les kWh Meters : l'API v2 ne fournit plus les index par tarif, seulement les totaux.}}
					{{Ces commandes ne seront plus mises à jour après la migration (elles sont conservées avec leur historique) :}}
					<strong class="cmds"></strong>.
					{{Utilisez à la place les commandes des totaux (import et export en kWh).}}
				</div>
				<a class="btn btn-default" id="bt_hwMigCancel"><i class="fas fa-times"></i> {{Annuler}}</a>
			</div>
		</div>
	</div>

	<!-- Etape 3 : résultat -->
	<div class="hwMig_view" data-view="done">
		<div class="hwMig_result">
			<div class="icon ok"><i class="fas fa-check-circle"></i></div>
			<h3 id="hwMig_doneTitle"></h3>
			<p><span id="hwMig_doneName"></span> <span id="hwMig_doneText"></span> <span id="hwMig_doneRenamed"></span></p>
			<div class="alert alert-warning hwMig_stale" style="display:none;text-align:left;">
				<i class="fas fa-exclamation-triangle"></i> {{Exception pour les kWh Meters : ces commandes ne sont plus mises à jour par l'API v2 (index par tarif) :}}
				<strong class="cmds"></strong>.
				{{Utilisez à la place les commandes des totaux (import et export en kWh).}}
			</div>
			<a class="btn btn-success" id="bt_hwMigNext" style="display:none;"><i class="fas fa-arrow-right"></i> {{Équipement suivant}} (<span class="nb"></span>)</a>
			<a class="btn btn-default" id="bt_hwMigBackDone"><i class="fas fa-list"></i> {{Retour à la liste}}</a>
			<a class="btn btn-primary" id="bt_hwMigFinish"><i class="fas fa-check"></i> {{Terminer}}</a>
		</div>
	</div>
	<div class="hwMig_view" data-view="fail">
		<div class="hwMig_result">
			<div class="icon ko"><i class="fas fa-hourglass-end"></i></div>
			<h3 id="hwMig_failTitle"></h3>
			<p id="hwMig_failDetail"></p>
			<ul id="hwMig_failHints">
				<li>{{Le bouton a-t-il bien été appuyé pendant le décompte ?}}</li>
				<li data-family="kwh">{{Sur le kWh Meter, le bouton doit rester enfoncé entre 1 et 3 secondes.}}</li>
				<li>{{L'équipement doit être allumé et sur le même réseau que Jeedom.}}</li>
			</ul>
			<br />
			<a class="btn btn-success" id="bt_hwMigRetry"><i class="fas fa-redo"></i> {{Réessayer}}</a>
			<a class="btn btn-default" id="bt_hwMigBackFail"><i class="fas fa-list"></i> {{Retour à la liste}}</a>
		</div>
	</div>
</div>
<script>
(function() {
	var list = <?= json_encode($hwMigrationList) ?>;
	var autoStart = <?= json_encode($hwMigrationStart) ?>;
	var current = null; // équipement en cours d'appairage
	var queue = []; // "Migrer tous" : équipements restants
	var countdownTimer = null;
	var remaining = 0;
	var total = 0;
	var changed = false;
	var states = {
		v2: {label: 'success', text: "{{Migré en API v2}}"},
		invalid: {label: 'danger', text: "{{Token refusé par l'équipement, autorisation à refaire}}"},
		ready: {label: 'info', text: "{{Migration possible}}"},
		// rien à migrer (Plug-In Battery, ou nouvel équipement ajouté directement en API v2) : il faut juste autoriser Jeedom par le bouton
		pair: {label: 'info', text: "{{Autorisation nécessaire : appui sur le bouton de l'équipement}}"},
		v2only: {label: 'success', text: "{{Autorisé (API v2 uniquement)}}"},
		firmware: {label: 'warning', text: "{{L'équipement n'annonce pas l'API v2 : mettez son firmware à jour}}"},
		unsupported: {label: 'default', text: "{{HomeWizard ne propose pas encore l'API v2 pour ce type}}"}
	};

	function esc(text) {
		return $('<div>').text((text === null || text === undefined) ? '' : text).html();
	}

	function byId(id) {
		for (var i = 0; i < list.length; i++) {
			if (list[i].id == id) { return list[i]; }
		}
		return null;
	}

	function canStart(eq) {
		return eq.state == 'ready' || eq.state == 'pair' || eq.state == 'invalid';
	}

	function migrable() {
		return list.filter(function(eq) { return canStart(eq); });
	}

	function showView(view) {
		var step = {list: 1, press: 2, done: 3, fail: 2}[view];
		$('#div_hwMigration .hwMig_view').removeClass('active').filter('[data-view=' + view + ']').addClass('active');
		$('#div_hwMigration .hwMig_step').each(function() {
			var n = $(this).data('step');
			$(this).toggleClass('active', n == step).toggleClass('done', n < step || view == 'done');
		});
	}

	function renderList() {
		var html = '';
		list.forEach(function(eq) {
			var state = states[eq.state] || states.unsupported;
			if (eq.v2Only && eq.state == 'v2') { state = states.v2only; }
			html += '<tr data-id="' + esc(eq.id) + '">';
			html += '<td><img src="' + esc(eq.image) + '" /></td>';
			html += '<td>' + eq.name + '</td>'; // getHumanName : déjà du html Jeedom
			html += '<td><span class="label label-default">' + esc(eq.type) + '</span></td>';
			html += '<td><span class="label label-' + ((eq.apiVersion == 'v2') ? 'success' : 'default') + '">' + esc(eq.apiVersion || 'v1') + '</span></td>';
			html += '<td><span class="label label-' + state.label + '" style="white-space:normal;">' + state.text + '</span>';
			if (canStart(eq) && eq.staleCmds && eq.staleCmds.length > 0) {
				html += '<br /><small><i class="fas fa-exclamation-triangle"></i> {{Ne seront plus mises à jour en API v2 :}} ' + esc(eq.staleCmds.join(', ')) + '</small>';
			}
			html += '</td>';
			html += '<td style="white-space:nowrap;">';
			if (canStart(eq)) {
				var action = (eq.state == 'invalid') ? "{{Refaire l'autorisation}}" : ((eq.authOnly) ? "{{Autoriser}}" : "{{Migrer}}");
				html += '<a class="btn btn-success btn-sm bt_hwMigStart"><i class="fas ' + ((eq.state == 'ready') ? 'fa-exchange-alt' : 'fa-key') + '"></i> ' + action + '</a> ';
			}
			if (eq.canRevert) {
				html += '<a class="btn btn-default btn-xs bt_hwMigRevert"><i class="fas fa-undo"></i> {{Revenir à l\'API v1}}</a>';
			}
			html += '</td></tr>';
		});
		if (list.length == 0) {
			html = '<tr><td colspan="6">{{Aucun équipement HomeWizard.}}</td></tr>';
		}
		$('#table_hwMigration tbody').html(html);
		var nb = migrable().length;
		$('#bt_hwMigAll').toggle(nb > 1).find('.nb').text(nb);
	}

	function refreshList(callback) {
		$.ajax({
			type: 'POST',
			url: 'plugins/HomeWizard/core/ajax/HomeWizard.ajax.php',
			data: { action: 'migrationList' },
			dataType: 'json',
			global: false,
			error: function(request, status, error) {
				handleAjaxError(request, status, error);
			},
			success: function(data) {
				if (data.state == 'ok') {
					list = data.result;
					renderList();
				}
				if (callback) { callback(); }
			}
		});
	}

	function stopCountdown() {
		if (countdownTimer) {
			clearInterval(countdownTimer);
			countdownTimer = null;
		}
	}

	function drawCountdown() {
		var shown = Math.max(remaining, 0);
		$('#div_hwMigration .hwMig_countdown .sec').text(shown);
		$('#div_hwMigration .hwMig_countdown .bar').css('stroke-dashoffset', 213.6 * (1 - (shown / total)));
		$('#div_hwMigration .hwMig_countdown').toggleClass('ending', shown <= 15);
	}

	function startCountdown(seconds) {
		stopCountdown();
		total = seconds;
		remaining = seconds;
		drawCountdown();
		countdownTimer = setInterval(function() {
			remaining--;
			drawCountdown();
			// le démon prévient au timeout, ceci n'est qu'une sécurité s'il ne répond plus
			if (remaining <= -8) {
				showFail('timeout', '');
			}
		}, 1000);
	}

	function startPairing(id) {
		var eq = byId(id);
		if (!eq) { return; }
		$.ajax({
			type: 'POST',
			url: 'plugins/HomeWizard/core/ajax/HomeWizard.ajax.php',
			data: { action: 'startPairing', id: id },
			dataType: 'json',
			global: false,
			error: function(request, status, error) {
				handleAjaxError(request, status, error);
			},
			success: function(data) {
				if (data.state != 'ok') {
					queue = [];
					jeedomUtils.showAlert({ message: data.result, level: 'danger' });
					showView('list');
					return;
				}
				current = eq;
				var illu = data.result.illustration;
				$('#img_hwMigIllu').attr('src', illu.image);
				// image officielle : on place le doigt animé sur le bouton (type sans position connue : image seule)
				var hasButton = (illu.x !== null && illu.y !== null);
				$('#div_hwMigration .hwMig_overlay').removeClass('press hold').toggleClass((illu.hold) ? 'hold' : 'press', hasButton);
				$('#div_hwMigration .hwMig_overlay .anchor').attr('transform', 'translate(' + (illu.x * 4) + ' ' + (illu.y * 4) + ')');
				// bouton à droite de l'image : l'étiquette "1–3 s" passe à gauche pour rester visible
				$('#div_hwMigration .hwMig_overlay .holdLabel').attr('transform', (illu.x > 55) ? 'translate(-172 0)' : '');
				var stale = data.result.staleCmds || [];
				$('#div_hwMigration .hwMig_stale').toggle(stale.length > 0).find('.cmds').text(stale.join(', '));
				$('#hwMig_pressName').html(eq.name);
				$('#div_hwMigration [data-family]').hide().filter('[data-family=' + data.result.family + ']').show();
				showView('press');
				startCountdown(parseInt(data.result.timeout));
			}
		});
	}

	function cancelPairing(callback) {
		stopCountdown();
		if (!current) {
			if (callback) { callback(); }
			return;
		}
		var id = current.id;
		current = null;
		$.ajax({
			type: 'POST',
			url: 'plugins/HomeWizard/core/ajax/HomeWizard.ajax.php',
			data: { action: 'cancelPairing', id: id },
			dataType: 'json',
			global: false,
			complete: function() {
				if (callback) { callback(); }
			}
		});
	}

	function showDone(renamed) {
		var eq = current;
		stopCountdown();
		current = null;
		changed = true;
		var auth = (eq.authOnly || eq.state == 'invalid');
		$('#hwMig_doneTitle').text((auth) ? "{{Autorisation réussie}}" : "{{Migration réussie}}");
		$('#hwMig_doneText').text((auth) ? "{{est maintenant autorisé, ses valeurs arrivent.}}" : "{{utilise maintenant l'API v2.}}");
		$('#hwMig_doneName').html(eq.name);
		$('#hwMig_doneRenamed').text((renamed > 0) ? '(' + renamed + ' {{commandes renommées, historique conservé}})' : '');
		queue = queue.filter(function(id) { return id != eq.id; });
		refreshList(function() {
			// "Migrer tous" en cours : on enchaine, sinon on propose les équipements encore migrables
			var next = (queue.length > 0) ? queue : migrable().map(function(m) { return m.id; });
			$('#bt_hwMigNext').toggle(next.length > 0).data('next', next[0]).find('.nb').text(next.length);
			$('#bt_hwMigFinish').toggleClass('btn-primary', next.length == 0).toggleClass('btn-default', next.length > 0);
			showView('done');
		});
	}

	function showFail(reason, detail) {
		var eq = current;
		stopCountdown();
		current = null;
		if (!eq) { return; }
		$('#bt_hwMigRetry').data('id', eq.id);
		$('#hwMig_failTitle').text((reason == 'timeout') ? "{{Aucun appui détecté dans le temps imparti}}" : ((eq.authOnly || eq.state == 'invalid') ? "{{L'autorisation n'a pas abouti}}" : "{{La migration n'a pas abouti}}"));
		$('#hwMig_failDetail').text((reason == 'timeout') ? '' : detail);
		$('#hwMig_failHints').toggle(reason == 'timeout').find('[data-family]').hide().filter('[data-family=' + eq.family + ']').show();
		showView('fail');
	}

	// résultat envoyé par le démon quand le bouton est appuyé (ou au timeout)
	$('body').off('HomeWizard::pairResult').on('HomeWizard::pairResult', function(_event, _options) {
		if (!current || !_options || _options.id != current.id) { return; }
		if (_options.result == 'ok') {
			showDone(parseInt(_options.renamed) || 0);
		} else if (_options.reason != 'aborted') {
			showFail(_options.reason, _options.error);
		}
	});

	$('#div_hwMigration').off('click').on('click', '.bt_hwMigStart', function() {
		queue = [];
		startPairing($(this).closest('tr').data('id'));
	}).on('click', '.bt_hwMigRevert', function() {
		var id = $(this).closest('tr').data('id');
		var dialog = (typeof jeeDialog !== 'undefined') ? jeeDialog : bootbox;
		dialog.confirm("{{Revenir à l'API v1 pour cet équipement ? L'autorisation de Jeedom sera supprimée de l'équipement, les commandes et leur historique sont conservés.}}", function(result) {
			if (!result) { return; }
			$.ajax({
				type: 'POST',
				url: 'plugins/HomeWizard/core/ajax/HomeWizard.ajax.php',
				data: { action: 'revertToV1', id: id },
				dataType: 'json',
				global: false,
				error: function(request, status, error) {
					handleAjaxError(request, status, error);
				},
				success: function(data) {
					if (data.state != 'ok') {
						jeedomUtils.showAlert({ message: data.result, level: 'danger' });
						return;
					}
					changed = true;
					jeedomUtils.showAlert({ message: "{{Équipement revenu en API v1}}", level: 'success' });
					refreshList();
				}
			});
		});
	});

	$('#bt_hwMigAll').off('click').on('click', function() {
		queue = migrable().map(function(eq) { return eq.id; });
		startPairing(queue[0]);
	});
	$('#bt_hwMigCancel').off('click').on('click', function() {
		queue = [];
		cancelPairing(function() { showView('list'); });
	});
	$('#bt_hwMigNext').off('click').on('click', function() {
		startPairing($(this).data('next'));
	});
	$('#bt_hwMigRetry').off('click').on('click', function() {
		startPairing($(this).data('id'));
	});
	$('#bt_hwMigBackDone, #bt_hwMigBackFail').off('click').on('click', function() {
		queue = [];
		refreshList(function() { showView('list'); });
	});
	$('#bt_hwMigFinish').off('click').on('click', function() {
		$('#md_modal').dialog('close');
	});

	// fermeture de la fenêtre : on n'attend plus le bouton, et la page est rechargée si un équipement a changé d'API
	$('#md_modal').off('dialogclose.hwMigration').on('dialogclose.hwMigration', function() {
		$('#md_modal').off('dialogclose.hwMigration');
		$('body').off('HomeWizard::pairResult');
		cancelPairing(function() {
			if (changed) { window.location.reload(); }
		});
	});

	renderList();
	showView('list');
	if (autoStart && byId(autoStart) && canStart(byId(autoStart))) {
		startPairing(autoStart);
	}
})();
</script>
