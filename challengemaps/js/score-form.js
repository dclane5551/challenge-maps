// score form: narrows the Map Name dropdown to the difficulty picked
// (or suggests map names if Map Name is still a text box)
(function () {
	if (!window.cmapsScore || !cmapsScore.maps.length) return;

	function norm(s) {
		return String(s).toLowerCase().replace(/[‐-―]/g, '-').replace(/\s+/g, ' ').trim();
	}

	function setup() {
		var mapInput = document.getElementById('input_' + cmapsScore.form + '_' + cmapsScore.map);
		var tierInput = document.getElementById('input_' + cmapsScore.form + '_' + cmapsScore.tier);
		if (!mapInput || mapInput.dataset.cm) return;
		mapInput.dataset.cm = '1';

		// name + discord come from the account
		document.querySelectorAll('.cm-locked input').forEach(function (i) { i.readOnly = true; });

		var tierOf = {};
		cmapsScore.maps.forEach(function (m) { tierOf[m[0]] = m[1]; });

		function tier() {
			var v = tierInput ? tierInput.value.toLowerCase().trim() : '';
			return cmapsScore.tiers[v] || v;
		}

		if (mapInput.tagName === 'SELECT') {
			var all = Array.prototype.slice.call(mapInput.options);

			var filter = function () {
				var t = tier();
				var picked = mapInput.value;
				mapInput.textContent = '';
				all.forEach(function (o) {
					if (!o.value || !t || tierOf[o.value] === t) mapInput.appendChild(o);
				});
				// drop the pick if it's not in this difficulty
				mapInput.value = Array.prototype.some.call(mapInput.options, function (o) { return o.value === picked; }) ? picked : '';
			};

			if (tierInput) tierInput.addEventListener('change', filter);
			filter();
			return;
		}

		// text box version
		var list = document.createElement('datalist');
		list.id = 'cm-map-list';
		document.body.appendChild(list);
		mapInput.setAttribute('list', list.id);
		mapInput.setAttribute('autocomplete', 'off');

		var note = document.createElement('div');
		note.className = 'gfield_description cm-map-note';
		mapInput.insertAdjacentElement('afterend', note);

		function fill() {
			list.textContent = '';
			cmapsScore.maps.forEach(function (m) {
				if (tier() && m[1] !== tier()) return;
				var o = document.createElement('option');
				o.value = m[4];
				o.label = m[2] + '★ - ' + m[3];
				list.appendChild(o);
			});
			check();
		}

		function check() {
			var v = norm(mapInput.value);
			var hit = v && cmapsScore.maps.find(function (m) {
				return norm(m[4]) === v && (!tier() || m[1] === tier());
			});
			note.textContent = hit ? hit[2] + '★, mapped by ' + hit[3] : '';
		}

		if (tierInput) tierInput.addEventListener('change', fill);
		mapInput.addEventListener('input', check);
		fill();
	}

	document.addEventListener('DOMContentLoaded', setup);
	// gravity forms redraws the form after an ajax validation error
	if (window.jQuery) jQuery(document).on('gform_post_render', setup);
})();
