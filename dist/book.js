/* Запись на демонстрацию — одно окно для всех страниц: главная, /demo/, /call/.
 * Подключение: <link rel="stylesheet" href="/book.css"> и <script src="/book.js" defer> после /phone.js.
 * Любая кнопка с атрибутом data-book открывает окно; data-task — подпись источника для сделки
 * («Запись на демонстрацию (страница звонка)»). Ссылка сразу на окно — #demonstraciya.
 * Страница может подсказать окну контакты, уже известные ей: window.alloBookData = () => ({ name, phone, site })
 * (phone — с кодом страны, например +79001234567). Если задана window.alloBookToAgent(data), после записи
 * окно предлагает ещё и поговорить с агентом (главная: открывает форму звонка с теми же контактами).
 * Отправка → POST /api/book.php: сделка «Аллоалло-Демонстрация: Имя» на стадии «Назначена консультация»;
 * звонка агенту нет, время подтверждает менеджер. */
(() => {
  const $ = (s, root) => (root || document).querySelector(s);
  const $$ = (s, root) => [...(root || document).querySelectorAll(s)];

  const SLOTS = [['10-12', '10:00–12:00', 10], ['12-14', '12:00–14:00', 12], ['14-16', '14:00–16:00', 14], ['16-18', '16:00–18:00', 16]];
  const SLOT_LAST = 16;           // последний интервал начинается в 16:00
  const DAYS = 8;                 // сколько рабочих дней предлагать
  const WEEKDAYS = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
  const MONTHS = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
  const PHONE_MESSAGE = 'Введите номер телефона, например +7 900 123-45-67.';
  const ERRORS = {
    too_many: 'Слишком много заявок подряд. Попробуйте через час.',
    phone: PHONE_MESSAGE,
    name: 'Введите имя.',
    consent: 'Подтвердите согласие, чтобы отправить заявку.',
    slot: 'Выберите день и время ещё раз: этот интервал уже недоступен.'
  };
  const LABEL = 'Записаться';
  const DEFAULT_TASK = 'Запись на демонстрацию';

  const dialog = document.createElement('dialog');
  dialog.id = 'book-dialog';
  dialog.setAttribute('aria-labelledby', 'book-title');
  dialog.innerHTML = `<button class="bk-close" type="button" aria-label="Закрыть">×</button>
  <div id="book-view"><h2 id="book-title">Запись на демонстрацию</h2><p class="bk-muted">Покажем демо-агента вашей компании, подготовленного по вашему сайту: услуги, цены, адреса. Разберём, какие звонки передать ему первыми.</p>
    <form id="book-form" novalidate>
      <input type="hidden" name="task" value="${DEFAULT_TASK}">
      <div class="bk-hp" aria-hidden="true"><input name="company_extra" tabindex="-1" autocomplete="off"></div>
      <div class="bk-row">
        <input name="name" autocomplete="name" placeholder="Ваше имя" aria-label="Имя" required maxlength="100">
        <input name="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="Телефон" aria-label="Телефон" required maxlength="28">
      </div>
      <input name="site" autocomplete="url" inputmode="url" placeholder="Сайт компании" aria-label="Сайт компании" maxlength="200">
      <fieldset class="bk-pick"><legend>Удобный день</legend><div class="bk-chips" id="book-days"></div></fieldset>
      <fieldset class="bk-pick"><legend>Время <span>по Москве</span></legend><div class="bk-chips bk-slots" id="book-slots">${SLOTS.map(([v, t, from]) =>
        `<label><input type="radio" name="slot" value="${v}" data-from="${from}"><span>${t}</span></label>`).join('')}</div></fieldset>
      <label class="bk-consent"><input type="checkbox" name="consent" required><span>Согласен на <a href="/privacy/" target="_blank" rel="noopener">обработку персональных данных</a> для заявки и демонстрации в соответствии с <a href="/policy/" target="_blank" rel="noopener">политикой обработки персональных данных</a></span></label>
      <p class="bk-error" id="book-error" role="alert" hidden></p>
      <button class="bk-btn bk-primary" type="submit">${LABEL}</button>
      <p class="bk-note">Менеджер позвонит, чтобы подтвердить день и время.</p>
    </form></div>
  <div id="book-done" hidden><h2>Вы записаны</h2><p class="bk-muted" id="book-done-text"></p>
    <div class="bk-actions"><button class="bk-btn bk-primary" type="button" id="book-to-agent" hidden>Пока поговорить с агентом</button><button class="bk-btn bk-secondary" type="button" id="book-close">Закрыть</button></div></div>`;
  document.body.append(dialog);

  const form = $('#book-form', dialog);
  const field = (k) => form.elements.namedItem(k);
  const phone = window.AlloPhone ? AlloPhone.attach(field('phone')) : null;
  const phoneCheck = () => phone ? phone.validate() : (() => { const d = field('phone').value.replace(/\D/g, ''); return { ok: d.length >= 10, value: d ? '+' + d : '', message: PHONE_MESSAGE }; })();

  /* Метки визита (utm_*): главная хранит их в sessionStorage на время вкладки; на других страницах — из адреса. */
  function utm() {
    const q = new URLSearchParams(location.search), out = {};
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(k => { if (q.get(k)) out[k] = q.get(k).slice(0, 200); });
    if (Object.keys(out).length) return out;
    try { return JSON.parse(sessionStorage.getItem('alloallo-utm') || '{}'); } catch (e) { return {}; }
  }

  /* Сегодняшняя дата и час по Москве, в каком бы поясе ни был посетитель. */
  function moscowNow() {
    const p = {};
    new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Moscow', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', hourCycle: 'h23' })
      .formatToParts(new Date()).forEach(x => { p[x.type] = x.value; });
    return { y: +p.year, m: +p.month, d: +p.day, h: +p.hour };
  }
  /* Сегодняшний интервал можно выбрать, если до его начала не меньше часа. */
  const slotOpen = (from, isToday, hour) => !isToday || from >= hour + 1;

  /* Свободное время из Google Календаря менеджера (/api/slots.php): starts — начала встреч в UTC.
     Пока не загрузилось или календарь не ответил — прежние интервалы, время подтверждает менеджер. */
  let free = null; // { 'YYYY-MM-DD' (по Москве): [{ iso, time: 'HH:00' }] }
  const MSK_PARTS = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Moscow', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
  function groupStarts(starts) {
    const out = {};
    starts.forEach(iso => {
      const p = {};
      MSK_PARTS.formatToParts(new Date(iso)).forEach(x => { p[x.type] = x.value; });
      const day = p.year + '-' + p.month + '-' + p.day;
      (out[day] = out[day] || []).push({ iso, time: p.hour + ':' + p.minute });
    });
    return out;
  }
  async function loadFree() {
    try {
      const r = await fetch('/api/slots.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
      const j = await r.json().catch(() => ({}));
      free = j.ok && Array.isArray(j.starts) && j.starts.length ? groupStarts(j.starts) : null;
    } catch (e) { free = null; }
    if (dialog.open) renderDays();
  }

  function renderFreeDays() {
    const now = moscowNow(), today = Date.UTC(now.y, now.m - 1, now.d), box = $('#book-days', dialog);
    const keep = box.querySelector('input:checked')?.value;
    box.textContent = '';
    Object.keys(free).sort().slice(0, DAYS).forEach(value => {
      const day = new Date(value + 'T00:00:00Z'), i = Math.round((day - today) / 864e5);
      const label = document.createElement('label'), input = document.createElement('input'), span = document.createElement('span'), small = document.createElement('small');
      input.type = 'radio'; input.name = 'date'; input.value = value;
      small.textContent = i === 0 ? 'сегодня' : i === 1 ? 'завтра' : WEEKDAYS[day.getUTCDay()];
      span.append(day.getUTCDate() + ' ' + MONTHS[day.getUTCMonth()], small);
      label.append(input, span);
      box.append(label);
      if (value === keep) input.checked = true;
    });
    if (!box.querySelector('input:checked') && box.querySelector('input')) box.querySelector('input').checked = true;
    renderFreeTimes();
  }
  function renderFreeTimes() {
    const day = $('#book-days input:checked', dialog), box = $('#book-slots', dialog);
    const keep = box.querySelector('input[name=start]:checked')?.value;
    box.textContent = '';
    ((day && free[day.value]) || []).forEach(({ iso, time }) => {
      const label = document.createElement('label'), input = document.createElement('input'), span = document.createElement('span');
      input.type = 'radio'; input.name = 'start'; input.value = iso;
      span.textContent = time;
      label.append(input, span);
      box.append(label);
      if (iso === keep) input.checked = true;
    });
  }
  function renderIntervals() {
    const box = $('#book-slots', dialog);
    if (box.querySelector('input[name=slot]')) return;
    box.innerHTML = SLOTS.map(([v, t, from]) => `<label><input type="radio" name="slot" value="${v}" data-from="${from}"><span>${t}</span></label>`).join('');
  }

  function renderDays() {
    if (free) { renderFreeDays(); return; }
    renderIntervals();
    const now = moscowNow(), base = Date.UTC(now.y, now.m - 1, now.d), box = $('#book-days', dialog);
    const keep = box.querySelector('input:checked')?.value;
    box.textContent = '';
    for (let i = 0, n = 0; n < DAYS && i < 21; i++) {
      const day = new Date(base + i * 864e5), wd = day.getUTCDay();
      if (wd === 0 || wd === 6) continue;
      if (i === 0 && !slotOpen(SLOT_LAST, true, now.h)) continue;
      const value = day.toISOString().slice(0, 10);
      const label = document.createElement('label'), input = document.createElement('input'), span = document.createElement('span'), small = document.createElement('small');
      input.type = 'radio'; input.name = 'date'; input.value = value; input.dataset.today = i === 0 ? '1' : '';
      small.textContent = i === 0 ? 'сегодня' : i === 1 ? 'завтра' : WEEKDAYS[wd];
      span.append(day.getUTCDate() + ' ' + MONTHS[day.getUTCMonth()], small);
      label.append(input, span);
      box.append(label);
      if (value === keep) input.checked = true;
      n++;
    }
    updateSlots();
  }
  function updateSlots() {
    if (free) { renderFreeTimes(); return; }
    const day = $('#book-days input:checked', dialog), isToday = !!(day && day.dataset.today), h = moscowNow().h;
    $$('#book-slots input', dialog).forEach(s => {
      s.disabled = !slotOpen(+s.dataset.from, isToday, h);
      if (s.disabled) s.checked = false;
    });
  }

  function showError(text) { const el = $('#book-error', dialog); el.textContent = text; el.hidden = !text; }
  function validate(input) {
    input.setCustomValidity('');
    if (input.name === 'phone') { const r = phoneCheck(); if (!r.ok) input.setCustomValidity(r.message); }
    else if (input.name === 'name' && !input.value.trim()) input.setCustomValidity('Введите имя.');
    else if (input.name === 'consent' && !input.checked) input.setCustomValidity('Подтвердите согласие, чтобы отправить заявку.');
  }
  $$('input', form).forEach(input => {
    input.addEventListener('input', () => validate(input));
    input.addEventListener('change', () => validate(input));
  });
  $('#book-days', dialog).addEventListener('change', () => { updateSlots(); showError(''); });
  $('#book-slots', dialog).addEventListener('change', () => showError(''));

  /* Контакты, которые страница уже знает, — в пустые поля. */
  function prefill() {
    let d = null;
    try { d = typeof window.alloBookData === 'function' ? window.alloBookData() : window.alloBookData; } catch (e) {}
    if (!d) return;
    if (d.name && !field('name').value.trim()) field('name').value = String(d.name).trim();
    if (d.site && !field('site').value.trim()) field('site').value = String(d.site).trim();
    if (d.phone && phone && !phone.validate().ok) phone.set(String(d.phone));
  }

  function open(task) {
    showError('');
    field('task').value = task || DEFAULT_TASK;
    prefill();
    renderDays();
    loadFree();
    $('#book-view', dialog).hidden = false; $('#book-done', dialog).hidden = true;
    if (!dialog.open) dialog.showModal();
  }
  const close = () => dialog.close();
  $('.bk-close', dialog).addEventListener('click', close);
  $('#book-close', dialog).addEventListener('click', close);
  dialog.addEventListener('click', e => {
    if (e.target !== dialog) return;
    const r = dialog.getBoundingClientRect();
    if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) close();
  });
  $('#book-to-agent', dialog).addEventListener('click', () => {
    close();
    window.alloBookToAgent({ name: field('name').value.trim(), phone: phoneCheck().ok ? phoneCheck().value : '', site: field('site').value.trim() });
  });
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-book]');
    if (!b) return;
    e.preventDefault();
    open(b.dataset.task);
  });

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const button = form.querySelector('[type=submit]');
    $$('input', form).forEach(validate);
    if (phone) phone.check();
    if (!form.reportValidity() || button.disabled) return;
    const f = new FormData(form);
    if (!f.get('date')) { showError('Выберите удобный день.'); return; }
    if (!f.get('slot') && !f.get('start')) { showError('Выберите удобное время.'); return; }
    showError('');
    const payload = {
      name: f.get('name'), phone: phoneCheck().value, site: f.get('site'), consent: f.get('consent') === 'on',
      date: f.get('date'), slot: f.get('slot') || '', start: f.get('start') || '', task: f.get('task'), company_extra: f.get('company_extra'),
      page: location.origin + location.pathname, utm: utm()
    };
    button.disabled = true; button.textContent = 'Записываем…';
    try {
      const r = await fetch('/api/book.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      const j = await r.json().catch(() => ({}));
      if (j.ok) {
        const text = $('#book-done-text', dialog), when = document.createElement('strong');
        when.textContent = j.slot_text || '';
        text.textContent = '';
        text.append(String(payload.name).trim() + ', ждём вас на демонстрации: ', when, '. Менеджер позвонит, чтобы подтвердить время.');
        $('#book-to-agent', dialog).hidden = typeof window.alloBookToAgent !== 'function';
        $('#book-view', dialog).hidden = true; $('#book-done', dialog).hidden = false;
      } else {
        if (j.error === 'slot') { renderDays(); loadFree(); }
        showError(ERRORS[j.error] || 'Не удалось записаться. Попробуйте ещё раз через минуту.');
      }
    } catch (err) {
      showError('Нет связи с сервером. Проверьте интернет и попробуйте ещё раз.');
    }
    button.disabled = false; button.textContent = LABEL;
  });

  /* Ссылка сразу на окно записи: …/#demonstraciya */
  function fromHash() {
    if (location.hash !== '#demonstraciya') return;
    history.replaceState(null, '', location.pathname + location.search);
    open();
  }
  fromHash();
  window.addEventListener('hashchange', fromHash);

  window.AlloBook = { open };
})();
