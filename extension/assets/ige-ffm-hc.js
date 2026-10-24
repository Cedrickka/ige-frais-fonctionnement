/*
 * Saisie hors connexion (IGE · Frais de fonctionnement) : service worker, file d'attente sur le
 * téléphone (IndexedDB), envoi automatique au retour du réseau. Aucune donnée ne quitte le
 * téléphone ailleurs que vers l'application.
 */
/* global IGE_HC */
(function () {
	'use strict';
	if (typeof IGE_HC === 'undefined') { return; }
	var BASE = 'ige-ffm', STORE = 'file';

	/* ---------- Service worker ---------- */
	if ('serviceWorker' in navigator) {
		navigator.serviceWorker.register(IGE_HC.sw, { scope: '/' }).then(function (reg) {
			var surSaisie = document.getElementById('ige-saisie') || document.getElementById('ige-hc');
			var dernier = 0;
			try { dernier = parseInt(localStorage.getItem('ige-hc-prep') || '0', 10); } catch (e) { dernier = 0; }
			if (surSaisie && navigator.onLine && Date.now() - dernier > 3600000) {
				var envoyerMsg = function (sw) { if (sw) { sw.postMessage({ type: 'prechauffer', urls: [IGE_HC.saisie, IGE_HC.page] }); } };
				envoyerMsg(reg.active || reg.waiting || reg.installing);
				try { localStorage.setItem('ige-hc-prep', String(Date.now())); } catch (e) { /* stockage indisponible */ }
			}
		}).catch(function () { /* navigateur sans service worker : la saisie en ligne reste possible */ });
	}

	/* ---------- IndexedDB ---------- */
	function ouvrir() {
		return new Promise(function (ok, ko) {
			if (!('indexedDB' in window)) { ko(new Error('indexedDB')); return; }
			var r = indexedDB.open(BASE, 1);
			r.onupgradeneeded = function () { r.result.createObjectStore(STORE, { keyPath: 'cle' }); };
			r.onsuccess = function () { ok(r.result); };
			r.onerror = function () { ko(r.error); };
		});
	}
	function tx(mode, fn) {
		return ouvrir().then(function (db) {
			return new Promise(function (ok, ko) {
				var t = db.transaction(STORE, mode), s = t.objectStore(STORE), res = fn(s);
				t.oncomplete = function () { ok(res && res.result !== undefined ? res.result : res); };
				t.onerror = function () { ko(t.error); };
			});
		});
	}
	function tous() {
		return tx('readonly', function (s) { return s.getAll(); }).then(function (l) {
			return (l || []).filter(function (x) { return x.user === IGE_HC.user; }).sort(function (a, b) { return a.cree < b.cree ? -1 : 1; });
		}).catch(function () { return []; });
	}
	function ranger(item) { return tx('readwrite', function (s) { return s.put(item); }); }
	function retirer(cle) { return tx('readwrite', function (s) { return s['delete'](cle); }); }

	function uuid() {
		if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
		return 'hc-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
	}
	function fmt(n) { return (Math.round(n) || 0).toLocaleString('fr-FR').replace(/\s/g, ' '); }

	/* ---------- Formulaire de saisie ---------- */
	var form = document.getElementById('ige-saisie');
	if (form) {
		form.addEventListener('submit', function (e) {
			var cle = form.querySelector('input[name=cle_client]');
			if (!cle) { cle = document.createElement('input'); cle.type = 'hidden'; cle.name = 'cle_client'; form.appendChild(cle); }
			if (!cle.value) { cle.value = uuid(); }
			if (navigator.onLine) { return; }
			e.preventDefault();
			var fd = new FormData(form), champs = {}, fichier = null, nom = '';
			fd.forEach(function (v, k) {
				if (k === 'piece') { if (v && v.size) { fichier = v; nom = v.name; } return; }
				if (k === '_wpnonce' || k === '_wp_http_referer' || k === 'action') { return; }
				champs[k] = v;
			});
			var item = { cle: cle.value, user: IGE_HC.user, cree: new Date().toISOString(), champs: champs, fichier: fichier, nom: nom, etat: 'attente', message: '' };
			ranger(item).then(function () {
				cle.value = '';
				form.reset();
				form.dispatchEvent(new Event('change'));
				form.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = false; });
				avis('ok', 'Écriture enregistrée sur le téléphone', 'Pas de réseau : elle sera envoyée automatiquement dès le retour de la connexion. Vous pouvez saisir la suivante.');
				majBadge();
			}).catch(function () {
				avis('ko', 'Enregistrement impossible sur ce téléphone', 'Le navigateur refuse le stockage (navigation privée ?). Réessayez quand le réseau revient.');
			});
		}, true);
	}

	function avis(type, titre, texte) {
		var zone = document.querySelector('.ige-wrap main, main, .ige-wrap') || document.body;
		var d = document.createElement('div');
		d.className = 'ige-banner ige-' + type;
		d.setAttribute('role', 'status');
		d.innerHTML = '<span class="ige-ic">' + (type === 'ok' ? '✓' : '!') + '</span><div><b></b><span></span></div>';
		d.querySelector('b').textContent = titre;
		d.querySelector('span:last-child').textContent = ' ' + texte;
		zone.insertBefore(d, zone.firstChild);
		window.scrollTo(0, 0);
		setTimeout(function () { if (d.parentNode) { d.parentNode.removeChild(d); } }, 12000);
	}

	/* ---------- Envoi ---------- */
	var enCours = false, reconnexion = false;
	function jeton() {
		var b = new FormData();
		b.append('action', 'ige_ffm_jeton');
		return fetch(IGE_HC.ajax, { method: 'POST', body: b, credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
			if (j && j.success && j.data && j.data.nonce) { IGE_HC.nonce = j.data.nonce; return true; }
			return false;
		}).catch(function () { return false; });
	}
	function envoyer() {
		if (enCours || !navigator.onLine) { return Promise.resolve(); }
		enCours = true;
		return tous().then(function (liste) {
			liste = liste.filter(function (x) { return x.etat !== 'erreur'; });
			if (!liste.length) { return; }
			return jeton().then(function (ok) {
				if (!ok) { reconnexion = true; return; }
				reconnexion = false;
				var chaine = Promise.resolve(), envoyees = 0;
				liste.forEach(function (it) {
					chaine = chaine.then(function () {
						if (reconnexion) { return; }
						var b = new FormData();
						Object.keys(it.champs).forEach(function (k) { b.append(k, it.champs[k]); });
						b.set('cle_client', it.cle);
						if (it.fichier) { b.append('piece', it.fichier, it.nom || 'piece.jpg'); }
						return fetch(IGE_HC.api, { method: 'POST', body: b, credentials: 'same-origin', headers: { 'X-WP-Nonce': IGE_HC.nonce } }).then(function (r) {
							if (r.status === 201) { envoyees++; return retirer(it.cle); }
							if (r.status === 401 || r.status === 403) { reconnexion = true; return; }
							return r.json().then(function (j) {
								it.etat = 'erreur';
								it.message = (j && j.message) || ('Refusée (code ' + r.status + ')');
								return ranger(it);
							});
						}).catch(function () { reconnexion = false; throw new Error('reseau'); });
					});
				});
				return chaine.then(function () {
					if (envoyees) { avis('ok', envoyees + ' écriture(s) envoyée(s)', 'Les écritures saisies hors connexion sont enregistrées dans le livre de caisse.'); }
				}).catch(function () { /* réseau coupé pendant l'envoi : nouvel essai plus tard */ });
			});
		}).then(function () { enCours = false; majBadge(); rendreListe(); }, function () { enCours = false; });
	}

	/* ---------- Affichage de la file ---------- */
	var badge = null;
	function majBadge() {
		tous().then(function (l) {
			if (!l.length) { if (badge) { badge.remove(); badge = null; } return; }
			if (!badge) {
				badge = document.createElement('a');
				badge.className = 'ige-hc-badge';
				badge.href = IGE_HC.page;
				document.body.appendChild(badge);
			}
			var err = l.filter(function (x) { return x.etat === 'erreur'; }).length;
			badge.textContent = l.length + ' écriture(s) en attente d’envoi' + (err ? ' · ' + err + ' refusée(s)' : '') + (reconnexion ? ' · reconnectez-vous' : '');
			badge.classList.toggle('ige-hc-ko', !!err || reconnexion);
		});
	}
	function rendreListe() {
		var zone = document.getElementById('ige-hc-liste');
		if (!zone) { return; }
		tous().then(function (l) {
			var etat = document.getElementById('ige-hc-etat');
			if (etat) { etat.textContent = navigator.onLine ? (reconnexion ? 'Connecté au réseau, mais la session a expiré : reconnectez-vous pour envoyer.' : 'Connecté : l’envoi est automatique.') : 'Hors connexion : les écritures restent sur ce téléphone.'; }
			if (!l.length) { zone.innerHTML = '<p class="ige-empty">Aucune écriture en attente sur ce téléphone.</p>'; return; }
			var t = document.createElement('table');
			t.className = 'ige-table';
			t.innerHTML = '<thead><tr><th>N°</th><th>Date</th><th>Description</th><th class="ige-r">Montant</th><th>État</th><th></th></tr></thead><tbody></tbody>';
			l.forEach(function (it, i) {
				var c = it.champs || {}, tr = document.createElement('tr');
				var m = c.devise === 'USD' ? (c.montant_usd || '') + ' USD' : fmt(parseInt(String(c.montant || '0').replace(/\D/g, ''), 10)) + ' CDF';
				tr.innerHTML = '<td>HC-' + String(i + 1).padStart(2, '0') + '</td><td></td><td></td><td class="ige-r"></td><td></td><td></td>';
				tr.children[1].textContent = (c.date_operation || '').split('-').reverse().join('/');
				tr.children[2].textContent = (c.type === 'entree' ? 'Entrée · ' : 'Sortie · ') + (c.description || '') + (it.fichier ? ' · pièce jointe' : '');
				tr.children[3].textContent = m;
				tr.children[4].innerHTML = it.etat === 'erreur' ? '<span class="ige-pill ige-p-ko">Refusée</span>' : '<span class="ige-pill ige-p-wait">En attente</span>';
				if (it.message) { var s = document.createElement('div'); s.className = 'ige-small'; s.style.color = 'var(--ige-ko-txt)'; s.textContent = it.message; tr.children[4].appendChild(s); }
				var del = document.createElement('button');
				del.type = 'button'; del.className = 'ige-act'; del.style.cssText = 'background:none;border:0;padding:0;cursor:pointer;font:inherit';
				del.textContent = 'Supprimer';
				del.addEventListener('click', function () {
					if (window.confirm('Supprimer cette écriture du téléphone ? Elle ne sera pas envoyée.')) { retirer(it.cle).then(function () { majBadge(); rendreListe(); }); }
				});
				tr.children[5].appendChild(del);
				t.tBodies[0].appendChild(tr);
			});
			var w = document.createElement('div');
			w.className = 'ige-table-wrap';
			w.appendChild(t);
			zone.innerHTML = '';
			zone.appendChild(w);
		});
	}
	var bouton = document.getElementById('ige-hc-envoyer');
	if (bouton) { bouton.addEventListener('click', function () { envoyer(); }); }

	window.addEventListener('online', function () { envoyer(); rendreListe(); });
	window.addEventListener('offline', function () { rendreListe(); });
	majBadge();
	rendreListe();
	envoyer();
	setInterval(function () { tous().then(function (l) { if (l.length) { envoyer(); } }); }, 60000);
}());
