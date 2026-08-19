////////////////////////////////////
// == LOGIN / SESSION GATEKEEPER == //
////////////////////////////////////
// Include this AFTER script.js. Checks session on load, shows/hides the
// app accordingly, and handles login (with "remember me"), forgot-password,
// and resend-verification flows entirely within the #login page.

(function () {
	const sideNav = document.querySelector('.side-nav');
	const loginPage = document.getElementById('login');
	const adminNavLink = document.getElementById('admin-button');
	const pluginsNavLink = document.getElementById('plugins-button');

	function showLoggedOutState() {
		sideNav.style.display = 'none';
		document.querySelectorAll('main.content > div').forEach(div => {
			div.style.display = (div.id === 'login') ? '' : 'none';
		});
	}

	function showLoggedInState(isAdmin, isLocationAdmin) {
		sideNav.style.display = '';
		// Admin Users is reachable by master admins AND location admins now
		// (admin_users.php scopes what a location admin sees/can do there --
		// see the comment at the top of that file). Plugins stays strictly
		// master-admin-only. Either way, hiding/showing the nav link here is
		// just UX -- the actual access control happens server-side in
		// admin_users.php/plugins.php regardless of whether someone finds
		// their way to #admin_users or #plugins directly.
		if (adminNavLink) {
			adminNavLink.style.display = (isAdmin || isLocationAdmin) ? '' : 'none';
		}
		if (pluginsNavLink) {
			pluginsNavLink.style.display = isAdmin ? '' : 'none';
		}
		// Note: Facility Location's nav link is NOT hidden here for
		// non-admins -- unlike the two above, that page is meant to be seen
		// by any assigned user (just showing them only what they're
		// assigned to, or an empty state if they're assigned to nothing).
	}

	async function checkSession() {
		try {
			const res = await fetch('auth.php?action=check');
			const json = await res.json();
			if (json.success && json.data.loggedIn) {
				showLoggedInState(json.data.is_admin, json.data.is_location_admin);
				window.currentUser = { username: json.data.username, isAdmin: json.data.is_admin, isLocationAdmin: json.data.is_location_admin };
			} else {
				showLoggedOutState();
			}
		} catch (err) {
			if (window.debugMode) console.error('Session check failed:', err);
			showLoggedOutState();
		}
	}

	async function postForm(url, fields) {
		const body = new URLSearchParams();
		Object.entries(fields).forEach(([k, v]) => body.set(k, v));
		const res = await fetch(url, { method: 'POST', body });
		return res.json();
	}

	// ---- View: login form ----

	function renderLoginForm() {
		loginPage.innerHTML = `
			<div class="login-box">
				<div class="login-header"><img src="images/A.png" />quaSense</div>
				<form id="login-form">
					<input type="text" id="login-username" placeholder="Username" autocomplete="username" required />
					<input type="password" id="login-password" placeholder="Password" autocomplete="current-password" required />
					<label class="login-remember-label">
						<input type="checkbox" id="login-remember" /> Remember me
					</label>
					<button type="submit">Log in</button>
					<p class="login-error" id="login-error" style="display:none"></p>
					<p class="login-resend" id="login-resend" style="display:none">
						<button type="button" id="login-resend-btn">Resend verification email</button>
					</p>
				</form>
				<a href="#" id="login-forgot-link" class="login-secondary-link">Forgot your password?</a>
			</div>
		`;

		document.getElementById('login-form').addEventListener('submit', onLoginSubmit);
		document.getElementById('login-forgot-link').addEventListener('click', (event) => {
			event.preventDefault();
			renderForgotPasswordForm();
		});
	}

	let pendingUnverifiedEmail = null;

	async function onLoginSubmit(event) {
		event.preventDefault();
		const username = document.getElementById('login-username').value;
		const password = document.getElementById('login-password').value;
		const remember = document.getElementById('login-remember').checked;
		const errorEl = document.getElementById('login-error');
		const resendEl = document.getElementById('login-resend');
		errorEl.style.display = 'none';
		resendEl.style.display = 'none';

		try {
			const json = await postForm('auth.php', {
				action: 'login',
				username,
				password,
				remember: remember ? '1' : '',
			});

			if (json.success) {
				window.location.reload();
				return;
			}

			errorEl.textContent = json.error || 'Login failed.';
			errorEl.style.display = '';

			if (json.data && json.data.needsVerification) {
				pendingUnverifiedEmail = json.data.email;
				resendEl.style.display = '';
				document.getElementById('login-resend-btn').onclick = onResendVerification;
			}
		} catch (err) {
			errorEl.textContent = 'Could not reach the server. Please try again.';
			errorEl.style.display = '';
		}
	}

	async function onResendVerification() {
		const btn = document.getElementById('login-resend-btn');
		btn.disabled = true;
		btn.textContent = 'Sending...';
		try {
			const json = await postForm('auth.php', { action: 'resend_verification', email: pendingUnverifiedEmail });
			btn.textContent = json.data && json.data.message ? json.data.message : 'Sent.';
		} catch (err) {
			btn.textContent = 'Failed to send. Try again.';
			btn.disabled = false;
		}
	}

	// ---- View: forgot password ----

	function renderForgotPasswordForm() {
		loginPage.innerHTML = `
			<div class="login-box">
				<div class="login-header">Reset password</div>
				<form id="forgot-form">
					<input type="email" id="forgot-email" placeholder="Your email address" autocomplete="email" required />
					<button type="submit">Send reset link</button>
					<p class="login-error" id="forgot-error" style="display:none"></p>
					<p class="login-success" id="forgot-success" style="display:none"></p>
				</form>
				<a href="#" id="forgot-back-link" class="login-secondary-link">Back to login</a>
			</div>
		`;

		document.getElementById('forgot-back-link').addEventListener('click', (event) => {
			event.preventDefault();
			renderLoginForm();
		});

		document.getElementById('forgot-form').addEventListener('submit', async (event) => {
			event.preventDefault();
			const email = document.getElementById('forgot-email').value;
			const errorEl = document.getElementById('forgot-error');
			const successEl = document.getElementById('forgot-success');
			errorEl.style.display = 'none';
			successEl.style.display = 'none';

			try {
				const json = await postForm('auth.php', { action: 'forgot_password', email });
				if (json.success) {
					successEl.textContent = json.data.message;
					successEl.style.display = '';
					document.getElementById('forgot-form').reset();
				} else {
					errorEl.textContent = json.error || 'Something went wrong.';
					errorEl.style.display = '';
				}
			} catch (err) {
				errorEl.textContent = 'Could not reach the server.';
				errorEl.style.display = '';
			}
		});
	}

	// ---- Logout ----

	function wireLogoutButton() {
		const logoutBtn = document.getElementById('logout-button');
		if (!logoutBtn) return;
		logoutBtn.addEventListener('click', async (event) => {
			event.preventDefault();
			event.stopImmediatePropagation();
			try {
				await fetch('auth.php', { method: 'POST', body: new URLSearchParams({ action: 'logout' }) });
			} catch (err) {
				if (window.debugMode) console.error('Logout request failed:', err);
			}
			window.location.reload();
		}, true); // capture phase so this runs before script.js's menu click handler
	}

	renderLoginForm();
	wireLogoutButton();
	checkSession();
})();
