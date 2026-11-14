// search button in the nav: maps and players
(function () {
	if (!window.cmapsSearch) return;

	var icon = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2.4"/><path d="M20 20l-4-4" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>';

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text != null) e.textContent = text;
		return e;
	}

	function setup() {
		var nav = document.getElementById('et-top-navigation');
		if (!nav || document.querySelector('.cm-search-btn')) return;

		var btn = el('button', 'cm-search-btn');
		btn.type = 'button';
		btn.setAttribute('aria-label', 'Search maps and players');
		btn.innerHTML = icon;
		var mobile = document.getElementById('et_mobile_nav_menu');
		nav.insertBefore(btn, mobile || null);

		var box = el('div', 'cm-search');
		box.hidden = true;
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-label', 'Search');
		box.innerHTML = '<div class="cm-search-panel"><div class="cm-search-field">' + icon +
			'<input type="search" maxlength="60" placeholder="Search maps, mappers or players" autocomplete="off" spellcheck="false">' +
			'<button type="button" class="cm-search-close" aria-label="Close">Esc</button></div><div class="cm-search-results"></div></div>';
		document.body.appendChild(box);

		var input = box.querySelector('input');
		var results = box.querySelector('.cm-search-results');
		var wait, req = 0;

		function open() {
			box.hidden = false;
			document.documentElement.classList.add('cm-search-open');
			input.focus();
			input.select();
		}

		function close() {
			box.hidden = true;
			document.documentElement.classList.remove('cm-search-open');
			btn.focus();
		}

		function item(r, kind) {
			var a = el('a', 'cm-search-item');
			if (/^https?:\/\//i.test(r.url)) a.href = r.url;
			if (kind === 'map') {
				var pill = el('span', 'cm-sr', r.sr);
				pill.style.background = r.color;
				a.appendChild(pill);
			} else if (r.avatar) {
				var img = el('img', 'cm-avatar');
				img.src = r.avatar;
				img.alt = '';
				img.width = img.height = 28;
				a.appendChild(img);
			} else {
				a.appendChild(el('span', 'cm-avatar cm-avatar-none', (r.name || '?').charAt(0).toUpperCase()));
			}
			var text = el('span', 'cm-search-text');
			text.appendChild(el('strong', '', r.name));
			if (r.sub) text.appendChild(el('small', '', r.sub));
			a.appendChild(text);
			return a;
		}

		function show(data, q) {
			results.textContent = '';
			if (!data.maps.length && !data.players.length) {
				results.appendChild(el('p', 'cm-search-none', 'Nothing found for "' + q + '".'));
				return;
			}
			[['maps', 'Maps', 'map'], ['players', 'Players', 'player']].forEach(function (g) {
				if (!data[g[0]].length) return;
				results.appendChild(el('h3', '', g[1]));
				data[g[0]].forEach(function (r) { results.appendChild(item(r, g[2])); });
			});
		}

		function search() {
			var q = input.value.trim();
			if (q.length < 2) {
				results.textContent = '';
				return;
			}
			var id = ++req;
			fetch(cmapsSearch.url + (cmapsSearch.url.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(q))
				.then(function (r) { return r.json(); })
				.then(function (data) { if (id === req && data.maps) show(data, q); })
				.catch(function () {});
		}

		btn.addEventListener('click', open);
		box.querySelector('.cm-search-close').addEventListener('click', close);
		box.addEventListener('click', function (e) { if (e.target === box) close(); });
		input.addEventListener('input', function () {
			clearTimeout(wait);
			wait = setTimeout(search, 200);
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				var first = results.querySelector('a[href]');
				if (first) location.href = first.href;
			}
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !box.hidden) close();
			// "/" opens search, like a lot of sites do
			if (e.key === '/' && box.hidden && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) && !document.activeElement.isContentEditable) {
				e.preventDefault();
				open();
			}
		});
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup);
	else setup();
})();
