/**
 * IGE Frais de fonctionnement — comportements d'interface (sans dépendance, < 3 Ko).
 */
(function () {
	'use strict';

	var doc = document;

	// Menu principal sur téléphone et tablette.
	var toggle = doc.querySelector('.ige-menu-toggle');
	var nav = doc.getElementById('ige-nav');
	if (toggle && nav) {
		toggle.addEventListener('click', function () {
			var open = nav.classList.toggle('is-open');
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	}

	// Sous-menus : ouverture au clavier et au toucher.
	function closeAll(except) {
		doc.querySelectorAll('.ige-nav__item.is-open').forEach(function (li) {
			if (li !== except) {
				li.classList.remove('is-open');
				var b = li.querySelector('.ige-nav__toggle');
				if (b) { b.setAttribute('aria-expanded', 'false'); }
			}
		});
	}
	doc.querySelectorAll('.ige-nav__toggle').forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			var li = btn.closest('.ige-nav__item');
			var open = !li.classList.contains('is-open');
			closeAll(li);
			li.classList.toggle('is-open', open);
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	});
	// Un parent de groupe sans page (« # ») ouvre son sous-menu.
	doc.querySelectorAll('.ige-nav__item.has-sub > .ige-nav__link[href="#"]').forEach(function (a) {
		a.addEventListener('click', function (e) {
			e.preventDefault();
			var btn = a.parentNode.querySelector('.ige-nav__toggle');
			if (btn) { btn.click(); }
		});
	});
	doc.addEventListener('click', function () { closeAll(null); });

	// Fenêtres modales : data-ige-modal-open="id", data-ige-modal-close.
	function openModal(id) {
		var m = doc.getElementById(id);
		if (!m) { return; }
		m.classList.add('is-open');
		var f = m.querySelector('input, select, textarea, button');
		if (f) { f.focus(); }
	}
	function closeModals() {
		doc.querySelectorAll('.ige-modal-backdrop.is-open').forEach(function (m) { m.classList.remove('is-open'); });
	}
	doc.addEventListener('click', function (e) {
		var o = e.target.closest('[data-ige-modal-open]');
		if (o) { e.preventDefault(); openModal(o.getAttribute('data-ige-modal-open')); return; }
		if (e.target.closest('[data-ige-modal-close]') || e.target.classList.contains('ige-modal-backdrop')) { closeModals(); }
	});

	// Échap ferme menus et modales.
	doc.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { closeAll(null); closeModals(); }
	});

	// Lignes sélectionnées dans les tableaux.
	doc.addEventListener('change', function (e) {
		var cb = e.target;
		if (cb.matches('.ige-table input[type="checkbox"]')) {
			var tr = cb.closest('tr');
			if (tr) { tr.classList.toggle('ige-sel', cb.checked); }
		}
	});

	// Impression d'un document officiel.
	doc.querySelectorAll('[data-ige-print]').forEach(function (b) {
		b.addEventListener('click', function () { window.print(); });
	});
})();
