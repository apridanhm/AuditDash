<?php
// index.php — Dashboard
require __DIR__ . '/auth.php';
require_login(); // <- auth.php akan include db.php & start session

/** util: cek apakah kolom ada (untuk file_ext opsional) */
function has_column(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = $table . '.' . $col;
  if (array_key_exists($key, $cache)) return $cache[$key];
  $stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"
  );
  $stmt->execute([$table, $col]);
  return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

/* --- dropdown Host --- */
$hosts = $pdo->query("SELECT id, hostname, ip FROM hosts ORDER BY hostname")->fetchAll(PDO::FETCH_ASSOC);

/* --- ambil filter --- */
$host_id = filter_input(INPUT_GET, 'host', FILTER_VALIDATE_INT);
$host_id = ($host_id !== false && $host_id !== null) ? $host_id : 0;

$limit = (int)($_GET['limit'] ?? 200);
$limit = max(10, min(2000, $limit));

$since_map = [
  '15m' => '15 MINUTE',
  '1h'  => '1 HOUR',
  '6h'  => '6 HOUR',
  '24h' => '24 HOUR',
  '7d'  => '7 DAY',
];
$since_key = $_GET['since'] ?? '1h';
$since_sql = $since_map[$since_key] ?? $since_map['1h'];

/* simpan host terakhir di sesi, dipakai clear.php jika user buka langsung */
$_SESSION['last_host_id'] = $host_id;

/* apakah ada kolom file_ext? (opsional) */
$has_ext = has_column($pdo, 'events', 'file_ext');
$ext = '';
$ext_options = [];
if ($has_ext) {
  $ext = substr(trim((string)($_GET['ext'] ?? '')), 0, 32);

  // isi pilihan ext sesuai jendela waktu + (opsional) host terpilih
  $paramsExt = [];
  $sqlExt = "SELECT DISTINCT file_ext
             FROM events
             WHERE file_ext IS NOT NULL AND file_ext <> ''
               AND time_event >= DATE_SUB(NOW(), INTERVAL $since_sql)";
  if ($host_id > 0) { $sqlExt .= " AND host_id = ?"; $paramsExt[] = $host_id; }
  $sqlExt .= " ORDER BY file_ext";
  $stx = $pdo->prepare($sqlExt);
  $stx->execute($paramsExt);
  $ext_options = $stx->fetchAll(PDO::FETCH_COLUMN);
}

/* --- ambil baris --- */
$params = [];
$sql = "SELECT e.id, e.time_event, e.serial, e.`key`,
               e.user_auid, e.user_uid, e.comm, e.exe,
               e.syscall, e.action, e.file_path, e.cwd,
               h.hostname
        FROM events e
        JOIN hosts h ON e.host_id = h.id
        WHERE e.time_event >= DATE_SUB(NOW(), INTERVAL $since_sql)";
if ($host_id > 0) { $sql .= " AND e.host_id = ?"; $params[] = $host_id; }
if ($has_ext && $ext !== '') { $sql .= " AND e.file_ext = ?"; $params[] = $ext; }
$sql .= " ORDER BY e.time_event DESC, e.id DESC LIMIT $limit";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>AuditDash</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root{
    --bg:#0b1220; --card:#0f172a; --line:#1f2a44; --txt:#e6edf3; --muted:#9fb0c3; --chip:#1f2a44; --chip-txt:#a3bffa;
    --danger:#7f1d1d; --danger-txt:#fecaca;
  }
  body{font-family:system-ui,Arial;background:var(--bg);color:var(--txt);margin:20px}
  a{color:#9ecbff;text-decoration:none}
  .topnav{display:flex;gap:12px;margin-bottom:12px;align-items:center}
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px;margin-top:12px}
  label,select,input,button{font-size:14px}
  input,button,select{padding:6px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--txt)}
  .controls{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px}

  .table-wrap{max-width:100%; overflow:auto}
  table{width:100%; border-collapse:collapse; font-size:13px; table-layout:fixed}
  col.time{width:180px}
  col.host{width:160px}
  col.user{width:150px}
  col.proc{width:180px}
  col.action{width:90px}
  col.file{width:auto}
  th,td{padding:8px; border-bottom:1px solid var(--line); vertical-align:top; text-align:left}
  th{position:sticky; top:0; background:var(--bg); z-index:1}
  td,td *{word-wrap:break-word; overflow-wrap:anywhere}
  .badge{display:inline-block; padding:2px 6px; border-radius:8px; background:var(--chip); color:var(--chip-txt); text-align:center; min-width:28px}
  .small{color:var(--muted); font-size:12px}
  .danger{background:var(--danger); color:var(--danger-txt)}
  .muted{opacity:.6; pointer-events:none}
</style>
</head>
<body>

<div class="topnav">
  <div><b>AuditDash</b></div>
  <a href="/index.php">Dashboard</a>
  <a href="/hosts.php">Hosts</a>
  <span style="flex:1"></span>

  <!-- Tombol Clear (per-host) -->
  <a href="#" id="clearLink" title="Hapus semua event untuk host terpilih"
     class="<?= $host_id ? '' : 'muted' ?>">Clear</a>

  <span>Hi, <?=htmlspecialchars($_SESSION['username'] ?? '')?></span>
  <a href="/logout.php">Logout</a>
</div>

<div class="card">
  <form id="filterForm" method="get" class="controls">
    <label>Host</label>
    <select name="host" id="hostSelect">
      <option value="0" <?= $host_id===0?'selected':'' ?>>All</option>
      <?php foreach ($hosts as $h): $hid=(int)$h['id']; ?>
        <option value="<?=$hid?>" <?= $host_id===$hid?'selected':'' ?>>
          <?=htmlspecialchars($h['hostname'])?><?= $h['ip'] ? ' ('.htmlspecialchars((string)$h['ip']).')':'' ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Since</label>
    <select name="since" id="sinceSelect">
      <option value="15m" <?= $since_key==='15m'?'selected':'' ?>>15 minute</option>
      <option value="1h"  <?= $since_key==='1h'?'selected':''  ?>>1 hour</option>
      <option value="6h"  <?= $since_key==='6h'?'selected':''  ?>>6 hour</option>
      <option value="24h" <?= $since_key==='24h'?'selected':'' ?>>24 hour</option>
      <option value="7d"  <?= $since_key==='7d'?'selected':''  ?>>7 day</option>
    </select>

    <label>Limit</label>
    <input type="number" name="limit" id="limitInput" value="<?=$limit?>" min="10" max="2000">

    <?php if ($has_ext): ?>
      <label>Ext</label>
      <select name="ext" id="extInput">
        <option value="" <?= $ext===''?'selected':'' ?>>All</option>
        <?php foreach ($ext_options as $opt): ?>
          <option value="<?=htmlspecialchars($opt)?>" <?= $ext===$opt?'selected':'' ?>><?=htmlspecialchars($opt)?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <button type="submit" id="refreshBtn">Refresh</button>
  </form>

  <div class="table-wrap">
    <table>
      <colgroup>
        <col class="time"><col class="host"><col class="user"><col class="proc"><col class="action"><col class="file">
      </colgroup>
      <thead>
        <tr>
          <th>Time</th>
          <th>Host</th>
          <th>User</th>
          <th>Proc</th>
          <th>Action</th>
          <th>File</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="small">No events.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td>
            <?=htmlspecialchars($r['time_event'])?><br>
            <span class="small">serial: <?=htmlspecialchars((string)$r['serial'])?></span>
          </td>
          <td><?=htmlspecialchars($r['hostname'])?></td>
          <td>
            <div>auid: <?=htmlspecialchars((string)$r['user_auid'])?></div>
            <div class="small">uid: <?=htmlspecialchars((string)$r['user_uid'])?></div>
          </td>
          <td>
            <div><?=htmlspecialchars((string)$r['comm'])?></div>
            <div class="small"><?=htmlspecialchars((string)$r['exe'])?></div>
          </td>
          <td style="text-align:center">
            <span class="badge"><?=htmlspecialchars((string)($r['action'] ?: $r['syscall'] ?: '-'))?></span>
          </td>
          <td>
            <div><?=htmlspecialchars((string)$r['file_path'])?></div>
            <div class="small">cwd: <?=htmlspecialchars((string)$r['cwd'])?></div>
            <div class="small">key: <?=htmlspecialchars((string)$r['key'])?></div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  const f = document.getElementById('filterForm');

  // auto-submit saat filter berubah
  ['hostSelect','sinceSelect','extInput'].forEach(id=>{
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', ()=>{ f.requestSubmit ? f.requestSubmit() : f.submit(); });
  });

  // Enter pada Limit
  const lim = document.getElementById('limitInput');
  if (lim) lim.addEventListener('keydown', e=>{
    if (e.key==='Enter'){ e.preventDefault(); f.requestSubmit ? f.requestSubmit() : f.submit(); }
  });

  // Clear per-host — blokir jika All
  const clearBtn = document.getElementById('clearLink');
  const hostSel  = document.getElementById('hostSelect');
  function refreshClearUI(){
    const hid = parseInt(hostSel.value || '0', 10);
    if (hid) clearBtn.classList.remove('muted'); else clearBtn.classList.add('muted');
  }
  if (hostSel) hostSel.addEventListener('change', refreshClearUI);
  refreshClearUI();

  if (clearBtn && hostSel) {
    clearBtn.addEventListener('click', (e)=>{
      e.preventDefault();
      const hid = parseInt(hostSel.value || '0', 10);
      if (!hid) { alert('Silakan pilih host dulu (bukan "All").'); return; }
      if (confirm('Hapus semua event untuk host ini?')) {
        window.location.href = '/clear.php?host=' + hid;
      }
    });
  }
})();
</script>

</body>
</html>
