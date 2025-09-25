<?php
// hosts.php — daftar & kelola host (tambah + hapus) dengan tabel rapi
require __DIR__ . '/auth.php';
require_login();

/** @var PDO $pdo from auth.php */

// ==== CSRF token sederhana ====
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

// ==== helper ====
function gen_token(int $bytes = 16): string {
  return bin2hex(random_bytes($bytes));
}
function now_http_base(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
  return $scheme . $_SERVER['HTTP_HOST'];
}

// ==== tindakan POST ====
$flash = null;
$new_token = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $hostname = trim((string)($_POST['hostname'] ?? ''));
    $ip       = trim((string)($_POST['ip'] ?? ''));

    if ($hostname === '') {
      $flash = ['type' => 'err', 'msg' => 'Hostname wajib diisi.'];
    } else {
      // generate token unik (retry jika tabrakan)
      $token = gen_token(16);
      for ($i=0; $i<3; $i++) {
        try {
          $stmt = $pdo->prepare("INSERT INTO hosts (hostname, ip, token, last_seen) VALUES (?, ?, ?, NULL)");
          $stmt->execute([$hostname, $ip ?: null, $token]);
          $new_token = $token;
          $flash = ['type' => 'ok', 'msg' => 'Host ditambahkan. Salin TOKEN di bawah.'];
          break;
        } catch (PDOException $e) {
          // 1062 duplicate (hostname unik atau token tabrakan)
          if ($e->getCode() === '23000') {
            // kalau karena token, coba ulang; kalau karena hostname, beritahu user
            if (stripos($e->getMessage(), 'token') !== false) {
              $token = gen_token(16);
              continue;
            }
            if (stripos($e->getMessage(), 'hostname') !== false) {
              $flash = ['type' => 'err', 'msg' => 'Hostname sudah ada. Gunakan nama lain.'];
              break;
            }
          }
          throw $e;
        }
      }
    }
  }

  if ($action === 'delete') {
    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    if (!$id) {
      $flash = ['type' => 'err', 'msg' => 'ID tidak valid.'];
    } else {
      // hapus events lalu host (jaga-jaga bila FK CASCADE belum dibuat)
      $pdo->beginTransaction();
      try {
        $pdo->prepare("DELETE FROM events WHERE host_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM hosts WHERE id = ?")->execute([$id]);
        $pdo->commit();
        $flash = ['type' => 'ok', 'msg' => 'Host dan seluruh event-nya telah dihapus.'];
      } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
      }
    }
  }
}

// ==== ambil data untuk tampilan ====
$rows = $pdo->query("SELECT id, hostname, ip, token, last_seen FROM hosts ORDER BY hostname")->fetchAll();

$base = now_http_base();
$webhook_url = $base . '/webhook.php'; // kamu pakai port 80 dan file di root /home/www
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>AuditDash • Hosts</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root{--bg:#0b1220; --card:#0f172a; --line:#1f2a44; --txt:#e6edf3; --muted:#9fb0c3; --chip:#1f2a44; --ok:#173b2b; --ok-b:#22c55e33; --err:#3b171d; --err-b:#ef444433;}
  body{font-family:system-ui,Arial;background:var(--bg);color:var(--txt);margin:20px}
  a{color:#9ecbff;text-decoration:none}
  .topnav{display:flex;gap:12px;margin-bottom:12px;align-items:center}
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px;margin-top:12px}
  label,select,input,button{font-size:14px}
  input,button{padding:8px 10px;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--txt)}
  .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .grow{flex:1}
  .note{color:var(--muted);font-size:12px;margin-top:6px}

  .table-wrap{max-width:100%; overflow:auto}
  table{width:100%; border-collapse:collapse; table-layout:fixed; font-size:14px}
  col.h-host{width:220px} col.h-ip{width:160px} col.h-token{width:420px} col.h-last{width:200px} col.h-act{width:120px}
  th,td{padding:10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}
  th{background:var(--bg);position:sticky;top:0;z-index:1}
  td .mono{font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; word-wrap:break-word; overflow-wrap:anywhere}
  .btn{cursor:pointer}
  .btn-danger{background:#3b1114;border-color:#752127}
  .btn-danger:hover{background:#5a181d}
  .flash{padding:10px 12px;border-radius:10px;margin-top:8px}
  .flash.ok{background:var(--ok); border:1px solid var(--ok-b)}
  .flash.err{background:var(--err); border:1px solid var(--err-b)}
  .muted{color:var(--muted)}
</style>
</head>
<body>

<div class="topnav">
  <div><b>AuditDash</b></div>
  <a href="/index.php">Dashboard</a>
  <a href="/hosts.php">Hosts</a>
  <span style="flex:1"></span>
  <span>Hi, <?=htmlspecialchars($_SESSION['username'] ?? '')?></span>
  <a href="/logout.php">Logout</a>
</div>

<div class="card">
  <form method="post" class="row" onsubmit="return true;">
    <input type="hidden" name="csrf" value="<?=$CSRF?>">
    <input type="hidden" name="action" value="create">
    <input class="grow" type="text" name="hostname" placeholder="Hostname (unik)" required>
    <input class="" type="text" name="ip" placeholder="IP (opsional)">
    <button class="btn" type="submit">Generate Token &amp; Tambah</button>
  </form>
  <?php if ($flash): ?>
    <div class="flash <?=$flash['type']==='ok'?'ok':'err'?>"><?=htmlspecialchars($flash['msg'])?></div>
  <?php endif; ?>
  <?php if ($new_token): ?>
    <div class="flash ok">
      <b>TOKEN:</b> <span class="mono"><?=$new_token?></span>
    </div>
  <?php endif; ?>
  <div class="note">Catatan: copy TOKEN yang ditampilkan, tempel ke skrip VM.</div>
</div>

<div class="card">
  <div class="table-wrap">
    <table>
      <colgroup>
        <col class="h-host"><col class="h-ip"><col class="h-token"><col class="h-last"><col class="h-act">
      </colgroup>
      <thead>
        <tr>
          <th>Hostname</th>
          <th>IP</th>
          <th>Token</th>
          <th>Last Seen</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="5" class="muted">Belum ada host.</td></tr>
        <?php else: foreach ($rows as $h): ?>
          <tr>
            <td><?=htmlspecialchars($h['hostname'])?></td>
            <td><?=htmlspecialchars((string)$h['ip'])?></td>
            <td class="mono"><?=htmlspecialchars($h['token'])?></td>
            <td><?=htmlspecialchars((string)$h['last_seen'])?></td>
            <td>
              <form method="post" style="display:inline" onsubmit="return confirm('Hapus host “<?=htmlspecialchars($h['hostname'])?>” dan semua event-nya?');">
                <input type="hidden" name="csrf" value="<?=$CSRF?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=$h['id']?>">
                <button class="btn btn-danger" type="submit">Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="mono" style="white-space:pre-wrap">
Cara registrasi VM

1) Di halaman ini, tambahkan host untuk dapatkan TOKEN.
2) Di VM terkait, edit skrip /usr/local/bin/audit2http.sh:

   DASH_URL="<?=$webhook_url?>"
   TOKEN="<TOKEN-DARI-HOSTS.PHP>"
   KEY_FILTER="uadwatch"

3) Restart auditd di VM:

   sudo systemctl restart auditd
  </div>
</div>

</body>
</html>
