/*
 * Service worker de la saisie hors connexion (IGE · Frais de fonctionnement).
 * IGE_PAGES (chemins des pages gardées en mémoire) et IGE_VERSION sont ajoutés par le serveur.
 *
 * - Pages « Nouvelle écriture » et « Saisie hors connexion » : réseau d'abord, mémoire sans réseau.
 * - Feuilles de style, scripts, polices, images de l'interface : mémoire d'abord, mise à jour en arrière-plan.
 * - Rien d'autre n'est intercepté (formulaires, API, administration, pièces justificatives).
 */
/* global IGE_PAGES, IGE_VERSION */
var PAGES = 'ige-ffm-pages-' + IGE_VERSION;
var FICHIERS = 'ige-ffm-fichiers-' + IGE_VERSION;

self.addEventListener('install', function () { self.skipWaiting(); });

self.addEventListener('activate', function (e) {
	e.waitUntil(caches.keys().then(function (ks) {
		return Promise.all(ks.filter(function (k) { return k.indexOf('ige-ffm-') === 0 && k !== PAGES && k !== FICHIERS; }).map(function (k) { return caches.delete(k); }));
	}).then(function () { return self.clients.claim(); }));
});

function estPage(url) {
	return IGE_PAGES.indexOf(url.pathname) !== -1 || IGE_PAGES.indexOf(url.pathname + '/') !== -1;
}

function estFichier(url) {
	return /\/wp-content\/(themes|plugins)\/[^?]+\.(css|js|woff2?|ttf|png|jpe?g|svg|webp)$/.test(url.pathname) || /\/wp-includes\/[^?]+\.(css|js)$/.test(url.pathname);
}

self.addEventListener('fetch', function (e) {
	var req = e.request;
	if (req.method !== 'GET') { return; }
	var url = new URL(req.url);
	if (url.origin !== self.location.origin) { return; }
	if (req.mode === 'navigate' && estPage(url)) {
		e.respondWith(fetch(req).then(function (rep) {
			// Ne garder que la page affichée à un compte connecté (pas une redirection vers la connexion).
			if (rep.ok && !rep.redirected && rep.type === 'basic') {
				var copie = rep.clone();
				caches.open(PAGES).then(function (c) { c.put(url.pathname, copie); });
			}
			return rep;
		}).catch(function () {
			return caches.open(PAGES).then(function (c) {
				return c.match(url.pathname).then(function (m) { return m || c.match(IGE_PAGES[0]); });
			}).then(function (m) {
				return m || new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hors connexion</title><body style="font-family:system-ui;padding:24px"><h1 style="color:#17418a">Hors connexion</h1><p>Cette page n’a pas encore été préparée sur ce téléphone. Ouvrez « Nouvelle écriture » une fois avec du réseau, puis réessayez.</p></body>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
			});
		}));
		return;
	}
	if (estFichier(url)) {
		e.respondWith(caches.open(FICHIERS).then(function (c) {
			return c.match(req).then(function (m) {
				var reseau = fetch(req).then(function (rep) { if (rep.ok) { c.put(req, rep.clone()); } return rep; }).catch(function () { return m; });
				return m || reseau;
			});
		}));
	}
});

self.addEventListener('message', function (e) {
	if (e.data && e.data.type === 'prechauffer' && Array.isArray(e.data.urls)) {
		caches.open(PAGES).then(function (c) {
			e.data.urls.forEach(function (u) {
				fetch(u, { credentials: 'same-origin' }).then(function (rep) {
					if (rep.ok && !rep.redirected) { c.put(new URL(u, self.location.origin).pathname, rep); }
				}).catch(function () {});
			});
		});
	}
	if (e.data && e.data.type === 'vider') {
		caches.keys().then(function (ks) { ks.forEach(function (k) { if (k.indexOf('ige-ffm-pages') === 0) { caches.delete(k); } }); });
	}
});
