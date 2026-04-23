<?php
/**
 * İmdatra Koleji — 3. Sınıf Fen Bilimleri Sınavı (Çoktan Seçmeli).
 *
 * Yazdırılabilir sınav kağıdı. 20 çoktan seçmeli soru, toplam 100 puan.
 * Öğrenci bilgi alanı, yazdırma düğmesi ve öğretmenler için açılır/
 * kapanır cevap anahtarı içerir (cevap anahtarı yazdırmada gizlenir).
 */
require_once __DIR__ . '/includes/functions.php';
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fen Bilimleri Sınavı — 3. Sınıf — İmdatra Koleji</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
  :root {
    --ink: #111827;
    --muted: #6b7280;
    --accent: #0369a1;
    --accent-2: #0c4a6e;
    --soft: #f0f9ff;
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

  /* Başlık */
  .exam-head {
    border-bottom:3px double var(--accent);
    padding-bottom:14px; margin-bottom:18px;
    display:flex; align-items:center; gap:18px;
  }
  .exam-head .logo {
    width:66px; height:66px; border-radius:50%;
    background:linear-gradient(135deg,var(--accent),var(--accent-2));
    color:#fff; display:flex; align-items:center; justify-content:center;
    font-size:26px; font-weight:900; flex-shrink:0;
  }
  .exam-head .h-text { flex:1; }
  .exam-head .school {
    font-size:22px; font-weight:900; color:var(--accent-2);
    letter-spacing:.5px;
  }
  .exam-head .meta-line {
    font-size:13px; color:var(--muted); margin-top:4px; font-weight:600;
  }
  .exam-title-row {
    background:var(--soft); border:2px solid var(--accent);
    border-radius:10px; padding:10px 18px; margin-bottom:18px;
    display:flex; justify-content:space-between; align-items:center;
    flex-wrap:wrap; gap:10px;
  }
  .exam-title-row h1 {
    font-size:19px; font-weight:900; color:var(--accent-2);
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
  .info-grid .row strong { min-width:85px; color:var(--accent-2); font-weight:800; }
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
      <div class="meta-line">2025–2026 Eğitim–Öğretim Yılı • 1. Dönem Değerlendirme Sınavı</div>
    </div>
  </header>

  <div class="exam-title-row">
    <h1>3. Sınıf Fen Bilimleri Sınavı</h1>
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
    Sınav <strong>20 çoktan seçmeli</strong> sorudan oluşmaktadır. Her soru <strong>5 puan</strong> değerindedir. Her sorunun yalnızca <strong>bir</strong> doğru cevabı vardır. Doğru şıkkı (A / B / C / D) yuvarlak içine alınız. Başarılar dileriz! 🌟
  </div>

  <!-- Sorular -->
  <section>
    <div class="section-head">
      <span>ÇOKTAN SEÇMELİ SORULAR</span>
      <span class="pts">20 soru × 5 puan = 100 puan</span>
    </div>
    <div class="section-body">

      <div class="q">
        <div class="q-text"><span class="num">1</span> Hangi duyu organımızla tatları ayırt ederiz?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Göz</div>
          <div class="c"><span class="box">B</span> Kulak</div>
          <div class="c"><span class="box">C</span> Dil</div>
          <div class="c"><span class="box">D</span> Burun</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">2</span> Dünya'nın şekli aşağıdakilerden hangisine benzer?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Küre</div>
          <div class="c"><span class="box">B</span> Kare</div>
          <div class="c"><span class="box">C</span> Üçgen</div>
          <div class="c"><span class="box">D</span> Dikdörtgen</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">3</span> Aşağıdakilerden hangisi <u>itme</u> kuvvetine örnektir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Topu ayakla tekmelemek</div>
          <div class="c"><span class="box">B</span> Halatı kendine çekmek</div>
          <div class="c"><span class="box">C</span> Kapı kolunu çekmek</div>
          <div class="c"><span class="box">D</span> İpi aşağıya çekmek</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">4</span> Aşağıdakilerden hangisi <u>doğal</u> ışık kaynağıdır?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Ampul</div>
          <div class="c"><span class="box">B</span> Mum</div>
          <div class="c"><span class="box">C</span> El feneri</div>
          <div class="c"><span class="box">D</span> Güneş</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">5</span> Buzun erimesi hangi hâl değişimine örnektir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Katı → Sıvı</div>
          <div class="c"><span class="box">B</span> Sıvı → Gaz</div>
          <div class="c"><span class="box">C</span> Gaz → Sıvı</div>
          <div class="c"><span class="box">D</span> Katı → Gaz</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">6</span> Dünya'nın tek doğal uydusu hangisidir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Güneş</div>
          <div class="c"><span class="box">B</span> Mars</div>
          <div class="c"><span class="box">C</span> Ay</div>
          <div class="c"><span class="box">D</span> Jüpiter</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">7</span> Aşağıdakilerden hangisi <u>canlı</u>dır?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Taş</div>
          <div class="c"><span class="box">B</span> Ağaç</div>
          <div class="c"><span class="box">C</span> Kalem</div>
          <div class="c"><span class="box">D</span> Sandalye</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">8</span> Aşağıdakilerden hangisi <u>mıknatıs</u> tarafından çekilir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Kağıt</div>
          <div class="c"><span class="box">B</span> Demir çivi</div>
          <div class="c"><span class="box">C</span> Plastik kaşık</div>
          <div class="c"><span class="box">D</span> Tahta kutu</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">9</span> Sesi duyabilmemizi sağlayan duyu organımız hangisidir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Göz</div>
          <div class="c"><span class="box">B</span> Dil</div>
          <div class="c"><span class="box">C</span> Kulak</div>
          <div class="c"><span class="box">D</span> Deri</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">10</span> Bir bitkinin büyüyebilmesi için <u>gerekli olmayan</u> aşağıdakilerden hangisidir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Su</div>
          <div class="c"><span class="box">B</span> Işık</div>
          <div class="c"><span class="box">C</span> Hava</div>
          <div class="c"><span class="box">D</span> Müzik</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">11</span> Aşağıdakilerden hangisi <u>yapay</u> ışık kaynağıdır?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Güneş</div>
          <div class="c"><span class="box">B</span> Yıldızlar</div>
          <div class="c"><span class="box">C</span> Ateşböceği</div>
          <div class="c"><span class="box">D</span> Ampul</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">12</span> Aşağıdakilerden hangisi <u>sıvı</u> maddedir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Buz</div>
          <div class="c"><span class="box">B</span> Süt</div>
          <div class="c"><span class="box">C</span> Taş</div>
          <div class="c"><span class="box">D</span> Hava</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">13</span> Çiçekleri koklayarak kokularını ayırt etmemizi sağlayan duyu organı hangisidir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Kulak</div>
          <div class="c"><span class="box">B</span> Burun</div>
          <div class="c"><span class="box">C</span> Dil</div>
          <div class="c"><span class="box">D</span> Göz</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">14</span> Aşağıdakilerden hangisi bir elektrikli alet <u>değildir</u>?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Buzdolabı</div>
          <div class="c"><span class="box">B</span> Televizyon</div>
          <div class="c"><span class="box">C</span> Mum</div>
          <div class="c"><span class="box">D</span> Ütü</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">15</span> Suyun kaynayıp buharlaşması hangi hâl değişimidir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Katı → Sıvı</div>
          <div class="c"><span class="box">B</span> Sıvı → Gaz</div>
          <div class="c"><span class="box">C</span> Gaz → Sıvı</div>
          <div class="c"><span class="box">D</span> Sıvı → Katı</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">16</span> Aşağıdakilerden hangisi <u>çekme</u> kuvvetine örnektir?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Topu tekmelemek</div>
          <div class="c"><span class="box">B</span> Çekmeceyi açmak</div>
          <div class="c"><span class="box">C</span> Kapıyı itmek</div>
          <div class="c"><span class="box">D</span> Bisikleti ileri sürmek</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">17</span> Aşağıdakilerden hangisi canlıların ortak özelliklerinden <u>biri değildir</u>?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Beslenme</div>
          <div class="c"><span class="box">B</span> Solunum</div>
          <div class="c"><span class="box">C</span> Üreme</div>
          <div class="c"><span class="box">D</span> Paslanma</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">18</span> Elektrikle ilgili aşağıdakilerden hangisi <u>doğru</u>dur?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Fişi ıslak elle prize takarız.</div>
          <div class="c"><span class="box">B</span> Kabloların içini açıp bakarız.</div>
          <div class="c"><span class="box">C</span> Bozuk kabloları kullanmayız.</div>
          <div class="c"><span class="box">D</span> Prize çivi sokabiliriz.</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">19</span> Kızgın sobaya elini yaklaştıran Ayşe sıcaklığı hangi duyu organıyla hisseder?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Gözüyle</div>
          <div class="c"><span class="box">B</span> Burnuyla</div>
          <div class="c"><span class="box">C</span> Derisiyle</div>
          <div class="c"><span class="box">D</span> Diliyle</div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">20</span> Işık ile ilgili aşağıdakilerden hangisi <u>doğru</u>dur?</div>
        <div class="choices">
          <div class="c"><span class="box">A</span> Işık eğri yayılır.</div>
          <div class="c"><span class="box">B</span> Işık doğrusal (düz) yayılır.</div>
          <div class="c"><span class="box">C</span> Işık sadece geceleri yayılır.</div>
          <div class="c"><span class="box">D</span> Işık duvardan geçer.</div>
        </div>
      </div>

    </div>
  </section>

  <div class="footer-note">
    <span>Başarılar dileriz. 🍀</span>
    <span><strong>Öğretmen:</strong> ____________________</span>
  </div>

  <!-- Cevap Anahtarı -->
  <div class="answer-key" id="anahtar" style="display:none;">
    <h3>🔑 Cevap Anahtarı (Öğretmen İçin)</h3>
    <div class="answer-table">
      <div class="cell"><strong>1</strong> — C</div>
      <div class="cell"><strong>2</strong> — A</div>
      <div class="cell"><strong>3</strong> — A</div>
      <div class="cell"><strong>4</strong> — D</div>
      <div class="cell"><strong>5</strong> — A</div>
      <div class="cell"><strong>6</strong> — C</div>
      <div class="cell"><strong>7</strong> — B</div>
      <div class="cell"><strong>8</strong> — B</div>
      <div class="cell"><strong>9</strong> — C</div>
      <div class="cell"><strong>10</strong> — D</div>
      <div class="cell"><strong>11</strong> — D</div>
      <div class="cell"><strong>12</strong> — B</div>
      <div class="cell"><strong>13</strong> — B</div>
      <div class="cell"><strong>14</strong> — C</div>
      <div class="cell"><strong>15</strong> — B</div>
      <div class="cell"><strong>16</strong> — B</div>
      <div class="cell"><strong>17</strong> — D</div>
      <div class="cell"><strong>18</strong> — C</div>
      <div class="cell"><strong>19</strong> — C</div>
      <div class="cell"><strong>20</strong> — B</div>
    </div>
  </div>

</article>

</body>
</html>
