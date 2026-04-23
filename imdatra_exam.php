<?php
/**
 * İmdatra Koleji — 3. Sınıf Fen Bilimleri: "Dünya ve Ay" Sınavı.
 *
 * Yazdırılabilir sınav kağıdı. 20 çoktan seçmeli soru, yalnızca
 * "Gezegenimizi Tanıyalım / Dünya ve Ay" ünitesine odaklanmıştır.
 * Her soru 5 puan, toplam 100 puandır. Öğretmenler için açılır/
 * kapanır cevap anahtarı vardır (basımda gizlenir).
 */
require_once __DIR__ . '/includes/functions.php';
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dünya ve Ay Sınavı — 3. Sınıf — İmdatra Koleji</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
  :root {
    --ink: #111827;
    --muted: #6b7280;
    --accent: #1e40af;
    --accent-2: #1e3a8a;
    --soft: #eff6ff;
    --border: #cbd5e1;
    --yellow: #fef3c7;
    --yellow-border: #fcd34d;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body {
    font-family:'Nunito','Segoe UI',Tahoma,Arial,sans-serif;
    background:#e2e8f0; color:var(--ink); line-height:1.55;
    padding:20px;
  }
  .sheet {
    max-width:820px; margin:0 auto; background:#fff;
    padding:34px 40px; border-radius:8px;
    box-shadow:0 4px 20px rgba(0,0,0,.08);
  }

  /* Yazdırma kontrolleri */
  .toolbar {
    max-width:820px; margin:0 auto 16px;
    display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;
  }
  .tbtn {
    background:var(--accent); color:#fff; border:0; padding:10px 20px;
    border-radius:8px; font-weight:700; cursor:pointer; font-family:inherit;
    font-size:14px; transition:background .18s;
  }
  .tbtn:hover { background:var(--accent-2); }
  .tbtn.ghost { background:#64748b; }
  .tbtn.ghost:hover { background:#475569; }

  /* Okul başlığı */
  .exam-head {
    border-bottom:3px double var(--accent);
    padding-bottom:14px; margin-bottom:18px;
    display:flex; align-items:center; gap:18px;
  }
  .exam-head .logo {
    width:72px; height:72px; border-radius:50%;
    background:linear-gradient(135deg,var(--accent),var(--accent-2));
    color:#fff; display:flex; align-items:center; justify-content:center;
    font-size:28px; font-weight:900; flex-shrink:0;
    border:3px solid #dbeafe;
    box-shadow:0 4px 10px rgba(30,64,175,.25);
  }
  .exam-head .h-text { flex:1; }
  .exam-head .school {
    font-size:24px; font-weight:900; color:var(--accent-2);
    letter-spacing:.8px;
  }
  .exam-head .meta-line {
    font-size:13px; color:var(--muted); margin-top:4px; font-weight:600;
  }
  .exam-title-row {
    background:var(--soft); border:2px solid var(--accent);
    border-radius:10px; padding:12px 18px; margin-bottom:18px;
    display:flex; justify-content:space-between; align-items:center;
    flex-wrap:wrap; gap:10px;
  }
  .exam-title-row h1 {
    font-size:19px; font-weight:900; color:var(--accent-2);
  }
  .exam-title-row h1 .unit {
    display:inline-block; margin-right:8px; font-size:15px;
    background:var(--accent); color:#fff; padding:3px 10px;
    border-radius:6px; font-weight:800;
  }
  .exam-title-row .ders {
    font-size:13px; font-weight:700; color:var(--accent);
    background:#fff; padding:5px 12px; border-radius:20px;
    border:1px solid var(--accent);
  }

  /* Öğrenci bilgi */
  .info-grid {
    display:grid; grid-template-columns:1fr 1fr; gap:6px 16px;
    margin-bottom:16px; font-size:13px;
  }
  .info-grid .row {
    display:flex; gap:8px; align-items:center;
    border-bottom:1px dotted var(--border); padding:5px 0;
  }
  .info-grid .row strong { min-width:95px; color:var(--accent-2); font-weight:800; }
  .info-grid .row .line { flex:1; }

  /* Talimat kutusu */
  .instructions {
    background:var(--yellow); border:1px solid var(--yellow-border);
    border-radius:10px; padding:10px 14px; margin-bottom:20px;
    font-size:12.5px; color:#78350f;
  }
  .instructions strong { color:#92400e; }

  /* Bölüm başlığı */
  .section-head {
    background:var(--accent); color:#fff;
    padding:8px 14px; border-radius:8px 8px 0 0;
    display:flex; justify-content:space-between; align-items:center;
    font-weight:800; font-size:14px;
  }
  .section-head .pts { font-size:12px; opacity:.9; }
  .section-body {
    border:1px solid var(--accent); border-top:0;
    border-radius:0 0 8px 8px; padding:14px 18px;
  }

  /* Sorular */
  .q {
    margin-bottom:12px; padding-bottom:10px;
    border-bottom:1px dashed #e2e8f0;
    page-break-inside:avoid;
  }
  .q:last-child { border-bottom:0; margin-bottom:0; padding-bottom:0; }
  .q-text {
    font-weight:700; margin-bottom:6px; font-size:14px; line-height:1.55;
  }
  .q-text .num {
    display:inline-block; background:var(--accent); color:#fff;
    width:23px; height:23px; border-radius:50%; text-align:center;
    line-height:23px; font-weight:900; margin-left:6px; font-size:12px;
  }
  .choices {
    display:grid; grid-template-columns:1fr 1fr; gap:4px 14px;
    padding-right:28px; font-size:13.5px;
  }
  .choices .c {
    display:flex; align-items:center; gap:7px;
  }
  .choices .c .box {
    display:inline-flex; align-items:center; justify-content:center;
    width:20px; height:20px; border:2px solid var(--accent);
    border-radius:50%; font-weight:800; color:var(--accent);
    font-size:12px; flex-shrink:0;
  }

  /* Cevap Anahtarı */
  .answer-key {
    margin-top:26px; padding:16px 20px;
    background:#ecfdf5; border:2px dashed #10b981;
    border-radius:10px; font-size:13.5px;
  }
  .answer-key h3 {
    color:#065f46; font-size:15px; margin-bottom:10px;
  }
  .answer-table {
    display:grid; grid-template-columns:repeat(5, 1fr); gap:6px;
  }
  .answer-table .cell {
    background:#fff; border:1px solid #10b981; border-radius:6px;
    padding:6px 8px; text-align:center; font-weight:700;
  }
  .answer-table .cell strong { color:#065f46; }

  /* Dipnot */
  .footer-note {
    margin-top:22px; padding-top:12px;
    border-top:1px solid var(--border);
    display:flex; justify-content:space-between;
    font-size:13px; color:var(--muted);
    flex-wrap:wrap; gap:10px;
  }

  @media print {
    body { background:#fff; padding:0; }
    .sheet { box-shadow:none; max-width:100%; padding:16px 22px; }
    .toolbar { display:none; }
    .answer-key { display:none; }
    @page { size:A4; margin:12mm; }
  }
  @media (max-width:640px) {
    .sheet { padding:20px 18px; }
    .info-grid { grid-template-columns:1fr; }
    .choices { grid-template-columns:1fr; padding-right:20px; }
    .exam-title-row h1 { font-size:17px; }
    .exam-head .school { font-size:18px; }
    .answer-table { grid-template-columns:repeat(4, 1fr); }
  }
</style>
</head>
<body>

<div class="toolbar">
  <button class="tbtn" onclick="window.print()">🖨️ Yazdır</button>
  <button class="tbtn ghost" onclick="document.getElementById('anahtar').style.display = document.getElementById('anahtar').style.display === 'none' ? 'block' : 'none'">🔑 Cevap Anahtarı</button>
</div>

<article class="sheet">

  <!-- Okul Başlığı -->
  <header class="exam-head">
    <div class="logo">İK</div>
    <div class="h-text">
      <div class="school">İMDATRA KOLEJİ</div>
      <div class="meta-line">2025–2026 Eğitim–Öğretim Yılı • 3. Sınıf Fen Bilimleri</div>
    </div>
  </header>

  <div class="exam-title-row">
    <h1><span class="unit">🌍 ÜNİTE</span> Dünya ve Ay — Çoktan Seçmeli Sınav</h1>
    <span class="ders">Süre: 40 dk • 100 puan</span>
  </div>

  <!-- Öğrenci Bilgileri -->
  <div class="info-grid">
    <div class="row"><strong>Adı Soyadı:</strong> <span class="line"></span></div>
    <div class="row"><strong>Numarası:</strong> <span class="line"></span></div>
    <div class="row"><strong>Sınıfı / Şubesi:</strong> <span class="line"></span></div>
    <div class="row"><strong>Tarih:</strong> <span class="line"></span></div>
  </div>

  <!-- Talimatlar -->
  <div class="instructions">
    <strong>🧒 Sevgili Öğrenciler:</strong>
    Sınavda <strong>20 çoktan seçmeli soru</strong> vardır. Her soru <strong>5 puan</strong> değerindedir. Her sorunun yalnızca <strong>bir</strong> doğru cevabı bulunmaktadır. Doğru şıkkı (A / B / C / D) yuvarlak içine alınız. Başarılar! 🌟
  </div>

  <!-- Sorular -->
  <section>
    <div class="section-head">
      <span>🌍 DÜNYA VE AY — ÇOKTAN SEÇMELİ SORULAR</span>
      <span class="pts">20 soru × 5 puan = 100 puan</span>
    </div>
    <div class="section-body">

      <div class="q">
        <div class="q-text"><span class="num">1</span> Dünya'nın şekli aşağıdakilerden hangisine benzer?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Küp</div>
          <div class="c"><span class="box">B</span> Küre</div>
          <div class="c"><span class="box">C</span> Koni</div>
          <div class="c"><span class="box">D</span> Silindir</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">2</span> Dünya'nın tek doğal uydusu hangisidir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Güneş</div>
          <div class="c"><span class="box">B</span> Mars</div>
          <div class="c"><span class="box">C</span> Ay</div>
          <div class="c"><span class="box">D</span> Jüpiter</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">3</span> Dünya'nın yüzeyinin büyük bölümünü ne kaplar?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Kara parçaları</div>
          <div class="c"><span class="box">B</span> Sular</div>
          <div class="c"><span class="box">C</span> Ormanlar</div>
          <div class="c"><span class="box">D</span> Çöller</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">4</span> Aşağıdakilerden hangisi bir yeryüzü şekli <u>değildir</u>?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Dağ</div>
          <div class="c"><span class="box">B</span> Ova</div>
          <div class="c"><span class="box">C</span> Plato</div>
          <div class="c"><span class="box">D</span> Bulut</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">5</span> Ay'ın yüzeyindeki çukurlara ne ad verilir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Göl</div>
          <div class="c"><span class="box">B</span> Krater</div>
          <div class="c"><span class="box">C</span> Vadi</div>
          <div class="c"><span class="box">D</span> Kanyon</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">6</span> Ay ile ilgili aşağıdakilerden hangisi <u>yanlış</u>tır?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Dünya'nın uydusudur.</div>
          <div class="c"><span class="box">B</span> Dünya'dan daha küçüktür.</div>
          <div class="c"><span class="box">C</span> Kendi ışığı vardır.</div>
          <div class="c"><span class="box">D</span> Gökyüzünde görülür.</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">7</span> Aşağıdakilerden hangisi bir <u>kara parçası</u>dır?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Deniz</div>
          <div class="c"><span class="box">B</span> Nehir</div>
          <div class="c"><span class="box">C</span> Ada</div>
          <div class="c"><span class="box">D</span> Göl</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">8</span> Aşağıdakilerden hangisi <u>tatlı su</u> kaynağıdır?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Deniz</div>
          <div class="c"><span class="box">B</span> Okyanus</div>
          <div class="c"><span class="box">C</span> Nehir</div>
          <div class="c"><span class="box">D</span> Tuz gölü</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">9</span> Dünya üzerinde yaşayan canlılar için aşağıdakilerden hangisi <u>gerekli değildir</u>?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Hava</div>
          <div class="c"><span class="box">B</span> Su</div>
          <div class="c"><span class="box">C</span> Uygun sıcaklık</div>
          <div class="c"><span class="box">D</span> Gürültü</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">10</span> Ay'ın büyüklüğü Dünya'ya göre nasıldır?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Dünya'dan büyüktür.</div>
          <div class="c"><span class="box">B</span> Dünya ile aynı büyüklüktedir.</div>
          <div class="c"><span class="box">C</span> Dünya'dan küçüktür.</div>
          <div class="c"><span class="box">D</span> Dünya'nın iki katıdır.</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">11</span> Dünya ile Ay arasındaki <u>ortak</u> özellik hangisidir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> İkisi de kendi ışığını yayar.</div>
          <div class="c"><span class="box">B</span> İkisinin de şekli küreye benzer.</div>
          <div class="c"><span class="box">C</span> İkisinde de canlılar yaşar.</div>
          <div class="c"><span class="box">D</span> İkisi de gezegendir.</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">12</span> Ay'ın yüzeyinde aşağıdakilerden hangisi <u>bulunmaz</u>?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Kraterler</div>
          <div class="c"><span class="box">B</span> Tepeler</div>
          <div class="c"><span class="box">C</span> Kayalıklar</div>
          <div class="c"><span class="box">D</span> Nehirler ve göller</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">13</span> Dünya'nın iç kısmında aşağıdakilerden hangisi bulunur?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Buz katmanları</div>
          <div class="c"><span class="box">B</span> Sıcak magma</div>
          <div class="c"><span class="box">C</span> Taze su</div>
          <div class="c"><span class="box">D</span> Soğuk hava</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">14</span> Ay ile ilgili aşağıdakilerden hangisi <u>doğru</u>dur?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Ay'ın havası vardır, nefes alınabilir.</div>
          <div class="c"><span class="box">B</span> Ay, Güneş'in ışığını yansıtır.</div>
          <div class="c"><span class="box">C</span> Ay, Dünya'dan büyüktür.</div>
          <div class="c"><span class="box">D</span> Ay'ın yüzeyi dümdüzdür.</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">15</span> Dünya, Güneş Sistemi'nde kaçıncı gezegendir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Birinci</div>
          <div class="c"><span class="box">B</span> İkinci</div>
          <div class="c"><span class="box">C</span> Üçüncü</div>
          <div class="c"><span class="box">D</span> Dördüncü</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">16</span> Dünya'nın yüzeyini değiştiren doğa olaylarından hangisi <u>değildir</u>?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Deprem</div>
          <div class="c"><span class="box">B</span> Volkan patlaması</div>
          <div class="c"><span class="box">C</span> Erozyon (aşınma)</div>
          <div class="c"><span class="box">D</span> Gökkuşağı</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">17</span> Aşağıdakilerden hangisi <u>su kaynakları</u>ndan biridir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Plato</div>
          <div class="c"><span class="box">B</span> Okyanus</div>
          <div class="c"><span class="box">C</span> Dağ</div>
          <div class="c"><span class="box">D</span> Ova</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">18</span> Yeryüzünde en yüksek kara parçası hangisidir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Ova</div>
          <div class="c"><span class="box">B</span> Plato</div>
          <div class="c"><span class="box">C</span> Dağ</div>
          <div class="c"><span class="box">D</span> Vadi</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">19</span> Ay, Dünya'ya göre konumu bakımından hangisidir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Dünya'dan çok uzaktaki bir yıldızdır.</div>
          <div class="c"><span class="box">B</span> Dünya'ya en yakın gök cismidir.</div>
          <div class="c"><span class="box">C</span> Dünya'nın içindedir.</div>
          <div class="c"><span class="box">D</span> Güneş'in içindedir.</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">20</span> Aşağıdakilerden hangisi Dünya'yı diğer gök cisimlerinden <u>ayıran</u> özelliktir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Küre şeklinde olması</div>
          <div class="c"><span class="box">B</span> Üzerinde canlıların yaşaması</div>
          <div class="c"><span class="box">C</span> Gökyüzünde görülmesi</div>
          <div class="c"><span class="box">D</span> Uydusunun bulunması</div>
        </div>
      </div>

    </div>
  </section>

  <div class="footer-note">
    <span>Başarılar dileriz. 🍀🌍🌕</span>
    <span><strong>Öğretmen:</strong> ____________________</span>
  </div>

  <!-- Cevap Anahtarı -->
  <div class="answer-key" id="anahtar" style="display:none;">
    <h3>🔑 Cevap Anahtarı (Öğretmen İçin)</h3>
    <div class="answer-table">
      <div class="cell"><strong>1</strong> — B</div>
      <div class="cell"><strong>2</strong> — C</div>
      <div class="cell"><strong>3</strong> — B</div>
      <div class="cell"><strong>4</strong> — D</div>
      <div class="cell"><strong>5</strong> — B</div>
      <div class="cell"><strong>6</strong> — C</div>
      <div class="cell"><strong>7</strong> — C</div>
      <div class="cell"><strong>8</strong> — C</div>
      <div class="cell"><strong>9</strong> — D</div>
      <div class="cell"><strong>10</strong> — C</div>
      <div class="cell"><strong>11</strong> — B</div>
      <div class="cell"><strong>12</strong> — D</div>
      <div class="cell"><strong>13</strong> — B</div>
      <div class="cell"><strong>14</strong> — B</div>
      <div class="cell"><strong>15</strong> — C</div>
      <div class="cell"><strong>16</strong> — D</div>
      <div class="cell"><strong>17</strong> — B</div>
      <div class="cell"><strong>18</strong> — C</div>
      <div class="cell"><strong>19</strong> — B</div>
      <div class="cell"><strong>20</strong> — B</div>
    </div>
  </div>

</article>

</body>
</html>
