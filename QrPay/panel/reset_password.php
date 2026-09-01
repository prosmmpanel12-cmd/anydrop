<?php
/**
 * QrPay — GET /panel/reset_password.php?token=...
 *
 * Standalone page (no login required — the token IS the identity proof,
 * see auth/reset_password.php). Reached by clicking the link in the
 * email sent from auth/forgot_password.php.
 *
 * This is a plain page ahead of the full Phase 6 dashboard build —
 * intentionally self-contained (inline CSS/JS, no external dashboard
 * assets) so it doesn't depend on anything not built yet.
 */
$token = trim($_GET['token'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — QrPay</title>
<style>
  :root {
    --bg: #0b0d10;
    --card: #14171c;
    --border: #23272e;
    --text: #eef0f2;
    --muted: #9aa3ad;
    --accent: #4f7cff;
    --accent-hover: #3d67e6;
    --error: #ff5c5c;
    --success: #35c675;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    padding: 24px;
  }
  .card {
    width: 100%;
    max-width: 380px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 32px 28px;
  }
  .brand {
    font-weight: 700;
    font-size: 15px;
    letter-spacing: 0.02em;
    color: var(--muted);
    margin-bottom: 18px;
  }
  h1 {
    font-size: 20px;
    margin: 0 0 6px;
  }
  p.sub {
    color: var(--muted);
    font-size: 13.5px;
    margin: 0 0 24px;
    line-height: 1.5;
  }
  label {
    display: block;
    font-size: 13px;
    color: var(--muted);
    margin: 16px 0 6px;
  }
  input[type="password"] {
    width: 100%;
    padding: 11px 12px;
    background: #0f1216;
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-size: 14px;
    outline: none;
    transition: border-color 0.15s;
  }
  input[type="password"]:focus {
    border-color: var(--accent);
  }
  button {
    width: 100%;
    margin-top: 24px;
    padding: 12px;
    border: none;
    border-radius: 8px;
    background: var(--accent);
    color: #fff;
    font-size: 14.5px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
  }
  button:hover:not(:disabled) { background: var(--accent-hover); }
  button:disabled { opacity: 0.6; cursor: not-allowed; }
  .msg {
    margin-top: 16px;
    font-size: 13.5px;
    padding: 10px 12px;
    border-radius: 8px;
    line-height: 1.5;
    display: none;
  }
  .msg.error { display: block; background: rgba(255,92,92,0.1); color: var(--error); border: 1px solid rgba(255,92,92,0.25); }
  .msg.success { display: block; background: rgba(53,198,117,0.1); color: var(--success); border: 1px solid rgba(53,198,117,0.25); }
  .hint { font-size: 12px; color: var(--muted); margin-top: 6px; }
  a { color: var(--accent); text-decoration: none; }
  .missing-token { text-align: center; padding: 8px 0; }
</style>
</head>
<body>
  <div class="card">
    <div class="brand">QrPay</div>

    <?php if (empty($token)): ?>
      <div class="missing-token">
        <h1>Invalid link</h1>
        <p class="sub">This password reset link is missing its token. Please request a new one from the login page.</p>
      </div>
    <?php else: ?>
      <h1>Reset your password</h1>
      <p class="sub">Choose a new password for your QrPay account.</p>

      <form id="resetForm" autocomplete="off">
        <input type="hidden" id="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

        <label for="password">New password</label>
        <input type="password" id="password" required minlength="8" autocomplete="new-password">
        <div class="hint">At least 8 characters.</div>

        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" required minlength="8" autocomplete="new-password">

        <button type="submit" id="submitBtn">Reset Password</button>
        <div class="msg" id="msgBox"></div>
      </form>
    <?php endif; ?>
  </div>

<?php if (!empty($token)): ?>
<script>
  const form = document.getElementById('resetForm');
  const submitBtn = document.getElementById('submitBtn');
  const msgBox = document.getElementById('msgBox');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const token = document.getElementById('token').value;

    msgBox.className = 'msg';
    msgBox.textContent = '';

    if (password !== confirmPassword) {
      msgBox.className = 'msg error';
      msgBox.textContent = 'Password and confirm password do not match.';
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Resetting…';

    try {
      const res = await fetch('/auth/reset_password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token, password, confirm_password: confirmPassword }),
      });
      const data = await res.json();

      if (data.status === 'success') {
        msgBox.className = 'msg success';
        msgBox.textContent = data.message + ' Redirecting to login…';
        form.querySelectorAll('input, button').forEach(el => el.disabled = true);
        setTimeout(() => { window.location.href = '/panel/login.php'; }, 2000);
      } else {
        msgBox.className = 'msg error';
        msgBox.textContent = data.message || 'Something went wrong. Please try again.';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Reset Password';
      }
    } catch (err) {
      msgBox.className = 'msg error';
      msgBox.textContent = 'Network error. Please try again.';
      submitBtn.disabled = false;
      submitBtn.textContent = 'Reset Password';
    }
  });
</script>
<?php endif; ?>
</body>
</html>
