// Cam Lock / Spin switch on the rankings page
(function () {
	document.querySelectorAll('[data-cm-tabs]').forEach(function (box) {
		var tabs = box.querySelectorAll('[data-tab]');
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				tabs.forEach(function (t) { t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
				box.querySelectorAll('[data-panel]').forEach(function (p) {
					p.hidden = p.dataset.panel !== tab.dataset.tab;
				});
			});
		});
	});
})();
