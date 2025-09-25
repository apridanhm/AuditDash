<?php
require __DIR__ . '/auth.php';

$error = '';
// amankan parameter next: hanya path lokal
$next = $_GET['next'] ?? '/';
if (!is_string($next) || !preg_match('#^/[^:]*$#', $next)) {
  $next = '/';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim((string)($_POST['username'] ?? ''));
  $p = (string)($_POST['password'] ?? '');
  $next_post = $_POST['next'] ?? $next;
  if (!is_string($next_post) || !preg_match('#^/[^:]*$#', $next_post)) {
    $next_post = '/';
  }

  $stmt = $pdo->prepare('SELECT id,username,password_hash FROM users WHERE username=?');
  $stmt->execute([$u]);
  $user = $stmt->fetch();

  if ($user && password_verify($p, $user['password_hash'])) {
    $_SESSION['user_id']  = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    header('Location: ' . $next_post);
    exit;
  }
  $error = 'Username atau password salah.';
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login | AuditDash</title>
<style>
  :root{
    --bg:#0b1220; --bg2:#0a1326;
    --card:#0f172a; --line:#1e2a44; --txt:#e6edf3; --muted:#9fb0c3;
    --accent:#3b82f6; --accent-2:#60a5fa; --danger:#ef4444;
  }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{
    margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; color:var(--txt);
    background:
      radial-gradient(900px 500px at 20% 0%, #14223f66, transparent),
      radial-gradient(900px 500px at 80% 100%, #13203a55, transparent),
      linear-gradient(180deg, var(--bg2), var(--bg));
    display:grid; place-items:center;
    padding:24px;
  }
  .card{
    width:min(92vw, 420px);
    background:linear-gradient(180deg, #0f172a, #0d1526);
    border:1px solid var(--line);
    border-radius:16px;
    padding:28px 24px 22px;
    box-shadow:
      0 20px 60px rgba(0,0,0,.45),
      inset 0 1px 0 rgba(255,255,255,.06);
  }
  .brand{display:flex; gap:14px; align-items:center; margin-bottom:14px}
  .logo{
    width:42px;height:42px;border-radius:12px;display:grid;place-items:center;
    background:linear-gradient(145deg,#1f2a44,#111a2e);
    border:1px solid #213052;color:#b7c7ff;font-weight:800;letter-spacing:.5px;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.08);
  }
  h1{margin:0; font-size:24px; line-height:1.25}
  .subtitle{color:var(--muted); font-size:13px; margin-top:2px}

  .err{
    margin:10px 0 14px; padding:10px 12px; font-size:13px;
    background:#ef44441a; color:#fecaca; border:1px solid #7f1d1d; border-radius:10px;
  }

  .field{margin-top:14px}
  label{display:block; font-size:13px; color:var(--muted); margin:0 0 6px}
  .control{
    position:relative; display:flex; align-items:center;
    background:#0b1220; border:1px solid var(--line); border-radius:12px;
    padding:10px 12px;
    transition:border-color .15s ease, box-shadow .15s ease;
  }
  .control:focus-within{
    border-color:#314875;
    box-shadow:0 0 0 4px rgba(59,130,246,.15);
  }
  input{
    border:0; outline:0; background:transparent; color:var(--txt);
    width:100%; font-size:14px;
  }
  .ghost-btn{
    margin-left:8px; padding:6px 8px; font-size:12px; line-height:1;
    color:var(--muted); background:#0e162a; border:1px solid #1c2946;
    border-radius:8px; cursor:pointer;
  }
  .ghost-btn:hover{color:var(--txt); border-color:#2a3b62}

  button[type=submit]{
    width:100%; margin-top:18px; padding:13px 14px; font-size:16px; font-weight:700;
    color:#0b1220; background:linear-gradient(180deg,var(--accent-2),var(--accent));
    border:0; border-radius:12px; cursor:pointer;
    box-shadow:0 10px 24px rgba(59,130,246,.35);
    transition:filter .15s ease, transform .05s ease;
  }
  button[type=submit]:hover{filter:brightness(1.03)}
  button[type=submit]:active{transform:translateY(1px)}

  .foot{margin-top:14px; text-align:center; color:var(--muted); font-size:12px}
</style>
</head>
<body>

<div class="card" role="main">
  <div class="brand">
    <div class="logo">AD</div>
    <div>
      <h1>AuditDash Login</h1>
      <div class="subtitle">Masuk untuk melihat event audit.</div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="err"><?=htmlspecialchars($error)?></div>
  <?php endif; ?>

  <form method="post" id="loginForm" autocomplete="on" novalidate>
    <input type="hidden" name="next" value="<?=htmlspecialchars($next)?>">

    <div class="field">
      <label for="user">Username</label>
      <div class="control">
        <input id="user" name="username" type="text" required autofocus>
      </div>
    </div>

    <div class="field">
      <label for="pass">Password</label>
      <div class="control">
        <input id="pass" name="password" type="password" required>
        <button type="button" class="ghost-btn" id="togglePwd" aria-label="Tampilkan password">Show</button>
      </div>
    </div>

    <button type="submit">Masuk</button>
    <div class="foot">© <?=date('Y')?> AuditDash</div>
  </form>
</div>

<script>
  // Toggle show/hide password + aksesibilitas
  (function(){
    const pass = document.getElementById('pass');
    const tgl  = document.getElementById('togglePwd');
    tgl.addEventListener('click', ()=>{
      const isPwd = pass.type === 'password';
      pass.type = isPwd ? 'text' : 'password';
      tgl.textContent = isPwd ? 'Hide' : 'Show';
      tgl.setAttribute('aria-label', isPwd ? 'Sembunyikan password' : 'Tampilkan password');
      pass.focus({preventScroll:true});
    });
  })();
</script>

</body>
</html>
