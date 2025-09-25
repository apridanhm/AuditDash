# AuditDash

## cara buat akun admin auditdashnya
### import skema db auditdash_schema.sql ke mysql : 
#### mysql -u root -p < auditdash_schema.sql

### jalankan ini : 
#### php -r 'echo password_hash("GantiIniDenganPasswordKuat", PASSWORD_DEFAULT), PHP_EOL;'
nati dia akan mengasilkan pasword_hash contoh : $2y$10$5e0uZt3q2iQF1CzF0v5mze0m3k2r6... (dst)

### kemudian jalankan di mysql :
#### INSERT INTO users (username, password_hash) VALUES ('admin', '$2y$10$5e0uZt3q2iQF1CzF0v5mze0m3k2r6...');
verifikasi : 
#### SELECT id, username, LENGTH(password_hash) AS hash_len FROM users;

## kemudian jangan lupa pasang auditd di host yang ingin di pantau 
ada 3 file penting yang perlu dipasang di host yang akan dipantau
### pertama : 
file /usr/local/bin/audit2http.sh ini berfungsi untuk hit webhook dahbord yang kana mengirimkan log dari auditd ke dashbord, 
dengan mengubahnya dlu sesuai format yang di konfigurasi di script ini.setelah dibuat kasih hak eksekusi "sudo chmod +x /usr/local/bin/audit2http.sh" (ada contohnya di repo)  
### kedua : 
file /etc/audit/plugins.d/http-audit.conf file ini adalah file conf plugin dari auditd. (ada contohnya di repo)
### ketiga :
buat rules dir mana saja yang akan di monitor lihat di sini doc membuat rules nya berkut reff nya : https://sematext.com/glossary/auditd/
### alasan kenapa ini dibuat
sering ada slot gacor di hosting yang mereka terkadang menubah ubah file hostingnya jadi muncul slot gacor sialan yang terkadang suka bikin jengkel
maka dengan adanya ini perubahan file dapat dipantau.
