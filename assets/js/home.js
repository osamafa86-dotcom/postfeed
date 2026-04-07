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
    document.getElementById('wTemp').textContent = temp + '°';
    document.getElementById('wCity').textContent = c.name + '، فلسطين';
    document.getElementById('wDesc').textContent = weatherDesc[code] || 'غير معروف';
    document.getElementById('wIcon').textContent = weatherCodes[code] || '🌤';
    document.getElementById('topWeather').textContent = (weatherCodes[code]||'☀') + ' ' + c.name + ' ' + temp + '°';

    // Forecast
    const daily = data.daily;
    let forecastHTML = '';
    for (let i = 1; i <= 4; i++) {
      const d = new Date(daily.time[i]);
      const dCode = daily.weather_code[i];
      const dTemp = Math.round(daily.temperature_2m_max[i]);
      forecastHTML += `<div class="weather-day"><div class="day">${dayShort[d.getDay()]}</div><div>${weatherCodes[dCode]||'🌤'}</div><div class="temp">${dTemp}°</div></div>`;
    }
    document.getElementById('wForecast').innerHTML = forecastHTML;
  }).catch(() => {});
}

// City buttons
document.querySelectorAll('.weather-city-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.weather-city-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    fetchWeather(this.dataset.city);
  });
});

// Load default
fetchWeather('Jerusalem');

// Align weather widget to palestine hero card (top + bottom)
function syncWeatherHeight() {
  const w = document.querySelector('.weather-widget');
  if (!w) return;
  w.style.marginTop = ''; w.style.minHeight = ''; w.style.height = '';
  if (window.innerWidth < 1100) return;
  const ps = document.querySelector('.ps-hero');
  if (!ps || !w.parentElement) return;
  const psTop = ps.getBoundingClientRect().top;
  const wpTop = w.parentElement.getBoundingClientRect().top;
  const offset = psTop - wpTop;
  if (offset > 0) w.style.marginTop = offset + 'px';
  w.style.minHeight = ps.offsetHeight + 'px';
}
syncWeatherHeight();
window.addEventListener('load', syncWeatherHeight);
window.addEventListener('resize', syncWeatherHeight);
// Recalc whenever palestine hero images load
document.querySelectorAll('.ps-hero img').forEach(img => {
  if (img.complete) syncWeatherHeight();
  else img.addEventListener('load', syncWeatherHeight);
});
// Observe size changes (handles fonts/late layout)
if (window.ResizeObserver) {
  const ps = document.querySelector('.ps-hero');
  if (ps) new ResizeObserver(syncWeatherHeight).observe(ps);
}

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
  fetch('https://api.frankfurter.app/latest?from=USD&to=ILS,JOD,EUR,GBP,SAR,EGP,TRY,AED,KWD')
    .then(r => r.json())
    .then(data => {
      exchangeRates = data.rates;
      exchangeRates['USD'] = 1;
      // Update sidebar
      document.getElementById('cUSD').textContent = '1.00 $';
      document.getElementById('cILS').textContent = (exchangeRates['ILS'] || 3.65).toFixed(2) + ' ₪';
      document.getElementById('cJOD').textContent = (exchangeRates['JOD'] || 0.71).toFixed(3) + ' د.أ';
    }).catch(() => {
      document.getElementById('cUSD').textContent = '1.00 $';
      document.getElementById('cILS').textContent = '3.65 ₪';
      document.getElementById('cJOD').textContent = '0.709 د.أ';
    });
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
