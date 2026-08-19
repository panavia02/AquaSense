////////////////////
// == CARE PLAN == //
////////////////////
// Include this AFTER login.js. Exposes window.renderGuestCarePlan(), called
// from script.js's Guest.loadCareplan() to show/edit the guest's care plan
// in the #care-plan-plan-container div. Anyone who can view the guest can
// read the plan; only a location admin of the guest's location (or a
// master admin) can edit it -- see careplan.php for the actual permission
// checks, which this file's UI just mirrors for what controls to show.

(function () {
	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str == null ? '' : String(str);
		return div.innerHTML;
	}

	window.renderGuestCarePlan = async function (guestId) {
		const container = document.getElementById('care-plan-plan-container');
		if (!container) return;
		container.innerHTML = '<p>Loading care plan...</p>';

		try {
			const res = await fetch('careplan.php?action=get&id=' + encodeURIComponent(guestId));
			const json = await res.json();
			if (!json.success) {
				container.innerHTML = `<p class="admin-error">${escapeHtml(json.error || 'Failed to load care plan.')}</p>`;
				return;
			}
			renderPlan(guestId, json.data, container);
		} catch (err) {
			container.innerHTML = '<p class="admin-error">Could not reach the server.</p>';
		}
	};

	function renderPlan(guestId, plan, container) {
		let metaLine = '';
		if (plan.updated_at) {
			metaLine = `<p class="careplan-meta">Last updated ${escapeHtml(plan.updated_at)}${plan.updated_by ? ' by ' + escapeHtml(plan.updated_by) : ''}</p>`;
		} else {
			metaLine = `<p class="careplan-meta">No care plan recorded yet.</p>`;
		}

		if (plan.can_edit) {
			container.innerHTML = `
				<h3 class="careplan-header">Care Plan</h3>
				${metaLine}
				<form id="careplan-form">
					<textarea id="careplan-text" class="careplan-textarea" rows="8">${escapeHtml(plan.plan_text)}</textarea>
					<button type="submit">Save care plan</button>
				</form>
				<p class="admin-error" id="careplan-error" style="display:none"></p>
			`;
			document.getElementById('careplan-form').addEventListener('submit', async (event) => {
				event.preventDefault();
				const errorEl = document.getElementById('careplan-error');
				errorEl.style.display = 'none';
				const text = document.getElementById('careplan-text').value;

				try {
					const body = new URLSearchParams({ action: 'save', id: guestId, plan_text: text });
					const res = await fetch('careplan.php', { method: 'POST', body });
					const json = await res.json();
					if (json.success) {
						if (typeof showToast === 'function') showToast('Care plan saved.', 'success');
						window.renderGuestCarePlan(guestId); // refresh to show the new "last updated" line
					} else {
						errorEl.textContent = json.error || 'Failed to save.';
						errorEl.style.display = '';
					}
				} catch (err) {
					errorEl.textContent = 'Could not reach the server.';
					errorEl.style.display = '';
				}
			});
		} else {
			// Read-only view for anyone who can see the guest but isn't an
			// admin of their location -- plain text, no textarea/edit UI.
			container.innerHTML = `
				<h3 class="careplan-header">Care Plan</h3>
				${metaLine}
				<p class="careplan-readonly">${plan.plan_text ? escapeHtml(plan.plan_text).replace(/\n/g, '<br>') : '<em>Nothing recorded yet.</em>'}</p>
			`;
		}
	}
})();
