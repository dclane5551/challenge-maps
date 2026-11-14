// refreshes the Live Clears bar after load so a cached homepage still shows the latest scores
(function () {
	function item(r) {
		var div = document.createElement('div');
		div.className = 'cm-ticker-item';

		var name = document.createElement('strong');
		name.textContent = r.player;
		div.appendChild(name);
		div.appendChild(document.createTextNode(' cleared '));

		var map = document.createElement('span');
		map.textContent = r.map + (r.tier ? ' (' + (cmapsTicker.tiers[r.tier] || r.tier) + ')' : '');
		div.appendChild(map);

		if (r.acc != null) {
			var acc = r.acc >= 100 ? 'FC' : Math.round(r.acc * 100) / 100 + '% accuracy';
			div.appendChild(document.createTextNode(' · ' + acc));
		}
		return div;
	}

	function load() {
		var track = document.querySelector('.cm-ticker-track');
		if (!track || !window.cmapsTicker) return;

		// time in the url so a cached api response can't stick around
		var url = cmapsTicker.url + (cmapsTicker.url.indexOf('?') > -1 ? '&' : '?') + 'sort=recent&per_page=8&t=' + Math.floor(Date.now() / 30000);

		fetch(url)
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data.rows || !data.rows.length) return; // keep whatever the page already has
				track.textContent = '';
				// twice, the scroll animation loops on the second copy
				for (var n = 0; n < 2; n++) {
					data.rows.forEach(function (r) { track.appendChild(item(r)); });
				}
				track.style.animation = '';
			})
			.catch(function () {});
	}

	document.addEventListener('DOMContentLoaded', load);
})();
