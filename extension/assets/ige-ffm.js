/* IGE — saisie des écritures : affichage selon le type, contrôles en direct, réduction des photos. */
(function () {
	'use strict';

	var fmt = function (n) {
		return Math.round(n).toLocaleString('fr-FR').replace(/\s/g, ' ') + ' CDF';
	};
	var montant = function (v) {
		var s = String(v || '').replace(/[\s  .]/g, '');
		return /^\d{1,15}$/.test(s) ? parseInt(s, 10) : null;
	};

	/* Réduction des photos avant l'envoi (1600 px, JPEG 75 %) : utile sur les connexions mobiles. */
	function reduirePhoto(input, info) {
		var f = input.files && input.files[0];
		if (!f) { if (info) { info.textContent = ''; } return; }
		if (f.type === 'application/pdf' || !/^image\/(jpeg|png|webp)$/.test(f.type)) {
			if (info) { info.textContent = f.name + ' · ' + Math.round(f.size / 1024) + ' Ko'; }
			return;
		}
		if (typeof DataTransfer === 'undefined' || !window.createImageBitmap && !window.FileReader) { return; }
		var img = new Image();
		var url = URL.createObjectURL(f);
		img.onload = function () {
			var max = 1600, w = img.naturalWidth, h = img.naturalHeight;
			URL.revokeObjectURL(url);
			if (w <= max && h <= max && f.size < 900 * 1024) {
				if (info) { info.textContent = f.name + ' · ' + Math.round(f.size / 1024) + ' Ko'; }
				return;
			}
			var r = Math.min(1, max / Math.max(w, h));
			var c = document.createElement('canvas');
			c.width = Math.round(w * r); c.height = Math.round(h * r);
			var ctx = c.getContext('2d');
			ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
			ctx.drawImage(img, 0, 0, c.width, c.height);
			c.toBlob(function (blob) {
				if (!blob || blob.size >= f.size) { return; }
				try {
					var nom = f.name.replace(/\.[^.]+$/, '') + '.jpg';
					var dt = new DataTransfer();
					dt.items.add(new File([blob], nom, { type: 'image/jpeg', lastModified: Date.now() }));
					input.files = dt.files;
					if (info) { info.textContent = 'Photo réduite : ' + Math.round(f.size / 1024) + ' Ko → ' + Math.round(blob.size / 1024) + ' Ko'; }
				} catch (e) { /* navigateur ancien : on envoie l'original */ }
			}, 'image/jpeg', 0.75);
		};
		img.onerror = function () { URL.revokeObjectURL(url); };
		img.src = url;
	}

	document.querySelectorAll('input[type=file][name=piece]').forEach(function (inp) {
		var info = inp.form && inp.form.querySelector('#ige-piece-info');
		inp.addEventListener('change', function () { reduirePhoto(inp, info); });
	});

	/* Un seul envoi par formulaire (évite les doublons sur une connexion lente). */
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (e.defaultPrevented || !f.matches('form[action*="admin-post.php"]')) { return; }
		setTimeout(function () {
			if (e.defaultPrevented) { return; }
			f.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; b.textContent = 'Envoi…'; });
		}, 0);
	});

	/* Vérification : tout sélectionner, compteur, motif exigé pour un rejet. */
	var vf = document.getElementById('ige-verif-form');
	if (vf) {
		var boxes = function () { return vf.querySelectorAll('input[name="ids[]"]'); };
		var tout = document.getElementById('ige-tout');
		var compte = function () {
			var n = vf.querySelectorAll('input[name="ids[]"]:checked').length;
			vf.querySelectorAll('[data-ige-sel]').forEach(function (b) {
				b.disabled = !n;
				b.textContent = (b.value === 'rejeter' ? 'Rejeter' : 'Valider la sélection') + (n ? ' (' + n + ')' : '');
			});
		};
		if (tout) { tout.addEventListener('change', function () { boxes().forEach(function (c) { c.checked = tout.checked; }); compte(); }); }
		vf.addEventListener('change', compte);
		compte();
	}
	document.addEventListener('click', function (e) {
		var b = e.target.closest('button[name=decision][value=rejeter]');
		if (!b) { return; }
		var m = b.form.querySelector('input[name=motif]');
		if (m && m.value.trim().length < 5) {
			e.preventDefault();
			m.setCustomValidity('Indiquez le motif du rejet (5 caractères au moins).');
			m.reportValidity();
			m.addEventListener('input', function () { m.setCustomValidity(''); }, { once: true });
		}
	});

	/* Codes QR (rapports, procès-verbaux, double authentification). */
	if (window.qrcode) {
		document.querySelectorAll('[data-ige-qr]').forEach(function (el) {
			try {
				var q = window.qrcode(0, 'M');
				q.addData(el.getAttribute('data-ige-qr'));
				q.make();
				el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
			} catch (e) { el.textContent = 'Code QR indisponible'; }
		});
	}

	/* Billetage : montants par coupure, totaux et écart en direct. */
	document.querySelectorAll('[data-ige-billetage]').forEach(function (zone) {
		var theo = parseInt(zone.getAttribute('data-theorique'), 10) || 0;
		var calc = function () {
			var cdf = 0, usd = 0;
			zone.querySelectorAll('input[data-coupure]').forEach(function (i) {
				var n = montant(i.value) || 0, v = parseInt(i.getAttribute('data-coupure'), 10) * n;
				var cell = i.nextElementSibling;
				if (i.getAttribute('data-devise') === 'usd') { usd += v; if (cell) { cell.textContent = v + ' USD'; } }
				else { cdf += v; if (cell) { cell.textContent = Math.round(v).toLocaleString('fr-FR').replace(/\s/g, '\u202F'); } }
			});
			var t = zone.querySelector('[data-taux]'), a = zone.querySelector('[data-autres]');
			var total = cdf + usd * ((t && montant(t.value)) || 0) + ((a && montant(a.value)) || 0);
			var tc = zone.querySelector('[data-total-cdf]');
			if (tc) { tc.textContent = Math.round(cdf).toLocaleString('fr-FR').replace(/\s/g, '\u202F'); }
			var to = zone.querySelector('[data-ige-total]'), ec = zone.querySelector('[data-ige-ecart]');
			if (to) { to.textContent = fmt(total); }
			if (ec) {
				var e = total - theo;
				ec.textContent = fmt(e);
				ec.style.color = e === 0 ? 'var(--ige-ok-txt)' : 'var(--ige-rouge)';
			}
		};
		zone.addEventListener('input', calc);
		calc();
	});

	var form = document.getElementById('ige-saisie');
	if (!form) { return; }

	var caisse = parseInt(form.getAttribute('data-caisse'), 10) || 0;
	var bloque = form.getAttribute('data-bloque') === '1';
	var el = function (id) { return document.getElementById(id); };
	var rub = el('ige-rubrique_id'), mnt = el('ige-montant'), four = el('ige-fournisseur_id');
	var out = el('ige-controle'), apres = el('ige-caisse-apres'), dispo = el('ige-dispo'), aide = el('ige-pieces-aide');

	function type() {
		var r = form.querySelector('input[name=type]:checked') || form.querySelector('input[name=type]');
		return r ? r.value : 'sortie';
	}
	function statut() {
		var r = form.querySelector('input[name=statut]:checked');
		return r ? r.value : 'payee';
	}
	function msg(cls, txt) {
		var d = document.createElement('div');
		d.className = 'ige-ctl ige-ctl-' + cls;
		d.textContent = txt;
		out.appendChild(d);
	}

	function maj() {
		var t = type(), sortie = t === 'sortie';
		form.querySelectorAll('.ige-sortie-only').forEach(function (n) {
			if (n.id === 'ige-nouveau-fournisseur') { n.hidden = !sortie || four.value !== 'nouveau'; }
			else if (n.id === 'ige-autre-beneficiaire') { n.hidden = !sortie || four.value !== 'autre'; }
			else { n.hidden = !sortie; }
		});
		form.querySelectorAll('.ige-entree-only').forEach(function (n) { n.hidden = sortie; });
		rub.required = sortie;
		four.required = sortie;
		el('ige-f_raison_sociale').required = sortie && four.value === 'nouveau';
		el('ige-beneficiaire').required = sortie && four.value === 'autre';
		var np = el('ige-num_piece');
		if (np && np.getAttribute('data-oblig')) { np.required = sortie; }

		var m = montant(mnt.value) || 0;
		var opt = rub.options[rub.selectedIndex];
		var d = sortie && opt && opt.value ? opt.getAttribute('data-dispo') : null;
		aide.textContent = sortie && opt && opt.value && opt.getAttribute('data-pieces') ? 'Pièces exigées : ' + opt.getAttribute('data-pieces') : '';
		out.innerHTML = '';

		var mvt = sortie ? (statut() === 'payee' ? -m : 0) : m;
		apres.textContent = m ? fmt(caisse + mvt) : '—';
		if (d === null || d === '') {
			dispo.textContent = sortie && opt && opt.value ? 'sans budget' : '—';
		} else {
			dispo.textContent = fmt(parseInt(d, 10)) + (m && sortie ? ' → ' + fmt(parseInt(d, 10) - m) : '');
		}
		if (mnt.value && montant(mnt.value) === null) { msg('ko', 'Montant : nombre entier en CDF, sans décimales.'); return; }
		if (!m) { return; }
		if (caisse + mvt < 0) { msg('ko', 'La caisse deviendrait négative : l’écriture sera refusée.'); }
		if (sortie && d !== null && d !== '' && m > parseInt(d, 10)) {
			msg(bloque ? 'ko' : 'warn', bloque ? 'Dépassement du disponible de la rubrique : l’écriture sera refusée.' : 'Dépassement du disponible de la rubrique.');
		}
		if (!out.childNodes.length) { msg('ok', 'Contrôles respectés.'); }
	}

	form.addEventListener('input', maj);
	form.addEventListener('change', maj);
	mnt.addEventListener('blur', function () {
		var m = montant(mnt.value);
		if (m !== null) { mnt.value = m.toLocaleString('fr-FR').replace(/\s/g, ' '); }
	});
	maj();
}());
