////////////////////////////////
// == SETTINGS - MY ACCOUNT == //
////////////////////////////////
// Include this AFTER login.js. Populates the #settings page with a
// "change my password" form for the currently logged-in user (any user,
// not just admins).

(function () {
	const settingsPage = document.getElementById('settings');

	function render() {
		settingsPage.innerHTML = `
			<div class="main-content">
				<h2 class="admin-header">Change Password</h2>
				<form id="change-password-form" class="admin-create-form">
					<input type="password" id="current-password" placeholder="Current password" autocomplete="current-password" required />
					<input type="password" id="new-password" placeholder="New password (min 8 chars)" minlength="8" autocomplete="new-password" required />
					<input type="password" id="confirm-password" placeholder="Confirm new password" minlength="8" autocomplete="new-password" required />
					<button type="submit">Change password</button>
				</form>
				<p class="admin-error" id="settings-error" style="display:none"></p>
				<p class="login-success" id="settings-success" style="display:none"></p>
			</div>
		`;

		document.getElementById('change-password-form').addEventListener('submit', onSubmit);
	}

	async function onSubmit(event) {
		event.preventDefault();
		const errorEl = document.getElementById('settings-error');
		const successEl = document.getElementById('settings-success');
		errorEl.style.display = 'none';
		successEl.style.display = 'none';

		const current = document.getElementById('current-password').value;
		const next = document.getElementById('new-password').value;
		const confirm = document.getElementById('confirm-password').value;

		if (next !== confirm) {
			errorEl.textContent = "New passwords don't match.";
			errorEl.style.display = '';
			return;
		}

		const body = new URLSearchParams();
		body.set('action', 'change_password');
		body.set('current_password', current);
		body.set('new_password', next);

		try {
			const res = await fetch('auth.php', { method: 'POST', body });
			const json = await res.json();
			if (json.success) {
				// auth.php's change_password revokes remember-me tokens on OTHER
				// devices as a precaution, but keeps this session logged in --
				// hence the note below rather than forcing a reload/logout here.
				successEl.textContent = json.data.message + ' (You may need to log in again on other devices.)';
				successEl.style.display = '';
				document.getElementById('change-password-form').reset();
				if (typeof showToast === 'function') showToast('Password changed.', 'success');
			} else {
				errorEl.textContent = json.error || 'Failed to change password.';
				errorEl.style.display = '';
			}
		} catch (err) {
			errorEl.textContent = 'Could not reach the server.';
			errorEl.style.display = '';
		}
	}

	render();
})();
