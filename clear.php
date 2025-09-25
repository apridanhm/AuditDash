<?php
// clear.php — hapus semua event untuk host tertentu
require __DIR__ . '/auth.php';
require_login();

$host_id = filter_input(INPUT_GET, 'host', FILTER_VALIDATE_INT);
$host_id = ($host_id !== false && $host_id !== null) ? $host_id : 0;

// fallback ke host terakhir dari session jika tidak ada di query
if ($host_id <= 0) {
  $host_id = (int)($_SESSION['last_host_id'] ?? 0);
}

if ($host_id <= 0) {
  // blokir jika masih "All" / tidak ada host
  http_response_code(400);
  ?>
  <!doctype html>
  <html>
  <head>
    <meta charset="utf-8">
    <title>Clear diblokir</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
      :root{ --bg:#0b1220; --card:#0f172a; --line:#1f2a44; --txt:#e6edf3; --muted:#9fb0c3; }
      body{font-family:system-ui,Arial;background:var(--bg);color:var(--txt);margin:20px}
      .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;max-width:800px}
      a{color:#9ecbff;text-decoration:none}
    </style>
  </head>
  <body>
    <div class="card">
      <h2>Clear diblokir</h2>
      <p>Status filter host saat ini <b>All</b>. Silakan pilih host dulu di Dashboard.</p>
      <p><a href="/index.php">Kembali ke Dashboard</a></p>
    </div>
  </body>
  </html>
  <?php
  exit;
}

// validasi host
$st = $pdo->prepare("SELECT id, hostname FROM hosts WHERE id = ?");
$st->execute([$host_id]);
$host = $st->fetch(PDO::FETCH_ASSOC);

if (!$host) {
  http_response_code(404);
  ?>
  <!doctype html>
  <html><head><meta charset="utf-8"><title>Host tidak ditemukan</title></head>
  <body>
    <p>Host tidak ditemukan.</p>
    <p><a href="/index.php">Kembali ke Dashboard</a></p>
  </body></html>
  <?php
  exit;
}

// eksekusi delete
$pdo->beginTransaction();
$del = $pdo->prepare("DELETE FROM events WHERE host_id = ?");
$del->execute([$host_id]);
$deleted = $del->rowCount();
$pdo->commit();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Clear selesai</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root{ --bg:#0b1220; --card:#0f172a; --line:#1f2a44; --txt:#e6edf3; --muted:#9fb0c3; }
    body{font-family:system-ui,Arial;background:var(--bg);color:var(--txt);margin:20px}
    .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;max-width:800px}
    a{color:#9ecbff;text-decoration:none}
  </style>
</head>
<body>
  <div class="card">
    <h2>Clear selesai</h2>
    <p>Event untuk host <b><?=htmlspecialchars($host['hostname'])?></b> telah dihapus.</p>
    <p class="small" style="color:#9fb0c3">Rows deleted: <?=$deleted?></p>
    <p><a href="/index.php?host=<?=$host_id?>">Kembali ke Dashboard</a></p>
  </div>
</body>
</html>
