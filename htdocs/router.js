////////////////////////
// == HASH ROUTING == //
////////////////////////
// Include this LAST (after script.js, which defines `menu`). Makes each
// menu page reachable/reloadable via its URL hash, e.g. loading the site
// directly at #admin_users opens the admin panel instead of always
// defaulting to the dashboard.
//
// Hash values intentionally differ from the internal page/content ids
// script.js uses (e.g. "#admin_users" vs the "admin" content div) because
// that's what the existing nav links in index.html already use as hrefs.

(function () {
	const hashToPage = {
		'dashboard': 'dashboard',
		'facility_location': 'facility',
		'profile': 'profile',
		'care_plan': 'care-plan',
		'analytics': 'analytics',
		'plugins': 'plugins',
		'admin_users': 'admin',
		'settings': 'settings',
	};
	const pageToHash = {};
	Object.entries(hashToPage).forEach(([hash, page]) => { pageToHash[page] = hash; });

	// Wrap menu.openPage so that any navigation -- whether from a nav-bar
	// click, a search result, or a "go to Care Plan" button elsewhere in
	// the app -- keeps the URL hash in sync and reloadable/bookmarkable.
	const originalOpenPage = menu.openPage.bind(menu);
	menu.openPage = function (pageName) {
		originalOpenPage(pageName);
		const hash = pageToHash[pageName];
		if (hash && window.location.hash.replace('#', '') !== hash) {
			// replaceState (not pushState): clicking around the app shouldn't
			// pile up a separate browser-history entry per page, or the back
			// button would step through every page you'd ever visited instead
			// of leaving the site. TODO: if you'd actually prefer back/forward
			// to navigate between pages within the app, switch this to
			// pushState -- that's a legitimate alternative choice, just not
			// the one made here.
			history.replaceState(null, '', '#' + hash);
		}
	};

	function openFromHash() {
		const hash = window.location.hash.replace('#', '');
		const pageName = hashToPage[hash];
		if (pageName) {
			menu.openPage(pageName);
		}
	}

	// Note: "#logout" (the hash on the logout link) is deliberately NOT in
	// hashToPage above -- it's not a real page, it's an action link that
	// login.js intercepts before this router ever sees the click (see the
	// capture-phase listener in wireLogoutButton()). If you ever add other
	// action-only links, keep them out of hashToPage the same way.
	window.addEventListener('hashchange', openFromHash);

	// script.js already opened "dashboard" by the time this file runs (it's
	// loaded after script.js and executes synchronously at parse time, and
	// the script tags sit at the end of <body> so the DOM is already ready).
	// If the URL actually points somewhere else, honor that instead.
	openFromHash();
})();
