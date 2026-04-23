<?php
/**
 * İmdatra Koleji — 3. Sınıf Fen Bilimleri Sınavı.
 *
 * Yazdırılabilir sınav kağıdı. Çoktan seçmeli, doğru/yanlış, boşluk
 * doldurma, eşleştirme ve açık uçlu sorular içerir. Cevap anahtarı
 * sayfa altında gösterilip gizlenebilir (basım sırasında gizlidir).
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
    background:#e2e8f0; color:var(--ink); line-height:1.6;
    padding:20px;
  }
  .sheet {
    max-width:820px; margin:0 auto; background:#fff;
    padding:36px 40px; border-radius:8px;
    box-shadow:0 4px 20px rgba(0,0,0,.08);
    position:relative;
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
    padding-bottom:16px; margin-bottom:20px;
    display:flex; align-items:center; gap:18px;
  }
  .exam-head .logo {
    width:70px; height:70px; border-radius:50%;
    background:linear-gradient(135deg,var(--accent),var(--accent-2));
    color:#fff; display:flex; align-items:center; justify-content:center;
    font-size:28px; font-weight:900; flex-shrink:0;
  }
  .exam-head .h-text { flex:1; }
  .exam-head .school {
    font-size:22px; font-weight:900; color:var(--accent-2);
    letter-spacing:.5px;
  }
  .exam-head .meta-line {
    font-size:14px; color:var(--muted); margin-top:4px; font-weight:600;
  }
  .exam-title-row {
    background:var(--soft); border:2px solid var(--accent);
    border-radius:10px; padding:12px 18px; margin-bottom:20px;
    display:flex; justify-content:space-between; align-items:center;
    flex-wrap:wrap; gap:10px;
  }
  .exam-title-row h1 {
    font-size:20px; font-weight:900; color:var(--accent-2);
  }
  .exam-title-row .ders {
    font-size:14px; font-weight:700; color:var(--accent);
    background:#fff; padding:6px 14px; border-radius:20px;
    border:1px solid var(--accent);
  }

  /* Öğrenci bilgi tablosu */
  .info-grid {
    display:grid; grid-template-columns:1fr 1fr; gap:10px 16px;
    margin-bottom:22px; font-size:14px;
  }
  .info-grid .row {
    display:flex; gap:8px; align-items:center;
    border-bottom:1px dotted var(--border); padding:6px 0;
  }
  .info-grid .row strong { min-width:85px; color:var(--accent-2); font-weight:800; }
  .info-grid .row .line { flex:1; border-bottom:1px solid transparent; }

  /* Talimat kutusu */
  .instructions {
    background:var(--yellow); border:1px solid var(--yellow-border);
    border-radius:10px; padding:12px 16px; margin-bottom:24px;
    font-size:13px; color:#78350f;
  }
  .instructions strong { color:#92400e; display:block; margin-bottom:4px; }
  .instructions ul { padding-right:20px; padding-left:20px; }
  .instructions li { margin-bottom:3px; }

  /* Bölüm başlıkları */
  .section {
    margin-bottom:26px;
    page-break-inside:avoid;
  }
  .section-head {
    background:var(--accent); color:#fff;
    padding:8px 14px; border-radius:8px 8px 0 0;
    display:flex; justify-content:space-between; align-items:center;
    font-weight:800; font-size:15px;
  }
  .section-head .pts { font-size:12px; opacity:.9; }
  .section-body {
    border:1px solid var(--accent); border-top:0;
    border-radius:0 0 8px 8px; padding:16px 18px;
  }

  /* Sorular */
  .q {
    margin-bottom:14px; padding-bottom:12px;
    border-bottom:1px dashed #e2e8f0;
  }
  .q:last-child { border-bottom:0; margin-bottom:0; padding-bottom:0; }
  .q-text {
    font-weight:700; margin-bottom:8px; font-size:15px; line-height:1.6;
  }
  .q-text .num {
    display:inline-block; background:var(--accent); color:#fff;
    width:24px; height:24px; border-radius:50%; text-align:center;
    line-height:24px; font-weight:900; margin-left:6px; font-size:13px;
  }
  .choices {
    display:grid; grid-template-columns:1fr 1fr; gap:6px 16px;
    padding-right:30px; font-size:14px;
  }
  .choices .c {
    display:flex; align-items:center; gap:8px;
  }
  .choices .c .box {
    display:inline-flex; align-items:center; justify-content:center;
    width:22px; height:22px; border:2px solid var(--accent);
    border-radius:50%; font-weight:800; color:var(--accent);
    font-size:13px; flex-shrink:0;
  }

  .tf { display:flex; gap:20px; padding-right:30px; margin-top:6px; }
  .tf .opt {
    display:inline-flex; align-items:center; gap:6px; font-weight:700;
  }
  .tf .opt .circle {
    display:inline-block; width:18px; height:18px; border:2px solid var(--ink);
    border-radius:50%;
  }

  .blank {
    display:inline-block; min-width:120px; border-bottom:2px solid var(--ink);
    margin:0 4px; height:20px;
  }

  .match-grid {
    display:grid; grid-template-columns:1fr 60px 1fr; gap:8px 16px;
    align-items:center; padding-right:10px;
  }
  .match-grid .item {
    border:1px solid var(--border); padding:8px 12px; border-radius:6px;
    font-weight:600; background:#f8fafc;
  }
  .match-grid .arrow { text-align:center; color:var(--muted); }

  .write-lines .line {
    border-bottom:1px solid var(--ink); height:26px; margin-top:6px;
  }

  /* Cevap Anahtarı */
  .answer-key {
    margin-top:30px; padding:18px 22px;
    background:#ecfdf5; border:2px dashed #10b981;
    border-radius:10px; font-size:14px;
  }
  .answer-key h3 {
    color:#065f46; font-size:16px; margin-bottom:10px;
    display:flex; align-items:center; gap:8px;
  }
  .answer-key ol { padding-right:22px; }
  .answer-key li { margin-bottom:4px; }

  /* Dipnot */
  .footer-note {
    margin-top:26px; padding-top:14px;
    border-top:1px solid var(--border);
    display:flex; justify-content:space-between;
    font-size:13px; color:var(--muted);
  }

  @media print {
    body { background:#fff; padding:0; }
    .sheet { box-shadow:none; max-width:100%; padding:20px 24px; }
    .toolbar { display:none; }
    .answer-key { display:none; }
    @page { size:A4; margin:14mm; }
  }
  @media (max-width:640px) {
    .sheet { padding:20px 18px; }
    .info-grid { grid-template-columns:1fr; }
    .choices { grid-template-columns:1fr; padding-right:20px; }
    .exam-title-row h1 { font-size:17px; }
    .exam-head .school { font-size:18px; }
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
    <span class="ders">Süre: 40 dk</span>
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
    <strong>🧒 Sevgili Öğrenciler,</strong>
    <ul>
      <li>Sınav <strong>5 bölümden</strong> oluşmaktadır ve toplam <strong>100 puan</strong>dır.</li>
      <li>Soruları dikkatlice okuyunuz.</li>
      <li>Kalem silgi ve mavi tükenmez kalem kullanınız.</li>
      <li>Başarılar dileriz. 🌟</li>
    </ul>
  </div>

  <!-- A. Çoktan Seçmeli -->
  <section class="section">
    <div class="section-head">
      <span>A) ÇOKTAN SEÇMELİ SORULAR</span>
      <span class="pts">Her soru 5 puan — (5 × 5 = 25 puan)</span>
    </div>
    <div class="section-body">

      <div class="q">
        <div class="q-text"><span class="num">1</span> Aşağıdaki duyu organlarından hangisi ile tatları ayırt ederiz?</div>
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
          <div class="c"><span class="box">D</span> Çantayı omzuna asmak</div>
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

    </div>
  </section>

  <!-- B. Doğru/Yanlış -->
  <section class="section">
    <div class="section-head">
      <span>B) DOĞRU / YANLIŞ SORULARI</span>
      <span class="pts">Her soru 4 puan — (5 × 4 = 20 puan)</span>
    </div>
    <div class="section-body">

      <div class="q">
        <div class="q-text"><span class="num">6</span> Dünya, Güneş'in etrafında döner.</div>
        <div class="tf">
          <span class="opt"><span class="circle"></span> Doğru (D)</span>
          <span class="opt"><span class="circle"></span> Yanlış (Y)</span>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">7</span> Ses; katı, sıvı ve gaz maddelerde yayılır.</div>
        <div class="tf">
          <span class="opt"><span class="circle"></span> Doğru (D)</span>
          <span class="opt"><span class="circle"></span> Yanlış (Y)</span>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">8</span> Mıknatıs bütün metalleri çeker.</div>
        <div class="tf">
          <span class="opt"><span class="circle"></span> Doğru (D)</span>
          <span class="opt"><span class="circle"></span> Yanlış (Y)</span>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">9</span> Bitkiler; büyüme, solunum ve üreme yaparlar.</div>
        <div class="tf">
          <span class="opt"><span class="circle"></span> Doğru (D)</span>
          <span class="opt"><span class="circle"></span> Yanlış (Y)</span>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">10</span> Elektrik fişini ıslak elle prizden çıkarmak güvenlidir.</div>
        <div class="tf">
          <span class="opt"><span class="circle"></span> Doğru (D)</span>
          <span class="opt"><span class="circle"></span> Yanlış (Y)</span>
        </div>
      </div>

    </div>
  </section>

  <!-- C. Boşluk Doldurma -->
  <section class="section">
    <div class="section-head">
      <span>C) BOŞLUK DOLDURMA</span>
      <span class="pts">Her soru 5 puan — (5 × 5 = 25 puan)</span>
    </div>
    <div class="section-body">

      <div class="q">
        <div class="q-text"><span class="num">11</span> Kulaklarımız <span class="blank"></span> duyu organımızdır.</div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">12</span> Dünya'nın tek doğal uydusu <span class="blank"></span>'dır.</div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">13</span> Kuvvet; varlıkları <span class="blank"></span>, durdurabilir veya <span class="blank"></span> değiştirebilir.</div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">14</span> Işık <span class="blank"></span> çizgiler halinde yayılır.</div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">15</span> Bir bitkinin büyüyebilmesi için <span class="blank"></span>, su ve hava gereklidir.</div>
      </div>

    </div>
  </section>

  <!-- D. Eşleştirme -->
  <section class="section">
    <div class="section-head">
      <span>D) EŞLEŞTİRME</span>
      <span class="pts">Her eşleştirme 4 puan — (5 × 4 = 20 puan)</span>
    </div>
    <div class="section-body">
      <div class="q-text" style="margin-bottom:10px;"><span class="num">16</span> Duyu organlarını ilgili duyularla eşleştiriniz.</div>
      <div class="match-grid">
        <div class="item">👁️ Göz</div><div class="arrow">↔</div><div class="item">Koklama</div>
        <div class="item">👂 Kulak</div><div class="arrow">↔</div><div class="item">Tatma</div>
        <div class="item">👃 Burun</div><div class="arrow">↔</div><div class="item">Görme</div>
        <div class="item">👅 Dil</div><div class="arrow">↔</div><div class="item">Dokunma</div>
        <div class="item">✋ Deri</div><div class="arrow">↔</div><div class="item">İşitme</div>
      </div>
    </div>
  </section>

  <!-- E. Açık Uçlu -->
  <section class="section">
    <div class="section-head">
      <span>E) AÇIK UÇLU SORULAR</span>
      <span class="pts">Her soru 5 puan — (2 × 5 = 10 puan)</span>
    </div>
    <div class="section-body">

      <div class="q">
        <div class="q-text"><span class="num">17</span> Evinizde kullandığınız <strong>3 elektrikli alet</strong> yazınız.</div>
        <div class="write-lines">
          <div class="line"></div>
          <div class="line"></div>
        </div>
      </div>

      <div class="q">
        <div class="q-text"><span class="num">18</span> Canlıların ortak özelliklerinden <strong>ikisini</strong> yazınız.</div>
        <div class="write-lines">
          <div class="line"></div>
          <div class="line"></div>
        </div>
      </div>

    </div>
  </section>

  <div class="footer-note">
    <span>Başarılar dileriz. 🍀</span>
    <span><strong>Öğretmen:</strong> ____________________</span>
  </div>

  <!-- Cevap Anahtarı (toplu yazdırmada gizli) -->
  <div class="answer-key" id="anahtar" style="display:none;">
    <h3>🔑 Cevap Anahtarı (Öğretmen İçin)</h3>
    <ol>
      <li><strong>A) Çoktan Seçmeli:</strong> 1-C, 2-A, 3-A, 4-D, 5-A</li>
      <li><strong>B) Doğru/Yanlış:</strong> 6-D, 7-D, 8-Y, 9-D, 10-Y</li>
      <li><strong>C) Boşluk Doldurma:</strong>
        <ul style="padding-right:20px; margin-top:4px;">
          <li>11. işitme</li>
          <li>12. Ay</li>
          <li>13. hareket ettirebilir / şeklini</li>
          <li>14. doğrusal (düz)</li>
          <li>15. ışık (güneş ışığı)</li>
        </ul>
      </li>
      <li><strong>D) Eşleştirme:</strong> Göz→Görme, Kulak→İşitme, Burun→Koklama, Dil→Tatma, Deri→Dokunma</li>
      <li><strong>E) Açık Uçlu:</strong>
        <ul style="padding-right:20px; margin-top:4px;">
          <li>17. Örnek: buzdolabı, televizyon, çamaşır makinesi, ütü, bulaşık makinesi, saç kurutma makinesi…</li>
          <li>18. Beslenme, solunum, büyüme, üreme, hareket etme, boşaltım — ikisi yeterli.</li>
        </ul>
      </li>
    </ol>
  </div>

</article>

</body>
</html>
