// WEATHER API (Open-Meteo - free, no key needed)
const weatherCodes = {
  0:'☀️', 1:'🌤', 2:'⛅', 3:'☁️', 45:'🌫', 48:'🌫',
  51:'🌦', 53:'🌦', 55:'🌧', 61:'🌧', 63:'🌧', 65:'🌧',
  71:'🌨', 73:'🌨', 75:'❄️', 80:'🌦', 81:'🌧', 82:'⛈', 95:'⛈', 96:'⛈', 99:'⛈'
};
const weatherDesc = {
  0:'صافي', 1:'صافي غالباً', 2:'غائم جزئياً', 3:'غائم', 45:'ضبابي', 48:'ضبابي',
  51:'رذاذ خفيف', 53:'رذاذ', 55:'رذاذ كثيف', 61:'مطر خفيف', 63:'مطر', 65:'مطر غزير',
  71:'ثلوج خفيفة', 73:'ثلوج', 75:'ثلوج كثيفة', 80:'أمطار متفرقة', 81:'أمطار', 82:'أمطار غزيرة',
  95:'عواصف رعدية', 96:'عواصف مع برد', 99:'عواصف شديدة'
};
const dayNames = ['الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
const dayShort = ['الأح','الإث','الثل','الأر','الخم','الجم','السب'];

const cities = {
  Jerusalem: { lat:31.7683, lon:35.2137, name:'القدس' },
  Gaza: { lat:31.5017, lon:34.4668, name:'غزة' },
  Ramallah: { lat:31.9038, lon:35.2034, name:'رام الله' },
  Nablus: { lat:32.2211, lon:35.2544, name:'نابلس' },
  Hebron: { lat:31.5326, lon:35.0998, name:'الخليل' },
  Jenin: { lat:32.4607, lon:35.2953, name:'جنين' }
};

function fetchWeather(cityKey) {
  const c = cities[cityKey];
  if (!c) return;
  const url = `https://api.open-meteo.com/v1/forecast?latitude=${c.lat}&longitude=${c.lon}&current=temperature_2m,weather_code&daily=weather_code,temperature_2m_max&timezone=Asia/Jerusalem&forecast_days=5`;
  fetch(url).then(r => r.json()).then(data => {
    const cur = data.current;
    const temp = Math.round(cur.temperature_2m);
    const code = cur.weather_code;
    const icon = weatherCodes[code] || '🌤';
    const setTxt = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    setTxt('wTemp', temp + '°');
    setTxt('wCity', c.name + '، فلسطين');
    setTxt('wDesc', weatherDesc[code] || 'غير معروف');
    setTxt('wIcon', icon);
    // Header button: keep emoji + temp span
    const topBtn = document.getElementById('topWeather');
    if (topBtn) topBtn.innerHTML = `${icon} <span>${temp}°</span>`;

    // Forecast
    const daily = data.daily;
    let forecastHTML = '';
    for (let i = 1; i <= 4; i++) {
      const d = new Date(daily.time[i]);
      const dCode = daily.weather_code[i];
      const dTemp = Math.round(daily.temperature_2m_max[i]);
      forecastHTML += `<div class="weather-day"><div class="day">${dayShort[d.getDay()]}</div><div>${weatherCodes[dCode]||'🌤'}</div><div class="temp">${dTemp}°</div></div>`;
    }
    const fc = document.getElementById('wForecast');
    if (fc) fc.innerHTML = forecastHTML;
  }).catch(() => {});
}

// City buttons (delegated — modal content exists in DOM from load)
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.weather-city-btn');
  if (!btn) return;
  document.querySelectorAll('.weather-city-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  fetchWeather(btn.dataset.city);
});

// Load default
fetchWeather('Jerusalem');

// Weather modal
function openWeatherModal() {
  document.getElementById('weatherModal').classList.add('show');
}
function closeWeatherModal() {
  document.getElementById('weatherModal').classList.remove('show');
}
document.getElementById('weatherModal')?.addEventListener('click', function(e) {
  if (e.target === this) closeWeatherModal();
});

// Sources modal
function openSourcesModal() {
  document.getElementById('sourcesModal').classList.add('show');
}
function closeSourcesModal() {
  document.getElementById('sourcesModal').classList.remove('show');
}
document.getElementById('sourcesModal')?.addEventListener('click', function(e) {
  if (e.target === this) closeSourcesModal();
});

// CURRENCY (using exchangerate.host or frankfurter.app - free)
const currencyData = [
  { code:'USD', flag:'🇺🇸', name:'دولار أمريكي', nameEn:'US Dollar' },
  { code:'ILS', flag:'🇮🇱', name:'شيقل إسرائيلي', nameEn:'Israeli Shekel' },
  { code:'JOD', flag:'🇯🇴', name:'دينار أردني', nameEn:'Jordanian Dinar' },
  { code:'EUR', flag:'🇪🇺', name:'يورو', nameEn:'Euro' },
  { code:'GBP', flag:'🇬🇧', name:'جنيه إسترليني', nameEn:'British Pound' },
  { code:'SAR', flag:'🇸🇦', name:'ريال سعودي', nameEn:'Saudi Riyal' },
  { code:'EGP', flag:'🇪🇬', name:'جنيه مصري', nameEn:'Egyptian Pound' },
  { code:'TRY', flag:'🇹🇷', name:'ليرة تركية', nameEn:'Turkish Lira' },
  { code:'AED', flag:'🇦🇪', name:'درهم إماراتي', nameEn:'UAE Dirham' },
  { code:'KWD', flag:'🇰🇼', name:'دينار كويتي', nameEn:'Kuwaiti Dinar' }
];

let exchangeRates = {};

function fetchCurrency() {
  // Frankfurter migrated the API to .dev; .app now serves a 301 that the
  // browser refuses to follow cross-origin (CORS denies redirects that
  // change origin), so we hit .dev directly.
  fetch('https://api.frankfurter.dev/v1/latest?base=USD&symbols=ILS,JOD,EUR,GBP,SAR,EGP,TRY,AED,KWD')
    .then(r => r.json())
    .then(data => {
      exchangeRates = data.rates || {};
      exchangeRates['USD'] = 1;
    }).catch(() => {});
}
fetchCurrency();

function openCurrencyModal() {
  const modal = document.getElementById('currencyModal');
  modal.classList.add('show');
  let html = '';
  const symbols = { USD:'$', ILS:'₪', JOD:'د.أ', EUR:'€', GBP:'£', SAR:'ر.س', EGP:'ج.م', TRY:'₺', AED:'د.إ', KWD:'د.ك' };
  currencyData.forEach(c => {
    const rate = c.code === 'USD' ? 1 : (exchangeRates[c.code] || '--');
    const rateStr = typeof rate === 'number' ? rate.toFixed(c.code === 'JOD' || c.code === 'KWD' ? 3 : 2) : rate;
    html += `
      <div class="modal-currency-row">
        <div class="modal-currency-info">
          <span class="modal-currency-flag">${c.flag}</span>
          <div>
            <div class="modal-currency-name">${c.name}</div>
            <div class="modal-currency-code">${c.code} - ${c.nameEn}</div>
          </div>
        </div>
        <div class="modal-currency-rates">
          <div class="modal-rate-buy"><span>${rateStr}</span> ${symbols[c.code] || ''}</div>
        </div>
      </div>`;
  });
  html += '<div style="text-align:center;font-size:11px;color:#bbb;margin-top:12px">سعر الصرف مقابل 1 دولار أمريكي</div>';
  document.getElementById('currencyModalBody').innerHTML = html;
}

function closeCurrencyModal() {
  document.getElementById('currencyModal').classList.remove('show');
}
document.getElementById('currencyModal').addEventListener('click', function(e) {
  if (e.target === this) closeCurrencyModal();
});

// MOST READ / TRENDING TABS
// Tab → panel + description swap. The "velocity" tab also hides the
// day/week/month pills since velocity is always "right now".
document.querySelectorAll('.mr2-tab').forEach(tab => {
  tab.addEventListener('click', function() {
    const target = this.dataset.mr2Tab;
    const section = this.closest('.mr2-section');
    if (!section) return;
    section.querySelectorAll('.mr2-tab').forEach(t => t.classList.toggle('active', t === this));
    section.querySelectorAll('[data-mr2-panel]').forEach(p => {
      p.hidden = (p.dataset.mr2Panel !== target);
    });
    section.querySelectorAll('[data-mr2-desc]').forEach(d => {
      d.hidden = (d.dataset.mr2Desc !== target);
    });
    const range = section.querySelector('[data-mr2-range]');
    if (range) range.style.visibility = (target === 'read') ? '' : 'hidden';
  });
});
// Range pill active state (visual only — data is server-rendered)
document.querySelectorAll('.mr2-range-opt input').forEach(inp => {
  inp.addEventListener('change', function() {
    const group = this.closest('.mr2-range');
    if (!group) return;
    group.querySelectorAll('.mr2-range-opt').forEach(o => o.classList.remove('active'));
    this.closest('.mr2-range-opt').classList.add('active');
  });
});

// CLICK-TO-LOAD INSTAGRAM REELS
// The reel cards ship as lightweight thumbnails; the real Instagram
// iframe (which pulls Instagram's embed SDK and is heavy) is only
// injected when the user actually clicks a card.
document.querySelectorAll('.reel-card-lazy').forEach(function(card){
  card.addEventListener('click', function(){
    if (card.dataset.reelLoaded === '1') return;
    var sc = card.dataset.reelShortcode;
    if (!sc) return;
    card.dataset.reelLoaded = '1';
    var iframe = document.createElement('iframe');
    iframe.src = 'https://www.instagram.com/reel/' + encodeURIComponent(sc) + '/embed/';
    iframe.setAttribute('scrolling', 'no');
    iframe.setAttribute('allowtransparency', 'true');
    iframe.setAttribute('allow', 'autoplay; encrypted-media');
    iframe.setAttribute('allowfullscreen', '');
    iframe.style.cssText = 'position:absolute!important;top:-54px!important;left:0!important;width:300px!important;height:800px!important;border:0!important;z-index:2;';
    // Remove the thumbnail/play overlay so the iframe has the stage.
    card.querySelectorAll('img,div').forEach(function(el){ el.remove(); });
    card.appendChild(iframe);
  });
});
