(function () {
	function srColor(sr, stops) {
		var c = stops[0][1];
		var last = stops[stops.length - 1];
		if (sr >= last[0]) {
			c = last[1];
		} else if (sr > stops[0][0]) {
			for (var i = 0; i < stops.length - 1; i++) {
				var a = stops[i], b = stops[i + 1];
				if (sr <= b[0]) {
					var t = (sr - a[0]) / (b[0] - a[0]);
					c = [0, 1, 2].map(function (k) { return a[1][k] + (b[1][k] - a[1][k]) * t; });
					break;
				}
			}
		}
		return 'rgb(' + Math.round(c[0]) + ', ' + Math.round(c[1]) + ', ' + Math.round(c[2]) + ')';
	}

	function ago(ts, now) {
		var s = Math.max(1, now - ts);
		var units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['min', 60]];
		for (var i = 0; i < units.length; i++) {
			var n = Math.floor(s / units[i][1]);
			if (n >= 1) return n + ' ' + units[i][0] + (n > 1 ? 's' : '') + ' ago';
		}
		return 'just now';
	}

	function round2(n) {
		return String(Math.round(n * 100) / 100);
	}

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text != null) e.textContent = text;
		return e;
	}

	function link(text, href, cls) {
		var a = el('a', cls, text);
		if (/^https?:\/\//i.test(href)) a.href = href;
		return a;
	}

	function row(r, cfg, now, flash) {
		var stops = cfg.stops;
		var tr = el('tr', flash ? 'cm-lb-new' : '');

		tr.appendChild(el('td', 'cm-lb-rank' + (r.rank <= 3 ? ' r' + r.rank : ''), r.rank));
		var player = el('td', 'cm-lb-player');
		var name = r.profile ? link('', r.profile) : player;
		if (r.avatar) {
			var img = el('img', 'cm-avatar');
			img.src = r.avatar;
			img.alt = '';
			img.width = img.height = 24;
			img.loading = 'lazy';
			name.appendChild(img);
			name.appendChild(document.createTextNode(' '));
		}
		name.appendChild(document.createTextNode(r.player));
		if (name !== player) player.appendChild(name);
		tr.appendChild(player);

		var map = el('td', 'cm-lb-map');
		map.appendChild(r.url || r.download ? link(r.map, r.url || r.download) : document.createTextNode(r.map));
		if (r.tier) map.appendChild(el('small', '', cfg.tiers[r.tier] || r.tier));
		tr.appendChild(map);

		var sr = el('td', 'cm-lb-sr center');
		if (r.sr != null) {
			var pill = el('span', 'cm-sr', round2(r.sr));
			pill.style.background = r.color || srColor(r.sr, stops);
			sr.appendChild(pill);
		} else {
			sr.appendChild(el('span', 'cm-chip', '?'));
		}
		tr.appendChild(sr);

		tr.appendChild(el('td', 'cm-lb-acc num', r.acc != null ? round2(r.acc) + '%' : r.acc_raw));

		var style = el('td', 'cm-lb-style');
		if (r.style) style.appendChild(el('span', 'cm-chip', r.style));
		tr.appendChild(style);

		var speed = el('td', 'cm-lb-speed');
		if (r.speed) speed.appendChild(el('span', 'cm-chip', r.speed));
		tr.appendChild(speed);

		var vid = el('td', 'cm-lb-video');
		if (r.video) {
			var a = link('Watch', r.video);
			a.target = '_blank';
			a.rel = 'noopener';
			vid.appendChild(a);
		}
		tr.appendChild(vid);

		var date = el('td', 'cm-lb-date');
		var t = el('time', '', ago(r.time, now));
		t.dateTime = new Date(r.time * 1000).toISOString();
		t.title = new Date(r.time * 1000).toLocaleString();
		date.appendChild(t);
		tr.appendChild(date);

		return tr;
	}

	function setup(box) {
		var cfg = JSON.parse(box.getAttribute('data-cfg'));
		var tbody = box.querySelector('tbody');
		var empty = box.querySelector('.cm-lb-empty');
		var emptyHtml = empty.innerHTML;
		var more = box.querySelector('.cm-lb-more');
		var live = box.querySelector('.cm-lb-live');
		var tabs = box.querySelectorAll('.cm-lb-tab');

		var state = { sort: cfg.sort, tier: '', style: cfg.style || '', q: '' };
		var fromUrl = new URLSearchParams(location.search).get('q');
		if (fromUrl) {
			state.q = fromUrl.slice(0, 60);
			var qInput = box.querySelector('[data-filter="q"]');
			if (qInput) qInput.value = state.q;
		}
		var seen = {};
		var ver = cfg.data.ver;
		var total = cfg.data.total;
		var req = 0;
		var timer;

		cfg.data.rows.forEach(function (r) { seen[r.id] = 1; });

		function url(page, perPage) {
			var p = new URLSearchParams({ sort: state.sort, page: page, per_page: perPage });
			if (state.tier) p.set('tier', state.tier);
			if (state.style) p.set('style', state.style);
			if (state.q) p.set('q', state.q);
			p.set('t', Math.floor(Date.now() / 15000));
			return cfg.url + (cfg.url.indexOf('?') > -1 ? '&' : '?') + p;
		}

		function show(data, append, flash) {
			if (!append) tbody.textContent = '';
			data.rows.forEach(function (r) {
				if (append && seen[r.id]) return; // already on the page
				tbody.appendChild(row(r, cfg, data.now, flash && !seen[r.id]));
				seen[r.id] = 1;
			});
			ver = data.ver;
			total = data.total;
			var n = tbody.children.length;
			empty.hidden = n > 0;
			if (!n) {
				if (state.tier || state.q) empty.textContent = 'No scores match.';
				else empty.innerHTML = emptyHtml;
			}
			more.hidden = n >= total;
		}

		function load(page, append) {
			var id = ++req;
			fetch(url(page, cfg.perPage))
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (id === req) show(data, append, false);
				})
				.catch(function () {});
		}

		function reload() {
			seen = {};
			load(1, false);
		}

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				if (state.sort === tab.dataset.sort) return;
				state.sort = tab.dataset.sort;
				tabs.forEach(function (t) { t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
				reload();
			});
		});

		var wait;
		box.querySelectorAll('[data-filter]').forEach(function (input) {
			input.addEventListener(input.tagName === 'INPUT' ? 'input' : 'change', function () {
				state[input.dataset.filter] = input.value.trim();
				clearTimeout(wait);
				wait = setTimeout(reload, input.tagName === 'INPUT' ? 250 : 0);
			});
		});

		more.addEventListener('click', function () {
			if (tbody.children.length >= total) return;
			load(Math.floor(tbody.children.length / cfg.perPage) + 1, true);
		});

		// check for new scores every 30s while the tab is open
		function poll() {
			var n = Math.min(100, Math.max(cfg.perPage, tbody.children.length));
			fetch(url(1, n))
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data.ver !== ver || data.total !== total) {
						show(data, false, true);
					} else {
						tbody.querySelectorAll('time').forEach(function (t) {
							t.textContent = ago(Date.parse(t.dateTime) / 1000, data.now);
						});
					}
				})
				.catch(function () {});
		}

		function start() {
			clearInterval(timer);
			timer = setInterval(poll, 30000);
			live.classList.remove('paused');
		}

		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				clearInterval(timer);
				live.classList.add('paused');
			} else {
				poll();
				start();
			}
		});
		if (state.q) reload();
		else poll(); // in case the page itself came from a cache
		start();
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.cm-lb[data-cfg]').forEach(setup);
	});
})();
