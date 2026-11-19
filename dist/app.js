const $ = (s) => document.querySelector(s);
const $$ = (s) => [...document.querySelectorAll(s)];
const listItems = (items) => items.map(text => { const li = document.createElement('li'); li.textContent = text; return li; });

$$('.waveform').forEach(w => { for (let i = 0; i < 65; i++) { const b = document.createElement('i'); b.style.setProperty('--h', `${5 + Math.abs(Math.sin(i * .87) * Math.cos(i * .16)) * 32}px`); b.style.setProperty('--d', `${i * .034}s`); w.append(b); } });

/* Contact dialog. Form → /api/lead.php (deal in Bitrix24) → call page /call/#<code> with the voice widget. */
const dialog = $('#contact-dialog');
const DEFAULT_TASK = 'Разговор с агентом и демонстрация';
/* Visible fields: name, phone, site. Hidden fields keep which button and industry the request came from. */
function openContact(task, industry) {
  showFormError('');
  $('#contact-form [name=task]').value = task || DEFAULT_TASK;
  $('#contact-form [name=industry]').value = industry || '';
  dialog.showModal();
}
$$('[data-contact]').forEach(b => b.addEventListener('click', () => openContact(b.dataset.task, b.dataset.industry)));
$('.dialog-close').addEventListener('click', () => dialog.close());
dialog.addEventListener('click', e => { if (e.target === dialog) { const r = dialog.getBoundingClientRect(); if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) dialog.close(); } });

/* UTM tags of the visit go into the deal. Kept for the tab in case the visitor opens the form later. */
const utm = (() => {
  const q = new URLSearchParams(location.search), out = {};
  ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(k => { if (q.get(k)) out[k] = q.get(k).slice(0, 200); });
  try {
    if (Object.keys(out).length) sessionStorage.setItem('alloallo-utm', JSON.stringify(out));
    else return JSON.parse(sessionStorage.getItem('alloallo-utm') || '{}');
  } catch (e) {}
  return out;
})();

const phoneMessage = 'Введите номер телефона, например +7 900 123-45-67.';
// Поле телефона: код страны, маска и проверка номера по стране (phone.js).
const phoneField = window.AlloPhone ? AlloPhone.attach($('#contact-form [name=phone]')) : null;
function validateContactField(input) {
  input.setCustomValidity('');
  if (input.name === 'phone') {
    const r = phoneField ? phoneField.validate() : { ok: input.value.replace(/\D/g, '').length >= 10, message: phoneMessage };
    if (!r.ok) input.setCustomValidity(r.message);
  } else if (input.name === 'name' && !input.value.trim()) input.setCustomValidity('Введите имя.');
  else if (input.name === 'consent' && !input.checked) input.setCustomValidity('Подтвердите согласие, чтобы отправить заявку.');
}
$$('#contact-form input').forEach(input => {
  input.addEventListener('input', () => validateContactField(input));
  input.addEventListener('change', () => validateContactField(input));
});

function showFormError(text) {
  const el = $('#form-error');
  el.textContent = text;
  el.hidden = !text;
}
const LEAD_ERRORS = {
  too_many: 'Слишком много заявок подряд. Попробуйте через час.',
  phone: phoneMessage,
  name: 'Введите имя.',
  consent: 'Подтвердите согласие, чтобы отправить заявку.'
};
const SUBMIT_LABEL = 'Поговорить с агентом';

$('#contact-form').addEventListener('submit', async e => {
  e.preventDefault();
  const form = e.target, button = form.querySelector('[type=submit]');
  $$('#contact-form input').forEach(validateContactField);
  if (phoneField) phoneField.check(); // подсветить поле, если номер неверный
  if (!form.reportValidity() || button.disabled) return;
  showFormError('');
  const f = new FormData(form);
  const payload = {
    name: f.get('name'), phone: phoneField ? phoneField.validate().value : f.get('phone'), site: f.get('site'), consent: f.get('consent') === 'on',
    task: f.get('task'), industry: f.get('industry'), company_extra: f.get('company_extra'),
    page: location.origin + location.pathname, utm
  };
  button.disabled = true; button.textContent = 'Открываем звонок…';
  try {
    const r = await fetch('/api/lead.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const j = await r.json().catch(() => ({}));
    if (j.ok && j.url) { location.assign(j.url); return; }
    showFormError(LEAD_ERRORS[j.error] || 'Не удалось отправить заявку. Попробуйте ещё раз через минуту.');
  } catch (err) {
    showFormError('Нет связи с сервером. Проверьте интернет и попробуйте ещё раз.');
  }
  button.disabled = false; button.textContent = SUBMIT_LABEL;
});
/* Back from the call page with the browser's Back button: the page may come from the bfcache with a busy button. */
window.addEventListener('pageshow', e => {
  const button = $('#contact-form [type=submit]');
  if (e.persisted && button.disabled) { button.disabled = false; button.textContent = SUBMIT_LABEL; }
});
/* Link straight to the form: аллоалло.рф/#zayavka (the call page links here when a call link has expired). */
function openFromHash() {
  if (location.hash !== '#zayavka') return;
  history.replaceState(null, '', location.pathname + location.search);
  if (!dialog.open) openContact();
}
openFromHash();
window.addEventListener('hashchange', openFromHash);

/* Mobile menu. */
function closeMenu() { $('#navlinks').classList.remove('open'); $('.menu-toggle').setAttribute('aria-expanded', 'false'); $('.menu-toggle').setAttribute('aria-label', 'Открыть меню'); }
$('.menu-toggle').addEventListener('click', () => { const isOpen = $('#navlinks').classList.toggle('open'); $('.menu-toggle').setAttribute('aria-expanded', isOpen); $('.menu-toggle').setAttribute('aria-label', isOpen ? 'Закрыть меню' : 'Открыть меню'); });
$$('#navlinks a').forEach(a => a.addEventListener('click', closeMenu));
/* Close on a tap anywhere outside the menu (pointerdown also fires on iOS for non-clickable areas) and on Escape. */
document.addEventListener('pointerdown', e => {
  if (!$('#navlinks').classList.contains('open')) return;
  if (e.target.closest('#navlinks') || e.target.closest('.menu-toggle')) return;
  closeMenu();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && $('#navlinks').classList.contains('open')) { closeMenu(); $('.menu-toggle').focus(); }
});

/* Shared tab helpers. */
function selectTab(button, buttons) { buttons.forEach(b => { const active = b === button; b.classList.toggle('active', active); b.setAttribute('aria-selected', active); b.tabIndex = active ? 0 : -1; }); }
function bindTabKeys(selector) { const tabs = $$(selector); tabs.forEach((tab, i) => tab.addEventListener('keydown', e => { let n; if (e.key === 'ArrowRight') n = (i + 1) % tabs.length; if (e.key === 'ArrowLeft') n = (i - 1 + tabs.length) % tabs.length; if (e.key === 'Home') n = 0; if (e.key === 'End') n = tabs.length - 1; if (n !== undefined) { e.preventDefault(); tabs[n].focus(); tabs[n].click(); } })); }

/* Hero call card. */
const calls = {
  missed: {
    title: 'Звонок в 21:47', subtitle: 'Администратор не ответил — отвечает Аллоалло',
    lines: ['Здравствуйте! Клиника «Улыбка», голосовой ассистент. Разговор записывается. Чем помочь?', 'Добрый вечер! Можно записаться на чистку зубов на завтра?', 'Да, завтра есть 10:30. Записываю вас и пришлю SMS с адресом.'],
    result: 'Запись создана', detail: 'Завтра, 10:30 · SMS отправлено'
  },
  request: {
    title: 'Заявка с сайта, 18:42', subtitle: 'Аллоалло перезванивает клиенту',
    lines: ['Здравствуйте! Это голосовой ассистент клиники «Улыбка». Вы оставили заявку на сайте. Удобно сейчас говорить?', 'Да, хотел узнать про брекеты.', 'Подберу консультацию ортодонта. Завтра в 17:00 подойдёт?'],
    result: 'Консультация назначена', detail: 'Завтра, 17:00 · карточка в CRM'
  }
};
/* Real agent recordings: put files into src/audio and set paths, e.g. '/audio/missed.mp3'.
   Until then the button offers a test call instead of a synthetic browser voice. */
const recordings = { missed: '', request: '' };
let direction = 'missed', player = null;
function setAudioLabels() {
  const has = Boolean(recordings[direction]);
  $('#audio-label').textContent = has ? 'Послушайте запись разговора' : 'Услышьте голос в тестовом звонке';
  $('#audio-status').textContent = has ? 'Запись агента Аллоалло' : 'Позвоните агенту из браузера';
  $('#play-demo').setAttribute('aria-label', has ? 'Воспроизвести запись разговора' : 'Услышать голос агента в тестовом звонке');
}
function stopAudio() {
  if (player) { player.pause(); player = null; }
  $('#play-demo').classList.remove('is-playing');
  $('.waveform').classList.remove('playing');
  setAudioLabels();
}
$$('[data-call]').forEach(b => b.addEventListener('click', () => {
  stopAudio(); direction = b.dataset.call; const c = calls[direction];
  $$('[data-call]').forEach(x => { x.classList.toggle('active', x === b); x.setAttribute('aria-pressed', x === b); });
  ['agent-line', 'client-line', 'answer-line'].forEach((id, i) => $('#' + id).textContent = c.lines[i]);
  $('#call-title').textContent = c.title; $('#call-subtitle').textContent = c.subtitle;
  $('#result-title').textContent = c.result; $('#result-detail').textContent = c.detail;
  setAudioLabels();
}));
$('#play-demo').addEventListener('click', () => {
  const src = recordings[direction];
  if (!src) { openContact(DEFAULT_TASK); return; }
  if (player) { stopAudio(); return; }
  player = new Audio(src);
  player.addEventListener('ended', stopAudio);
  player.addEventListener('error', () => { stopAudio(); $('#audio-status').textContent = 'Запись недоступна. Закажите тестовый звонок.'; });
  player.play().then(() => {
    $('#play-demo').classList.add('is-playing'); $('.waveform').classList.add('playing');
    $('#play-demo').setAttribute('aria-label', 'Остановить запись'); $('#audio-label').textContent = 'Идёт запись разговора';
  }).catch(() => stopAudio());
});
window.addEventListener('pagehide', stopAudio);
setAudioLabels();

/* When the agent picks up. */
const modes = {
  missed: { tag: 'РЕЖИМ ДЛЯ СТАРТА', title: 'Агент берёт только\nто, что вы теряете.', description: 'Если за 10 секунд никто не ответил, АТС передаёт звонок агенту. Администраторы работают как раньше, а вы видите, сколько звонков агент вернул.', points: ['Отвечает по вашему прайсу, расписанию и правилам', 'Записывает, принимает заказ или собирает заявку', 'Сохраняет итог в CRM и пишет клиенту в мессенджер или SMS'], dash: 'Маршрут звонка', flow: [['Звонок поступил', 'Администратор говорит с другим клиентом'], ['10 секунд без ответа', 'АТС передаёт звонок агенту'], ['Клиент записан', 'Итог в CRM, SMS отправлено']] },
  offhours: { tag: 'ВЕЧЕР, НОЧЬ, ВЫХОДНЫЕ', title: 'Рабочий день закончился.\nЛиния — нет.', description: 'В нерабочие часы звонки принимает агент. Утром команда видит в CRM записи и заявки, а не список пропущенных.', points: ['Отвечает по вашему расписанию и прайсу', 'Записывает на ближайшие свободные окна', 'Срочное отмечает для команды на утро'], dash: 'Звонок в 21:47', flow: [['Входящий звонок', 'Офис уже закрыт'], ['Отвечает агент', 'Уточняет запрос и проверяет расписание'], ['Запись на завтра', 'Команда видит её утром в CRM']] },
  firstline: { tag: 'ПЕРВАЯ ЛИНИЯ', title: 'Типовые вопросы — агенту.\nСложные — людям.', description: 'Агент отвечает первым, решает типовые запросы и передаёт сотруднику то, что требует человека.', points: ['Отвечает на вопросы о ценах, адресах и графике', 'Оформляет записи и заказы', 'Переводит звонок через АТС или ставит задачу перезвонить'], dash: 'Первая линия', flow: [['Любой входящий звонок', 'Агент отвечает первым'], ['Типовой вопрос решён', 'Запись, заказ или ответ по прайсу'], ['Сложный — сотруднику', 'Перевод через АТС или задача на перезвон']] }
};
$$('[data-scenario]').forEach(b => b.addEventListener('click', () => {
  const d = modes[b.dataset.scenario];
  selectTab(b, $$('[data-scenario]'));
  $('#scenario-panel').setAttribute('aria-labelledby', b.id);
  $('#scenario-tag').textContent = d.tag;
  $('#scenario-title').textContent = d.title; $('#scenario-title').style.whiteSpace = 'pre-line';
  $('#scenario-description').textContent = d.description;
  $('#scenario-points').replaceChildren(...listItems(d.points));
  $('#dash-title').textContent = d.dash;
  d.flow.forEach(([title, sub], i) => { $(`#flow-${i + 1}`).textContent = title; $(`#flow-${i + 1}-sub`).textContent = sub; });
}));
bindTabKeys('[data-scenario]');

/* Conversation examples. */
const conversationExamples = {
  normal: { context: 'Клиент хочет записаться на завтра', agent: 'Есть окно завтра в 14:30 или в 16:00. Какое удобнее?', client: 'Давайте в 14:30.', status: 'Запись создана', detail: 'Завтра, 14:30 · профгигиена · SMS отправлено' },
  interrupt: { context: 'Клиент передумал, пока агент отвечал', agent: 'Записываю вас на завтра на 14:30…', client: 'Подождите, лучше в субботу утром.', status: 'Остановился и изменил только время', detail: 'Суббота, 10:00 · остальные данные без изменений' },
  noise: { context: 'На фоне — шум улицы и чужие голоса', agent: 'Назовите, пожалуйста, адрес доставки.', client: 'Ленина, двенадцать, квартира сорок.', status: 'Адрес сверен перед оформлением', detail: 'Заказ оформлен · доставка завтра с 10 до 14' }
};
$$('[data-conversation]').forEach(button => button.addEventListener('click', () => {
  const example = conversationExamples[button.dataset.conversation];
  selectTab(button, $$('[data-conversation]'));
  $('#conversation-panel').setAttribute('aria-labelledby', button.id);
  Object.entries(example).forEach(([key, value]) => $('#conversation-' + key).textContent = value);
  $('.conversation-demo').classList.toggle('noise-demo', button.dataset.conversation === 'noise');
}));
bindTabKeys('[data-conversation]');

/* Industries. */
const industries = {
  delivery: { name: 'Доставка', quote: '«Как обычно, два бутыля, только на новый адрес»', result: 'Агент находит клиента по номеру в вашей системе, меняет адрес и сверяет заказ перед оформлением.', title: 'Заказ с новым адресом', points: ['Повторные заказы по номеру клиента', 'Изменение адреса и времени доставки', 'Напоминание о доставке и детали в SMS'], cta: 'Подобрать сценарий для доставки' },
  auto: { name: 'Автосервис и шины', quote: '«Сколько стоит переобуться на R17 и когда можно приехать?»', result: 'Агент называет цену «от» по вашему прайсу и предлагает свободное время на шиномонтаж.', title: 'Сезон без потерянных звонков', points: ['Цены по вашему прайсу', 'Запись на шиномонтаж и ТО', 'Перенос и подтверждение визита'], cta: 'Подобрать сценарий для автосервиса' },
  clinic: { name: 'Клиники', quote: '«Болит зуб, можно сегодня?»', result: 'Агент ищет ближайшее окно и не даёт медицинских советов. Срочный случай передаёт администратору.', title: 'Запись без очереди на линии', points: ['Запись и перенос приёма', 'Напоминание о визите накануне', 'Срочное — администратору'], cta: 'Подобрать сценарий для клиники' },
  beauty: { name: 'Салоны красоты', quote: '«Можно к Ане на окрашивание в субботу?»', result: 'Агент проверяет расписание мастера и длительность услуги, затем оформляет запись.', title: 'Мастера заняты клиентами', points: ['Запись к выбранному мастеру', 'Перенос и подтверждение визита', 'Ответы об услугах и ценах'], cta: 'Подобрать сценарий для салона' },
  hotel: { name: 'Гостиницы', quote: '«Есть номер на двоих на эти выходные?»', result: 'Агент проверяет доступность в подключённой системе и называет условия проживания.', title: 'Бронь в любое время суток', points: ['Проверка доступности номеров', 'Бронь или заявка на бронь', 'Ответы о заезде и услугах'], cta: 'Подобрать сценарий для гостиницы' },
  restaurant: { name: 'Рестораны', quote: '«Столик на шестерых в пятницу в восемь»', result: 'Агент проверяет зал, уточняет пожелания и оформляет резерв.', title: 'Вечером линия не занята', points: ['Бронирование и перенос столиков', 'Пожелания гостей в брони', 'Ответы о меню и времени работы'], cta: 'Подобрать сценарий для ресторана' }
};
$$('[data-industry-tab]').forEach(b => b.addEventListener('click', () => {
  const d = industries[b.dataset.industryTab];
  selectTab(b, $$('[data-industry-tab]'));
  $('#industry-panel').setAttribute('aria-labelledby', b.id);
  $('#industry-quote').textContent = d.quote; $('#industry-result').textContent = d.result; $('#industry-title').textContent = d.title;
  $('#industry-points').replaceChildren(...listItems(d.points));
  $('#industry-contact-button').textContent = d.cta; $('#industry-contact-button').dataset.industry = d.name;
}));
bindTabKeys('[data-industry-tab]');

/* Hosting options. */
const deployments = {
  cloud: { title: 'Быстрый старт в российском облаке', description: 'Платформа работает на серверах в России. Вы подключаете телефонию и CRM, мы настраиваем сценарии и сопровождаем запуск.', points: ['Российские модели на всех этапах', 'Записи и карточки звонков хранятся в России', 'Без собственной инфраструктуры'], caption: 'ОБЛАКО В РОССИИ', boundary: 'Контур Аллоалло в России', note: 'Данные разговора не уходят за рубеж.' },
  attested: { title: 'Для строгих требований к данным', description: 'Если этого требует ваша служба безопасности, разместим платформу на аттестованной инфраструктуре в России: в дата-центре с аттестатом соответствия требованиям к защите персональных данных.', points: ['Тот же российский контур обработки', 'Уровень защищённости по аттестату дата-центра', 'Для клиник, финансовых компаний и крупных сетей'], caption: 'АТТЕСТОВАННАЯ ИНФРАСТРУКТУРА', boundary: 'Аттестованный дата-центр в России', note: 'Провайдера и уровень защищённости согласуем на разборе проекта.' },
  own: { title: 'Платформа в вашем контуре', description: 'На корпоративном тарифе разместим платформу на серверах компании. Данные не покидают ваш периметр, подключаются ваши средства защиты.', points: ['Размещение на ваших серверах', 'Доступ к внутренним системам', 'Конфигурация под вашу нагрузку'], caption: 'ИНФРАСТРУКТУРА ВАШЕЙ КОМПАНИИ', boundary: 'Ваши серверы', note: 'Состав компонентов и требования к серверам обсуждаем на разборе.' }
};
$$('[data-deploy]').forEach(b => b.addEventListener('click', () => {
  const d = deployments[b.dataset.deploy];
  selectTab(b, $$('[data-deploy]'));
  $('#deploy-panel').setAttribute('aria-labelledby', b.id);
  $('#deploy-title').textContent = d.title; $('#deploy-description').textContent = d.description;
  $('#deploy-points').replaceChildren(...listItems(d.points));
  $('#infra-caption').textContent = d.caption; $('#boundary-label').textContent = d.boundary; $('#infra-note').textContent = d.note;
  $('.infrastructure').classList.toggle('own', b.dataset.deploy !== 'cloud');
}));
bindTabKeys('[data-deploy]');

/* Missed-calls calculator: visitor's own numbers, not a promise. */
const money = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });
function calcValue(id, max) { const v = parseFloat($('#' + id).value.replace(',', '.')); if (!isFinite(v) || v < 0) return 0; return max ? Math.min(v, max) : v; }
function updateCalculator() {
  const calls = calcValue('calc-missed') * 30;
  const revenue = calls * calcValue('calc-intent', 100) / 100 * calcValue('calc-conv', 100) / 100 * calcValue('calc-check');
  const cost = calls * calcValue('calc-min') * calcValue('calc-rate');
  $('#calc-out-calls').textContent = money.format(calls);
  $('#calc-out-revenue').textContent = money.format(revenue) + ' ₽';
  $('#calc-out-cost').textContent = money.format(cost) + ' ₽';
}
$$('.calc-grid input').forEach(input => input.addEventListener('input', updateCalculator));
updateCalculator();

/* Call and chat as one conversation. */
const messageExamples = {
  during: { call: '«Отправлю адрес и подтверждение, пока мы говорим»', firstLabel: 'АГЕНТ · ВО ВРЕМЯ ЗВОНКА', first: 'Вы записаны на завтра, 14:30, профгигиена. Адрес: ул. Центральная, 10. Если планы изменятся, напишите сюда.', secondLabel: 'КЛИЕНТ · ПОСЛЕ ЗВОНКА', second: 'Спасибо! А парковка рядом есть?', result: 'Агент в переписке отвечает с учётом звонка' },
  reminder: { call: 'Накануне визита агент напоминает о записи', firstLabel: 'АГЕНТ · НАПОМИНАНИЕ', first: 'Здравствуйте! Напоминаем о записи завтра в 14:30. Всё в силе?', secondLabel: 'КЛИЕНТ', second: 'Можно перенести на пятницу после обеда?', result: 'Запись перенесена на пятницу, 15:00 · расписание обновлено' },
  context: { call: 'Вчера клиент писал в мессенджер, сегодня звонит', firstLabel: 'КЛИЕНТ · В ПЕРЕПИСКЕ, ВЧЕРА', first: 'Сколько стоит отбеливание и есть ли окна на этой неделе?', secondLabel: 'АГЕНТ · В ЗВОНКЕ, СЕГОДНЯ', second: 'Вы спрашивали про отбеливание. Есть окно в четверг в 18:00 — записать вас?', result: 'Голосовой агент продолжил разговор из переписки' }
};
$$('[data-msg]').forEach(b => b.addEventListener('click', () => {
  const d = messageExamples[b.dataset.msg];
  selectTab(b, $$('[data-msg]'));
  $('#msg-panel').setAttribute('aria-labelledby', b.id);
  $('#msg-call').textContent = d.call;
  $('#msg-first-label').textContent = d.firstLabel; $('#msg-first').textContent = d.first;
  $('#msg-second-label').textContent = d.secondLabel; $('#msg-second').textContent = d.second;
  $('#msg-result').textContent = d.result;
}));
bindTabKeys('[data-msg]');

/* Motion. Native Web Animations API (the engine Motion's animate() is built on),
   so the page loads no third-party scripts. Everything respects prefers-reduced-motion. */
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches || !document.body.animate;
const ease = 'cubic-bezier(.22,1,.36,1)';
const spring = 'cubic-bezier(.34,1.56,.64,1)';
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
function fadeUp(el, { delay = 0, distance = 24, duration = 750 } = {}) {
  if (reduceMotion || !el) return;
  el.animate([{ opacity: 0, transform: `translateY(${distance}px)` }, { opacity: 1, transform: 'none' }], { duration, delay, easing: ease, fill: 'backwards' });
}
function popIn(el, delay = 0) {
  if (reduceMotion || !el) return;
  el.animate([{ opacity: 0, transform: 'scale(.9) translateY(10px)' }, { opacity: 1, transform: 'none' }], { duration: 560, delay, easing: spring, fill: 'backwards' });
}
function animateFlow(dash, delay = 0) {
  if (reduceMotion || !dash) return;
  dash.querySelectorAll('.flow-step').forEach((step, i) => {
    step.animate([{ opacity: 0, transform: 'translateX(-14px)' }, { opacity: 1, transform: 'none' }], { duration: 500, delay: delay + i * 220, easing: ease, fill: 'backwards' });
    const check = step.querySelector('.flow-check');
    if (check) check.animate([{ opacity: 0, transform: 'scale(.3)' }, { opacity: 1, transform: 'scale(1)' }], { duration: 420, delay: delay + i * 220 + 260, easing: spring, fill: 'backwards' });
  });
}

/* Hero: the call plays out. The agent speaks, the client answers, the result appears. */
let heroToken = 0;
async function playHero() {
  const token = ++heroToken;
  const messages = $$('.transcript .message'), result = $('.floating-result'), wave = $('.waveform'), timer = $('.audio-time');
  if (reduceMotion) { timer.textContent = '0:07'; return; }
  messages.forEach(m => { m.style.opacity = '0'; });
  result.style.opacity = '0';
  const started = performance.now();
  const timerId = setInterval(() => {
    if (token !== heroToken) { clearInterval(timerId); return; }
    timer.textContent = `0:${String(Math.floor((performance.now() - started) / 1000)).padStart(2, '0')}`;
  }, 250);
  const say = async (i, mode, ms) => {
    if (token !== heroToken) return false;
    messages[i].style.opacity = '';
    fadeUp(messages[i], { distance: 12, duration: 520 });
    wave.classList.remove('playing', 'listening'); wave.classList.add(mode);
    $('.call-card').classList.toggle('speaking', mode === 'playing');
    await wait(ms);
    return token === heroToken;
  };
  await wait(450);
  if (!await say(0, 'playing', 2300)) return;
  if (!await say(1, 'listening', 1800)) return;
  if (!await say(2, 'playing', 2200)) return;
  wave.classList.remove('playing', 'listening');
  $('.call-card').classList.remove('speaking');
  result.style.opacity = ''; popIn(result);
  clearInterval(timerId);
}
$$('[data-call]').forEach(b => b.addEventListener('click', playHero));
if (!reduceMotion) {
  $$('.hero h1 .ln').forEach((line, i) => line.animate(
    [{ opacity: 0, transform: 'translateY(28px)', filter: 'blur(10px)' }, { opacity: 1, transform: 'none', filter: 'blur(0)' }],
    { duration: 900, delay: 130 * i, easing: ease, fill: 'backwards' }));
  $$('.hero-copy > :not(h1)').forEach((el, i) => fadeUp(el, { delay: 380 + 90 * i, distance: 18 }));
  popIn($('.call-card'), 250);
}
playHero();

/* Sections reveal as they scroll into view; neighbours follow one after another. */
const revealSelector = ['.section-heading', '.problem-card', '.stat-row .stat', '.works-with', '.scenario-tabs', '.scenario-panel', '.conversation-copy', '.conversation-demo', '.callback-grid > div', '.industry-tabs', '.industry-panel', '.feature-card', '.deployment-grid > *', '.security-points > div', '.pricing-copy', '.calculator-card', '.start-heading', '.steps-grid > div', '.pilot-footer', '.faq-section > div:first-child', '.faq-list details', '.final-cta'].join(',');
if (!reduceMotion && 'IntersectionObserver' in window) {
  const targets = $$(revealSelector);
  const io = new IntersectionObserver(entries => entries.forEach(entry => {
    if (!entry.isIntersecting) return;
    const el = entry.target; io.unobserve(el);
    const order = [...el.parentElement.children].filter(c => targets.includes(c)).indexOf(el);
    const delay = Math.min(Math.max(order, 0), 5) * 90;
    el.style.opacity = '';
    fadeUp(el, { delay });
    animateFlow(el.querySelector('.mini-dashboard'), delay + 250);
  }), { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
  targets.forEach(el => { if (el.getBoundingClientRect().top > innerHeight * .92) el.style.opacity = '0'; io.observe(el); });

  /* Figures count up once they are on screen. */
  const counter = new IntersectionObserver(entries => entries.forEach(entry => {
    if (!entry.isIntersecting) return;
    const el = entry.target; counter.unobserve(el);
    const raw = el.textContent, m = raw.match(/^(.*?)(\d+(?:,\d+)?)(.*)$/);
    if (!m) return;
    const [, before, number, after] = m, decimals = (number.split(',')[1] || '').length, target = parseFloat(number.replace(',', '.'));
    const start = performance.now(), duration = 1200;
    const step = now => {
      const t = Math.min(1, (now - start) / duration), value = target * (1 - Math.pow(1 - t, 3));
      el.textContent = t < 1 ? before + value.toFixed(decimals).replace('.', ',') + after : raw;
      if (t < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  }), { threshold: 0.6 });
  $$('.stat strong, .big-stat strong').forEach(el => counter.observe(el));
}

/* Tab content slides in when it changes. */
const tabPanels = { '[data-scenario]': ['#scenario-panel .scenario-copy', '#scenario-panel .mini-dashboard'], '[data-conversation]': ['#conversation-panel'], '[data-industry-tab]': ['#industry-panel'], '[data-deploy]': ['#deploy-panel', '.infra-boundary'], '[data-msg]': ['#msg-panel'] };
Object.entries(tabPanels).forEach(([tabs, panels]) => $$(tabs).forEach(b => b.addEventListener('click', () => panels.forEach(sel => {
  const el = $(sel);
  if (sel.includes('mini-dashboard')) animateFlow(el, 80); else fadeUp(el, { distance: 10, duration: 420 });
}))));

/* Calculator results roll to the new value. */
const calcOutputs = ['#calc-out-calls', '#calc-out-revenue', '#calc-out-cost'].map(id => $(id));
const digitsOf = el => Number(el.textContent.replace(/\D/g, '')) || 0;
calcOutputs.forEach(el => { el.dataset.value = digitsOf(el); });
$$('.calc-grid input').forEach(input => input.addEventListener('input', () => calcOutputs.forEach(el => {
  const to = digitsOf(el), from = Number(el.dataset.value) || 0;
  el.dataset.value = to;
  if (reduceMotion || from === to) return;
  const suffix = el.textContent.includes('₽') ? ' ₽' : '';
  const start = performance.now();
  const step = now => {
    if (Number(el.dataset.value) !== to) return;
    const t = Math.min(1, (now - start) / 450);
    el.textContent = money.format(from + (to - from) * (1 - Math.pow(1 - t, 3))) + suffix;
    if (t < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
})));
