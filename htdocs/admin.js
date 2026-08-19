////////////////////////
// == ADMIN - USERS == //
////////////////////////
// Include this AFTER login.js. Populates the #admin page with a user
// management table. Reachable by master admins (full access) AND location
// admins (scoped to users assigned to a location they administer) --
// admin_users.php decides which of those two the caller is and returns
// `is_master_admin` alongside the user list, which is what this file uses
// to decide whether to show the create-user form and the is_admin/is_active/
// delete controls. That's a UI convenience only: admin_users.php enforces
// the same restrictions server-side regardless of what this file renders.

(function () {
	const adminPage = document.getElementById('admin');
	let isMasterAdmin = false; // set once the first successful list response comes back

	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str == null ? '' : String(str);
		return div.innerHTML;
	}

	// Full (re)build: fetches the user list, learns is_master_admin from the
	// response, and renders the page shell (including the create-user form,
	// only for master admins) plus the table. Used for the initial load and
	// any time the caller's scope might matter to re-derive (in practice
	// just once -- isMasterAdmin doesn't change mid-session).
	async function render() {
		adminPage.innerHTML = `<div class="main-content"><p>Loading...</p></div>`;
		try {
			const res = await fetch('admin_users.php?action=list');
			const json = await res.json();
			if (!json.success) {
				adminPage.innerHTML = `<div class="main-content"><p class="admin-error">${escapeHtml(json.error || 'Failed to load users.')}</p></div>`;
				return;
			}
			isMasterAdmin = json.data.is_master_admin;
			renderShell();
			renderRows(json.data.users);
		} catch (err) {
			adminPage.innerHTML = `<div class="main-content"><p class="admin-error">Could not reach the server.</p></div>`;
		}
	}

	// Lighter refresh used after create/update/delete actions: keeps the
	// already-built shell (form or scope note) and just re-fetches + repaints
	// the table body, since isMasterAdmin won't have changed.
	async function loadUsers() {
		const errorEl = document.getElementById('admin-error');
		errorEl.style.display = 'none';
		try {
			const res = await fetch('admin_users.php?action=list');
			const json = await res.json();
			if (!json.success) {
				errorEl.textContent = json.error || 'Failed to load users.';
				errorEl.style.display = '';
				return;
			}
			renderRows(json.data.users);
		} catch (err) {
			errorEl.textContent = 'Could not reach the server.';
			errorEl.style.display = '';
		}
	}

	function renderShell() {
		adminPage.innerHTML = `
			<div class="main-content">
				<h2 class="admin-header">Manage Users</h2>
				${isMasterAdmin ? `
				<form id="admin-create-form" class="admin-create-form">
					<input type="text" id="admin-new-username" placeholder="Username" required />
					<input type="email" id="admin-new-email" placeholder="Email" required />
					<input type="password" id="admin-new-password" placeholder="Password (min 8 chars)" minlength="8" required />
					<label><input type="checkbox" id="admin-new-isadmin" /> Admin</label>
					<button type="submit">Add user</button>
				</form>
				` : `
				<p class="admin-scope-note">You're seeing users assigned to a location you administer. To add an existing
				user to your location, use the Facility Location page. Creating new accounts, and changing admin or
				active status, requires a master admin.</p>
				`}
				<p class="admin-error" id="admin-error" style="display:none"></p>
				<table class="admin-users-table">
					<thead>
						<tr>
							<th>Username</th>
							<th>Email</th>
							<th>Verified</th>
							<th>Admin</th>
							<th>Active</th>
							<th>Last login</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody id="admin-users-body"></tbody>
				</table>
			</div>
		`;

		if (isMasterAdmin) {
			document.getElementById('admin-create-form').addEventListener('submit', onCreateUser);
		}
	}

	function renderRows(users) {
		const body = document.getElementById('admin-users-body');
		body.innerHTML = '';
		users.forEach(u => {
			const tr = document.createElement('tr');

			const nameTd = document.createElement('td');
			nameTd.textContent = u.username;

			const emailTd = document.createElement('td');
			emailTd.textContent = u.email;

			const verifiedTd = document.createElement('td');
			if (Number(u.email_verified)) {
				verifiedTd.textContent = 'Yes';
			} else {
				// Resend verification is allowed for location admins too
				// (scoped server-side to users in their location), so this
				// button isn't gated on isMasterAdmin.
				const resendBtn = document.createElement('button');
				resendBtn.type = 'button';
				resendBtn.textContent = 'Resend verification';
				resendBtn.addEventListener('click', () => resendVerification(u.user_id, resendBtn));
				verifiedTd.appendChild(resendBtn);
			}

			// Admin flag: master admins get a live checkbox. Location admins
			// see the same checkbox, disabled/greyed out, so they can still
			// tell at a glance who's a master admin without being able to
			// change it -- admin_users.php rejects any attempt to change
			// this server-side regardless, this is just the UI reflecting
			// that up front instead of letting them click it and fail.
			const adminTd = document.createElement('td');
			const adminCb = document.createElement('input');
			adminCb.type = 'checkbox';
			adminCb.checked = !!Number(u.is_admin);
			if (isMasterAdmin) {
				adminCb.addEventListener('change', () => updateUser(u.user_id, { is_admin: adminCb.checked ? 1 : 0 }));
			} else {
				adminCb.disabled = true;
			}
			adminTd.appendChild(adminCb);

			// Active flag: same reasoning as admin flag above -- it's a
			// global, account-wide setting, so it's master-admin-only even
			// though this row might be a user the location admin otherwise
			// manages. A location admin removing someone from THEIR
			// location uses the Facility Location page's per-location
			// remove instead, which doesn't touch the account globally.
			const activeTd = document.createElement('td');
			const activeCb = document.createElement('input');
			activeCb.type = 'checkbox';
			activeCb.checked = !!Number(u.is_active);
			if (isMasterAdmin) {
				activeCb.addEventListener('change', () => updateUser(u.user_id, { is_active: activeCb.checked ? 1 : 0 }));
			} else {
				activeCb.disabled = true;
			}
			activeTd.appendChild(activeCb);

			const lastLoginTd = document.createElement('td');
			lastLoginTd.textContent = u.last_login || 'Never';

			const actionsTd = document.createElement('td');

			// Password reset is allowed for location admins too (scoped
			// server-side) -- resetting a user's password for someone they
			// administer is a reasonable "manage this user" action.
			const resetBtn = document.createElement('button');
			resetBtn.type = 'button';
			resetBtn.textContent = 'Reset password';
			resetBtn.addEventListener('click', () => resetPassword(u.user_id, u.username));
			actionsTd.appendChild(resetBtn);

			// Delete is destructive and account-wide -- master admin only,
			// so the button is simply not shown for a location admin rather
			// than shown-and-disabled (there's nothing useful to explain by
			// greying out a delete button the way there is for a checkbox).
			if (isMasterAdmin) {
				const deleteBtn = document.createElement('button');
				deleteBtn.type = 'button';
				deleteBtn.textContent = 'Delete';
				deleteBtn.addEventListener('click', () => deleteUser(u.user_id, u.username));
				actionsTd.appendChild(deleteBtn);
			}

			tr.appendChild(nameTd);
			tr.appendChild(emailTd);
			tr.appendChild(verifiedTd);
			tr.appendChild(adminTd);
			tr.appendChild(activeTd);
			tr.appendChild(lastLoginTd);
			tr.appendChild(actionsTd);
			body.appendChild(tr);
		});
	}

	async function onCreateUser(event) {
		event.preventDefault();
		const errorEl = document.getElementById('admin-error');
		errorEl.style.display = 'none';

		const username = document.getElementById('admin-new-username').value;
		const email = document.getElementById('admin-new-email').value;
		const password = document.getElementById('admin-new-password').value;
		const isAdmin = document.getElementById('admin-new-isadmin').checked;

		const body = new URLSearchParams();
		body.set('action', 'create');
		body.set('username', username);
		body.set('email', email);
		body.set('password', password);
		if (isAdmin) body.set('is_admin', '1');

		try {
			const res = await fetch('admin_users.php', { method: 'POST', body });
			const json = await res.json();
			if (!json.success) {
				errorEl.textContent = json.error || 'Failed to create user.';
				errorEl.style.display = '';
				return;
			}
			document.getElementById('admin-create-form').reset();
			loadUsers();
			if (typeof showToast === 'function') showToast('User created.', 'success');
		} catch (err) {
			errorEl.textContent = 'Could not reach the server.';
			errorEl.style.display = '';
		}
	}

	async function updateUser(userId, fields) {
		const errorEl = document.getElementById('admin-error');
		errorEl.style.display = 'none';

		const body = new URLSearchParams();
		body.set('action', 'update');
		body.set('user_id', userId);
		Object.entries(fields).forEach(([k, v]) => body.set(k, v));

		try {
			const res = await fetch('admin_users.php', { method: 'POST', body });
			const json = await res.json();
			if (!json.success) {
				errorEl.textContent = json.error || 'Update failed.';
				errorEl.style.display = '';
				loadUsers(); // reload to reset checkboxes to true server state
				return;
			}
			if (typeof showToast === 'function') showToast('User updated.', 'success');
		} catch (err) {
			errorEl.textContent = 'Could not reach the server.';
			errorEl.style.display = '';
		}
	}

	async function resetPassword(userId, username) {
		const newPassword = prompt(`New password for ${username} (min 8 characters):`);
		if (!newPassword) return;
		if (newPassword.length < 8) {
			alert('Password must be at least 8 characters.');
			return;
		}
		await updateUser(userId, { password: newPassword });
	}

	async function deleteUser(userId, username) {
		if (!confirm(`Delete user "${username}"? This can't be undone.`)) return;

		const body = new URLSearchParams();
		body.set('action', 'delete');
		body.set('user_id', userId);

		try {
			const res = await fetch('admin_users.php', { method: 'POST', body });
			const json = await res.json();
			if (!json.success) {
				alert(json.error || 'Delete failed.');
				return;
			}
			loadUsers();
			if (typeof showToast === 'function') showToast('User deleted.', 'success');
		} catch (err) {
			alert('Could not reach the server.');
		}
	}

	async function resendVerification(userId, btn) {
		btn.disabled = true;
		btn.textContent = 'Sending...';
		try {
			const res = await fetch('admin_users.php', {
				method: 'POST',
				body: new URLSearchParams({ action: 'resend_verification', user_id: userId }),
			});
			const json = await res.json();
			if (json.success) {
				btn.textContent = 'Sent';
			} else {
				btn.textContent = json.error || 'Failed';
				btn.disabled = false;
			}
		} catch (err) {
			btn.textContent = 'Failed';
			btn.disabled = false;
		}
	}

	render();
})();
