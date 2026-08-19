////////////////////
// == PLUGINS == //
////////////////////
// Include this AFTER login.js. Populates the #plugins page (master admins
// only -- the nav link itself is hidden for everyone else by login.js, and
// plugins.php independently enforces the same restriction server-side).

(function () {
	const pluginsPage = document.getElementById('plugins');

	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str == null ? '' : String(str);
		return div.innerHTML;
	}

	async function render() {
		pluginsPage.innerHTML = `<div class="main-content"><p>Loading...</p></div>`;

		try {
			const res = await fetch('plugins.php?action=list');
			const json = await res.json();
			if (!json.success) {
				pluginsPage.innerHTML = `<div class="main-content"><p class="admin-error">${escapeHtml(json.error || 'Failed to load plugins.')}</p></div>`;
				return;
			}
			renderList(json.data);
		} catch (err) {
			pluginsPage.innerHTML = `<div class="main-content"><p class="admin-error">Could not reach the server.</p></div>`;
		}
	}

	function renderList(plugins) {
		let html = `<div class="main-content"><h2 class="admin-header">Plugins</h2><div class="plugins-list">`;

		plugins.forEach(p => {
			html += `
				<div class="plugin-card" data-slug="${escapeHtml(p.slug)}">
					<div class="plugin-card-info">
						<strong>${escapeHtml(p.name)}</strong>
						<p>${escapeHtml(p.description)}</p>
					</div>
					<div class="plugin-card-toggle">`;
			if (p.locked) {
				html += `<span class="plugin-locked-badge">Always enabled</span>`;
			} else {
				// Reusing the .dashboard-switch toggle styling from the dashboard's
				// "Private mode" switch (style.css) rather than inventing a new
				// toggle style just for this page.
				html += `
					<label class="dashboard-switch">
						<input type="checkbox" class="plugin-enable-cb" ${p.enabled ? 'checked' : ''} />
						<span class="dashboard-switch-slider round"></span>
					</label>`;
			}
			html += `</div></div>`;
		});

		html += `</div></div>`;
		pluginsPage.innerHTML = html;

		pluginsPage.querySelectorAll('.plugin-enable-cb').forEach(cb => {
			cb.addEventListener('change', async () => {
				const slug = cb.closest('.plugin-card').dataset.slug;
				const body = new URLSearchParams({ action: 'toggle', slug, enabled: cb.checked ? '1' : '' });
				try {
					const res = await fetch('plugins.php', { method: 'POST', body });
					const json = await res.json();
					if (!json.success) {
						alert(json.error || 'Failed to update plugin.');
						cb.checked = !cb.checked;
						return;
					}
					if (typeof showToast === 'function') {
						showToast(cb.checked ? 'Plugin enabled.' : 'Plugin disabled.', 'success');
					}
				} catch (err) {
					alert('Could not reach the server.');
					cb.checked = !cb.checked;
				}
			});
		});
	}

	render();
})();
