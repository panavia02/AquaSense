////////////////////////////////
// == FACILITY LOCATION == //
////////////////////////////////
// Include this AFTER login.js. Populates the #facility page with the
// locations/sections/rooms the current user can see, with add/edit/delete
// and user-assignment controls shown only where the user has permission
// (enforced again server-side by locations.php regardless of what's shown
// here). Also exposes window.renderGuestFacilityControls(), called from
// script.js's Guest.loadProfile() to show reassignment controls there.

(function () {
	const facilityPage = document.getElementById('facility');
	let cachedIsMaster = false;

	// Small wrapper so every call site doesn't repeat the same fetch/parse
	// boilerplate. GET is used for read-only actions (list, list_location_users,
	// search_users) and POST for anything that writes -- matching what
	// locations.php expects for each action.
	async function apiCall(action, fields, method) {
		method = method || 'POST';
		if (method === 'GET') {
			const qs = new URLSearchParams({ action, ...fields });
			const res = await fetch('locations.php?' + qs.toString());
			return res.json();
		}
		const body = new URLSearchParams({ action, ...fields });
		const res = await fetch('locations.php', { method: 'POST', body });
		return res.json();
	}

	async function render() {
		facilityPage.innerHTML = `<div class="main-content"><p>Loading...</p></div>`;
		const json = await apiCall('list', {}, 'GET');
		if (!json.success) {
			facilityPage.innerHTML = `<div class="main-content"><p class="admin-error">${escapeHtml(json.error || 'Failed to load locations.')}</p></div>`;
			return;
		}
		cachedIsMaster = json.data.is_master_admin;
		renderLocations(json.data.locations);
	}

	// Everything rendered here goes through this before being dropped into
	// innerHTML (location names, usernames, section/room names, etc. are
	// all user-entered at some point) -- don't add new HTML-string-building
	// code below without running dynamic values through this first.
	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str == null ? '' : String(str);
		return div.innerHTML;
	}

	function renderLocations(locations) {
		let html = `<div class="main-content"><h2 class="admin-header">Facility Location</h2>`;

		if (cachedIsMaster) {
			html += `
				<form id="new-location-form" class="admin-create-form">
					<input type="text" id="new-location-name" placeholder="New location name" required />
					<button type="submit">Add location</button>
				</form>
				<div id="facility-unassigned-panel"></div>
			`;
		}

		html += `<p class="admin-error" id="facility-error" style="display:none"></p>`;

		if (locations.length === 0) {
			html += `<p>You're not assigned to any locations yet. ${cachedIsMaster ? 'Add one above, or check the Admin Users page to assign yourself.' : 'Ask a location admin to assign you.'}</p>`;
		}

		locations.forEach(loc => {
			html += renderLocationCard(loc);
		});

		html += `</div>`;
		facilityPage.innerHTML = html;

		if (cachedIsMaster) {
			document.getElementById('new-location-form').addEventListener('submit', onCreateLocation);
			renderUnassignedGuestsPanel();
		}
		locations.forEach(loc => wireLocationCard(loc));
	}

	function renderLocationCard(loc) {
		const canManage = loc.is_location_admin;
		const adminNames = loc.admins.length ? loc.admins.map(a => escapeHtml(a.username)).join(', ') : 'None';

		let html = `<div class="facility-location-card" data-location-id="${loc.location_id}">
			<div class="facility-location-header">
				<h3>${escapeHtml(loc.location_name)}</h3>`;
		if (cachedIsMaster) {
			html += `
				<button type="button" class="facility-rename-location" data-id="${loc.location_id}">Rename</button>
				<button type="button" class="facility-delete-location" data-id="${loc.location_id}">Delete</button>`;
		}
		html += `</div>
			<p class="facility-admins-line">Location admins: ${adminNames}</p>`;

		if (canManage) {
			html += `
				<form class="admin-create-form facility-new-section-form" data-location-id="${loc.location_id}">
					<input type="text" class="facility-new-section-name" placeholder="New section name" required />
					<button type="submit">Add section</button>
				</form>`;
		}

		html += `<div class="facility-sections">`;
		loc.sections.forEach(section => {
			html += renderSectionBlock(loc, section, canManage);
		});
		html += `</div>`;

		if (canManage) {
			html += `
				<button type="button" class="facility-toggle-users" data-location-id="${loc.location_id}">Manage users for this location</button>
				<div class="facility-users-panel" id="facility-users-${loc.location_id}" style="display:none"></div>`;
		}

		html += `</div>`;
		return html;
	}

	function renderSectionBlock(loc, section, canManage) {
		let html = `<div class="facility-section" data-section-id="${section.section_id}">
			<div class="facility-section-header">
				<strong>${escapeHtml(section.section_name)}</strong>`;
		if (canManage) {
			html += `
				<button type="button" class="facility-rename-section" data-id="${section.section_id}">Rename</button>
				<button type="button" class="facility-delete-section" data-id="${section.section_id}">Delete</button>`;
		}
		html += `</div><ul class="facility-rooms">`;
		section.rooms.forEach(room => {
			html += `<li data-room-id="${room.room_id}">${escapeHtml(room.room_name)}`;
			if (canManage) {
				html += `
					<button type="button" class="facility-rename-room" data-id="${room.room_id}">Rename</button>
					<button type="button" class="facility-delete-room" data-id="${room.room_id}">Delete</button>`;
			}
			html += `</li>`;
		});
		html += `</ul>`;
		if (canManage) {
			html += `
				<form class="admin-create-form facility-new-room-form" data-section-id="${section.section_id}">
					<input type="text" class="facility-new-room-name" placeholder="New room name" required />
					<button type="submit">Add room</button>
				</form>`;
		}
		html += `</div>`;
		return html;
	}

	function showError(msg) {
		const el = document.getElementById('facility-error');
		if (!el) return;
		el.textContent = msg;
		el.style.display = '';
	}

	function wireLocationCard(loc) {
		const card = facilityPage.querySelector(`.facility-location-card[data-location-id="${loc.location_id}"]`);
		if (!card) return;

		const renameBtn = card.querySelector('.facility-rename-location');
		if (renameBtn) {
			renameBtn.addEventListener('click', async () => {
				const name = prompt('New name for this location:', loc.location_name);
				if (!name) return;
				const json = await apiCall('update_location', { location_id: loc.location_id, location_name: name });
				if (!json.success) { showError(json.error || 'Rename failed.'); return; }
				render();
			});
		}
		const deleteBtn = card.querySelector('.facility-delete-location');
		if (deleteBtn) {
			deleteBtn.addEventListener('click', async () => {
				if (!confirm(`Delete "${loc.location_name}" and everything in it (sections, rooms, guest assignments)? This can't be undone.`)) return;
				const json = await apiCall('delete_location', { location_id: loc.location_id });
				if (!json.success) { showError(json.error || 'Delete failed.'); return; }
				render();
			});
		}

		const newSectionForm = card.querySelector('.facility-new-section-form');
		if (newSectionForm) {
			newSectionForm.addEventListener('submit', async (event) => {
				event.preventDefault();
				const input = newSectionForm.querySelector('.facility-new-section-name');
				const json = await apiCall('create_section', { location_id: loc.location_id, section_name: input.value });
				if (!json.success) { showError(json.error || 'Failed to add section.'); return; }
				render();
			});
		}

		card.querySelectorAll('.facility-rename-section').forEach(btn => {
			btn.addEventListener('click', async () => {
				const name = prompt('New name for this section:');
				if (!name) return;
				const json = await apiCall('update_section', { section_id: btn.dataset.id, section_name: name });
				if (!json.success) { showError(json.error || 'Rename failed.'); return; }
				render();
			});
		});
		card.querySelectorAll('.facility-delete-section').forEach(btn => {
			btn.addEventListener('click', async () => {
				if (!confirm('Delete this section and all its rooms? This can\'t be undone.')) return;
				const json = await apiCall('delete_section', { section_id: btn.dataset.id });
				if (!json.success) { showError(json.error || 'Delete failed.'); return; }
				render();
			});
		});

		card.querySelectorAll('.facility-new-room-form').forEach(form => {
			form.addEventListener('submit', async (event) => {
				event.preventDefault();
				const input = form.querySelector('.facility-new-room-name');
				const json = await apiCall('create_room', { section_id: form.dataset.sectionId, room_name: input.value });
				if (!json.success) { showError(json.error || 'Failed to add room.'); return; }
				render();
			});
		});
		card.querySelectorAll('.facility-rename-room').forEach(btn => {
			btn.addEventListener('click', async () => {
				const name = prompt('New name for this room:');
				if (!name) return;
				const json = await apiCall('update_room', { room_id: btn.dataset.id, room_name: name });
				if (!json.success) { showError(json.error || 'Rename failed.'); return; }
				render();
			});
		});
		card.querySelectorAll('.facility-delete-room').forEach(btn => {
			btn.addEventListener('click', async () => {
				if (!confirm('Delete this room?')) return;
				const json = await apiCall('delete_room', { room_id: btn.dataset.id });
				if (!json.success) { showError(json.error || 'Delete failed.'); return; }
				render();
			});
		});

		const toggleUsersBtn = card.querySelector('.facility-toggle-users');
		if (toggleUsersBtn) {
			toggleUsersBtn.addEventListener('click', () => toggleUsersPanel(loc));
		}
	}

	// Lazy-loads the user list for a location the first time its panel is
	// opened (rather than fetching it for every visible location up front,
	// most of which a user will never click into). Re-fetches fresh data
	// every time it's re-opened rather than caching, so it can't go stale
	// after an assign/remove action elsewhere on the page.
	async function toggleUsersPanel(loc) {
		const panel = document.getElementById('facility-users-' + loc.location_id);
		if (!panel) return;
		if (panel.style.display !== 'none') {
			panel.style.display = 'none';
			return;
		}
		panel.style.display = '';
		panel.innerHTML = '<p>Loading users...</p>';

		const json = await apiCall('list_location_users', { location_id: loc.location_id }, 'GET');
		if (!json.success) {
			panel.innerHTML = `<p class="admin-error">${escapeHtml(json.error || 'Failed to load users.')}</p>`;
			return;
		}

		const { location_admins_and_members, section_only_members } = json.data;
		let html = `<table class="admin-users-table"><thead><tr><th>User</th><th>Scope</th><th>Location admin</th><th>Actions</th></tr></thead><tbody>`;
		location_admins_and_members.forEach(u => {
			html += `<tr>
				<td>${escapeHtml(u.username)}</td>
				<td>Whole location</td>
				<td><input type="checkbox" class="facility-toggle-loc-admin" data-user-id="${u.user_id}" data-location-id="${loc.location_id}" ${Number(u.is_location_admin) ? 'checked' : ''} /></td>
				<td><button type="button" class="facility-remove-loc-user" data-user-id="${u.user_id}" data-location-id="${loc.location_id}">Remove</button></td>
			</tr>`;
		});
		section_only_members.forEach(u => {
			html += `<tr>
				<td>${escapeHtml(u.username)}</td>
				<td>Section: ${escapeHtml(u.section_name)}</td>
				<td>&mdash;</td>
				<td><button type="button" class="facility-remove-section-user" data-user-id="${u.user_id}" data-section-id="${u.section_id}">Remove</button></td>
			</tr>`;
		});
		html += `</tbody></table>`;

		html += `
			<div class="facility-assign-panel">
				<input type="text" class="facility-user-search" placeholder="Search username to assign..." />
				<button type="button" class="facility-user-search-btn">Search</button>
				<div class="facility-user-search-results"></div>
			</div>`;

		panel.innerHTML = html;

		panel.querySelectorAll('.facility-toggle-loc-admin').forEach(cb => {
			cb.addEventListener('change', async () => {
				const json2 = await apiCall('assign_location', {
					user_id: cb.dataset.userId,
					location_id: cb.dataset.locationId,
					is_location_admin: cb.checked ? '1' : '',
				});
				if (!json2.success) {
					alert(json2.error || 'Update failed.');
					cb.checked = !cb.checked;
				}
			});
		});
		panel.querySelectorAll('.facility-remove-loc-user').forEach(btn => {
			btn.addEventListener('click', async () => {
				if (!confirm('Remove this user\'s access to the whole location?')) return;
				const json2 = await apiCall('remove_location', { user_id: btn.dataset.userId, location_id: btn.dataset.locationId });
				if (!json2.success) { alert(json2.error || 'Remove failed.'); return; }
				panel.style.display = 'none';
				toggleUsersPanel(loc); // reopen fresh with the updated list
			});
		});
		panel.querySelectorAll('.facility-remove-section-user').forEach(btn => {
			btn.addEventListener('click', async () => {
				if (!confirm('Remove this user\'s access to this section?')) return;
				const json2 = await apiCall('remove_section', { user_id: btn.dataset.userId, section_id: btn.dataset.sectionId });
				if (!json2.success) { alert(json2.error || 'Remove failed.'); return; }
				panel.style.display = 'none';
				toggleUsersPanel(loc);
			});
		});

		const searchBtn = panel.querySelector('.facility-user-search-btn');
		const searchInput = panel.querySelector('.facility-user-search');
		const resultsEl = panel.querySelector('.facility-user-search-results');
		searchBtn.addEventListener('click', async () => {
			const json2 = await apiCall('search_users', { query: searchInput.value }, 'GET');
			if (!json2.success) { resultsEl.innerHTML = `<p class="admin-error">${escapeHtml(json2.error || 'Search failed.')}</p>`; return; }
			renderSearchResults(json2.data, loc);
		});

		// TODO: this doesn't indicate whether a returned user is already
		// assigned to this location/section -- you can click "Add to whole
		// location" on someone already assigned and it'll just silently
		// no-op (assignLocation in locations.php upserts, so it's harmless,
		// just not informative). Cross-referencing against the table already
		// rendered above this search box would be a nice follow-up.
		function renderSearchResults(users, loc) {
			if (users.length === 0) {
				resultsEl.innerHTML = '<p>No matching users.</p>';
				return;
			}
			let sectionOptions = '';
			loc.sections.forEach(s => {
				sectionOptions += `<option value="${s.section_id}">${escapeHtml(s.section_name)}</option>`;
			});

			resultsEl.innerHTML = users.map(u => `
				<div class="facility-search-result-row" data-user-id="${u.user_id}">
					<span>${escapeHtml(u.username)} (${escapeHtml(u.email)})</span>
					<label><input type="checkbox" class="facility-assign-admin-cb" /> as admin</label>
					<button type="button" class="facility-assign-whole-btn">Add to whole location</button>
					<select class="facility-assign-section-select">${sectionOptions}</select>
					<button type="button" class="facility-assign-section-btn">Add to section</button>
				</div>
			`).join('');

			resultsEl.querySelectorAll('.facility-search-result-row').forEach(row => {
				const userId = row.dataset.userId;
				row.querySelector('.facility-assign-whole-btn').addEventListener('click', async () => {
					const asAdmin = row.querySelector('.facility-assign-admin-cb').checked;
					const json3 = await apiCall('assign_location', { user_id: userId, location_id: loc.location_id, is_location_admin: asAdmin ? '1' : '' });
					if (!json3.success) { alert(json3.error || 'Assign failed.'); return; }
					panel.style.display = 'none';
					toggleUsersPanel(loc);
				});
				row.querySelector('.facility-assign-section-btn').addEventListener('click', async () => {
					const sectionId = row.querySelector('.facility-assign-section-select').value;
					if (!sectionId) return;
					const json3 = await apiCall('assign_section', { user_id: userId, section_id: sectionId });
					if (!json3.success) { alert(json3.error || 'Assign failed.'); return; }
					panel.style.display = 'none';
					toggleUsersPanel(loc);
				});
			});
		}
	}

	async function onCreateLocation(event) {
		event.preventDefault();
		const input = document.getElementById('new-location-name');
		const json = await apiCall('create_location', { location_name: input.value });
		if (!json.success) { showError(json.error || 'Failed to add location.'); return; }
		render();
	}

	// Guests that got displaced by a deleted room/section/location land in
	// the reserved "Unassigned" bucket (see getOrCreateUnassignedRoom() in
	// location_helpers.php) rather than being deleted outright. This panel
	// is how a master admin finds and resolves them -- master-admin only
	// since, by definition, these guests aren't in any location a mere
	// location admin currently administers.
	async function renderUnassignedGuestsPanel() {
		const panel = document.getElementById('facility-unassigned-panel');
		if (!panel) return;

		const json = await apiCall('list_unassigned_guests', {}, 'GET');
		if (!json.success || json.data.length === 0) {
			panel.innerHTML = '';
			return;
		}

		panel.innerHTML = `
			<div class="facility-unassigned-panel">
				<strong>${json.data.length} guest${json.data.length === 1 ? '' : 's'} need${json.data.length === 1 ? 's' : ''} reassignment</strong>
				<p>These guests were moved here after a room, section, or location they were in got deleted.</p>
				<ul class="facility-unassigned-list">
					${json.data.map(g => `
						<li>
							${escapeHtml(g.guest_name)}
							<button type="button" class="facility-goto-profile-btn" data-guest-id="${g.guest_id}">Go to profile</button>
						</li>
					`).join('')}
				</ul>
			</div>
		`;

		panel.querySelectorAll('.facility-goto-profile-btn').forEach(btn => {
			btn.addEventListener('click', () => {
				const guest = guests.data[btn.dataset.guestId];
				if (!guest) {
					alert('That guest isn\'t loaded on the dashboard yet -- try again after the dashboard has finished loading.');
					return;
				}
				guests.loadProfile(guest);
				guests.displayProfile();
				menu.openPage('profile');
			});
		});
	}

	render();

	// ---- Profile page: guest reassignment ----
	// Called from script.js's Guest.loadProfile(). Defined globally (rather
	// than script.js importing this file directly, which it can't do -- both
	// are plain <script> tags, not modules) so script.js only needs a
	// `typeof renderGuestFacilityControls === 'function'` check and doesn't
	// have to know facility.js exists at all. If facility.js somehow failed
	// to load, the profile page just silently skips the reassignment UI
	// rather than throwing.
	window.renderGuestFacilityControls = async function (guestId) {
		const container = document.getElementById('profile-facility-controls');
		if (!container) return;
		container.innerHTML = '<p>Loading facility info...</p>';

		const res = await fetch('getdata.php?mode=getguestfacility&id=' + encodeURIComponent(guestId));
		const json = await res.json();
		if (!json.success) {
			container.innerHTML = `<p class="admin-error">${escapeHtml(json.error || 'Failed to load facility info.')}</p>`;
			return;
		}
		const d = json.data;

		let html = '';

		if (d.needs_reassignment) {
			html += `<p class="facility-needs-reassignment-banner">This guest was moved here automatically after their previous room/section/location was deleted. Please reassign them below.</p>`;
		}

		// Photo + name/DOB. Read-only display for anyone who can see the
		// guest at all; the edit form only renders if can_edit_info (same
		// permission as reassignment -- location admin of this guest's
		// location, or master admin).
		const photoUrl = d.profile_picture
			? 'guest_picture.php?action=view&id=' + encodeURIComponent(guestId) + '&v=' + encodeURIComponent(d.profile_picture)
			: 'images/DP.png';
		html += `
			<div class="facility-guest-info">
				<img src="${photoUrl}" class="facility-guest-photo" alt="${escapeHtml(d.guest_name)}" />
				<div class="facility-guest-info-fields">`;

		if (d.can_edit_info) {
			html += `
					<form id="guest-info-form" class="admin-create-form">
						<input type="text" id="guest-info-name" value="${escapeHtml(d.guest_name)}" placeholder="Name" required />
						<input type="date" id="guest-info-dob" value="${d.dob ? escapeHtml(d.dob) : ''}" />
						<button type="submit">Save</button>
					</form>
					<label class="facility-photo-upload-label">
						Change photo: <input type="file" id="guest-info-photo" accept="image/jpeg,image/png,image/webp" />
					</label>
					<p class="admin-error" id="guest-info-error" style="display:none"></p>`;
		} else {
			html += `
					<p class="facility-guest-current"><strong>${escapeHtml(d.guest_name)}</strong></p>
					<p class="facility-guest-current">DOB: ${d.dob ? escapeHtml(d.dob) : 'Not set'}</p>`;
		}

		html += `
				</div>
			</div>
			<p class="facility-guest-current"><strong>Location:</strong> ${escapeHtml(d.location_name)}
				&nbsp;|&nbsp; <strong>Section:</strong> ${escapeHtml(d.section_name)}
				&nbsp;|&nbsp; <strong>Room:</strong> ${escapeHtml(d.room_name)}</p>`;

		if (d.can_reassign) {
			html += `
				<form id="guest-reassign-form" class="admin-create-form">
					${d.can_change_location ? `<select id="guest-reassign-location"></select>` : ''}
					<select id="guest-reassign-section"></select>
					<select id="guest-reassign-room"></select>
					<button type="submit">Reassign</button>
				</form>
				<p class="admin-error" id="guest-reassign-error" style="display:none"></p>`;
		}

		container.innerHTML = html;

		if (d.can_edit_info) {
			document.getElementById('guest-info-form').addEventListener('submit', async (event) => {
				event.preventDefault();
				const errorEl = document.getElementById('guest-info-error');
				errorEl.style.display = 'none';

				const name = document.getElementById('guest-info-name').value;
				const dob = document.getElementById('guest-info-dob').value;
				const body = new URLSearchParams({ mode: 'updateguestinfo', id: guestId, name, dob });

				try {
					const res2 = await fetch('getdata.php', { method: 'POST', body });
					const json2 = await res2.json();
					if (json2.success) {
						// Reflect the new name immediately in the Guest object and
						// the top-of-page display, without needing a full reload.
						if (guests.data[guestId]) {
							guests.data[guestId].name = name;
							guests.data[guestId].refreshInfo();
						}
						if (typeof profileName !== 'undefined') profileName.innerText = name;
						if (typeof showToast === 'function') showToast('Guest info updated.', 'success');
					} else {
						errorEl.textContent = json2.error || 'Update failed.';
						errorEl.style.display = '';
					}
				} catch (err) {
					errorEl.textContent = 'Could not reach the server.';
					errorEl.style.display = '';
				}
			});

			document.getElementById('guest-info-photo').addEventListener('change', async (event) => {
				const file = event.target.files[0];
				if (!file) return;
				const errorEl = document.getElementById('guest-info-error');
				errorEl.style.display = 'none';

				const formData = new FormData();
				formData.append('action', 'upload');
				formData.append('id', guestId);
				formData.append('photo', file);

				try {
					const res2 = await fetch('guest_picture.php', { method: 'POST', body: formData });
					const json2 = await res2.json();
					if (json2.success) {
						if (typeof showToast === 'function') showToast('Photo updated.', 'success');
						window.renderGuestFacilityControls(guestId); // refresh to show the new photo
					} else {
						errorEl.textContent = json2.error || 'Upload failed.';
						errorEl.style.display = '';
					}
				} catch (err) {
					errorEl.textContent = 'Could not reach the server.';
					errorEl.style.display = '';
				}
			});
		}

		if (!d.can_reassign) return;

		const locationSelect = document.getElementById('guest-reassign-location');
		const sectionSelect = document.getElementById('guest-reassign-section');
		const roomSelect = document.getElementById('guest-reassign-room');

		// Cascading dropdowns: changing location repopulates its sections
		// (defaulting to the guest's current section if it's in that location,
		// otherwise whatever's first), which in turn repopulates that section's
		// rooms the same way. All from the single `d.locations` tree fetched
		// once above -- no extra requests as the user changes selections.
		function populateSections(locationId) {
			const loc = d.locations.find(l => l.location_id === locationId);
			sectionSelect.innerHTML = (loc ? loc.sections : []).map(s =>
				`<option value="${s.section_id}" ${s.section_id === d.section_id ? 'selected' : ''}>${escapeHtml(s.section_name)}</option>`
			).join('');
			populateRooms(locationId, parseInt(sectionSelect.value, 10));
		}

		function populateRooms(locationId, sectionId) {
			const loc = d.locations.find(l => l.location_id === locationId);
			const section = loc ? loc.sections.find(s => s.section_id === sectionId) : null;
			roomSelect.innerHTML = (section ? section.rooms : []).map(r =>
				`<option value="${r.room_id}" ${r.room_id === d.room_id ? 'selected' : ''}>${escapeHtml(r.room_name)}</option>`
			).join('');
		}

		if (locationSelect) {
			locationSelect.innerHTML = d.locations.map(l =>
				`<option value="${l.location_id}" ${l.location_id === d.location_id ? 'selected' : ''}>${escapeHtml(l.location_name)}</option>`
			).join('');
			locationSelect.addEventListener('change', () => populateSections(parseInt(locationSelect.value, 10)));
			populateSections(parseInt(locationSelect.value, 10));
		} else {
			populateSections(d.location_id);
		}

		sectionSelect.addEventListener('change', () => {
			const locationId = locationSelect ? parseInt(locationSelect.value, 10) : d.location_id;
			populateRooms(locationId, parseInt(sectionSelect.value, 10));
		});

		document.getElementById('guest-reassign-form').addEventListener('submit', async (event) => {
			event.preventDefault();
			const errorEl = document.getElementById('guest-reassign-error');
			errorEl.style.display = 'none';

			const qs = new URLSearchParams({
				mode: 'reassignguest',
				id: guestId,
				section_id: sectionSelect.value,
				room_id: roomSelect.value,
			});
			try {
				const res2 = await fetch('getdata.php?' + qs.toString());
				const json2 = await res2.json();
				if (json2.success) {
					if (typeof showToast === 'function') showToast('Guest reassigned.', 'success');
					window.renderGuestFacilityControls(guestId);
				} else {
					errorEl.textContent = json2.error || 'Reassign failed.';
					errorEl.style.display = '';
				}
			} catch (err) {
				errorEl.textContent = 'Could not reach the server.';
				errorEl.style.display = '';
			}
		});
	};
})();
