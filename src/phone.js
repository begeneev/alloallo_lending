/* Поле телефона с выбором страны, маской и проверкой номера — для всех форм сайта (главная, /demo/).
 * AlloPhone.attach(input) оборачивает <input type="tel">: слева — код страны (флаг и +код, список стран —
 * обычный <select>), в поле — номер без кода по маске страны. Вставка или автозаполнение «+375 29 …»,
 * «8 900 …» сами выбирают страну и убирают лишнее.
 * Контроллер (AlloPhone.get(input)): validate() / check() → { ok, value: '+79001234567', message } (check ещё и
 * подсвечивает поле), set('+7…'), country().
 * Проверка: число цифр после кода и первые цифры номера по стране; «Другая страна» — номер целиком с +. */
(() => {
  // [ISO, название, код, маска (# — цифра), цифр от, до, с чего начинается номер, подсказка, пример]
  const LIST = [
    ['RU', 'Россия', '7', '(###) ###-##-##', 10, 10, /^[3489]/, '3, 4, 8 или 9', '(900) 123-45-67'],
    ['KZ', 'Казахстан', '7', '(###) ###-##-##', 10, 10, /^[67]/, '6 или 7', '(701) 123-45-67'],
    ['BY', 'Беларусь', '375', '(##) ###-##-##', 9, 9, /^[1-4]/, '1, 2, 3 или 4', '(29) 123-45-67'],
    ['UZ', 'Узбекистан', '998', '(##) ###-##-##', 9, 9, /^[2-9]/, '2–9', '(90) 123-45-67'],
    ['KG', 'Киргизия', '996', '(###) ###-###', 9, 9, /^[2-9]/, '2–9', '(555) 123-456'],
    ['TJ', 'Таджикистан', '992', '(##) ###-####', 9, 9, /^[3-9]/, '3–9', '(93) 123-4567'],
    ['AM', 'Армения', '374', '(##) ###-###', 8, 8, /^[1-9]/, '1–9', '(77) 123-456'],
    ['AZ', 'Азербайджан', '994', '(##) ###-##-##', 9, 9, /^[1-9]/, '1–9', '(50) 123-45-67'],
    ['GE', 'Грузия', '995', '(###) ##-##-##', 9, 9, /^[3-7]/, '3–7', '(555) 12-34-56'],
    ['MD', 'Молдова', '373', '(###) ##-###', 8, 8, /^[2-9]/, '2–9', '(691) 23-456'],
    ['TM', 'Туркменистан', '993', '(##) ##-##-##', 8, 8, /^[1-6]/, '1–6', '(65) 12-34-56'],
    ['UA', 'Украина', '380', '(##) ###-##-##', 9, 9, /^[3-9]/, '3–9', '(67) 123-45-67'],
    ['DE', 'Германия', '49', '#### ########', 7, 12, /^[1-9]/, '1–9', '1512 3456789'],
    ['IL', 'Израиль', '972', '(##) ###-####', 8, 9, /^[2-9]/, '2–9', '(50) 123-4567'],
    ['CN', 'Китай', '86', '(###) ####-####', 10, 11, /^[1-9]/, '1–9', '(131) 2345-6789'],
    ['LV', 'Латвия', '371', '#### ####', 8, 8, /^[2-9]/, '2–9', '2123 4567'],
    ['LT', 'Литва', '370', '(###) #####', 8, 8, /^[3-9]/, '3–9', '(612) 34567'],
    ['AE', 'ОАЭ', '971', '(##) ###-####', 8, 9, /^[1-9]/, '1–9', '(50) 123-4567'],
    ['RS', 'Сербия', '381', '(##) ###-####', 8, 9, /^[1-9]/, '1–9', '(60) 123-4567'],
    ['US', 'США и Канада', '1', '(###) ###-####', 10, 10, /^[2-9]/, '2–9', '(201) 555-0123'],
    ['TH', 'Таиланд', '66', '(##) ###-####', 8, 9, /^[1-9]/, '1–9', '(81) 234-5678'],
    ['TR', 'Турция', '90', '(###) ###-##-##', 10, 10, /^[2-5]/, '2, 3, 4 или 5', '(501) 234-56-78'],
    ['EE', 'Эстония', '372', '#### ####', 7, 8, /^[3-9]/, '3–9', '5123 4567'],
    ['XX', 'Другая страна', '', '', 8, 15, /^[1-9]/, '', '+ код страны и номер']
  ].map(([iso, name, code, mask, min, max, start, startText, example]) => ({ iso, name, code, mask, min, max, start, startText, example }));
  const BY_ISO = Object.fromEntries(LIST.map((c) => [c.iso, c]));
  const OTHER = BY_ISO.XX;
  const flag = (iso) => iso === 'XX' ? '🌐' : String.fromCodePoint(...[...iso].map((ch) => 0x1f1a5 + ch.charCodeAt(0)));

  // Страна по номеру с кодом (цифры без +): самый длинный подходящий код; +7 — Россия или Казахстан по первой цифре.
  function byCode(digits) {
    let best = null;
    for (const c of LIST) if (c.code && digits.startsWith(c.code) && (!best || c.code.length > best.code.length)) best = c;
    if (best && best.code === '7') return /^7[67]/.test(digits) ? BY_ISO.KZ : BY_ISO.RU;
    return best;
  }

  // Цифры по маске: скобки и дефисы появляются по мере ввода, хвост маски без цифр не дописывается.
  function format(digits, mask) {
    if (!mask) return digits;
    let out = '', k = 0;
    for (const ch of mask) {
      if (k >= digits.length) break;
      if (ch === '#') out += digits[k++];
      else out += ch;
    }
    return out + digits.slice(k); // цифры сверх маски (не бывает: режем по max)
  }
  const countDigits = (s, to) => (s.slice(0, to).match(/\d/g) || []).length;
  const posAfterDigits = (s, n) => { if (!n) return s.search(/\d/) < 0 ? s.length : Math.max(0, s.search(/\d/)); let k = 0; for (let i = 0; i < s.length; i++) if (/\d/.test(s[i]) && ++k === n) return i + 1; return s.length; };

  const CSS = `
.phone-field{display:flex;gap:8px;min-width:0;width:100%}
.phone-field>input{flex:1 1 auto;min-width:0;width:auto!important}
.phone-cc{position:relative;flex:none;display:flex;align-items:center;gap:6px;padding:0 26px 0 12px;min-height:var(--pf-h,42px);
  border:1px solid var(--pf-border,#dfdce8);border-radius:var(--pf-radius,8px);background:var(--pf-bg,#fff);color:var(--ink,#20212a);
  font-size:var(--pf-size,14px);white-space:nowrap;cursor:pointer}
.phone-cc::after{content:"";position:absolute;right:11px;top:50%;width:6px;height:6px;margin-top:-5px;border-right:1.5px solid currentColor;border-bottom:1.5px solid currentColor;transform:rotate(45deg);opacity:.55}
.phone-cc .pf-flag{font-size:1.15em;line-height:1}
.phone-cc select{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;border:0;padding:0;margin:0;min-height:0;font-size:16px}
.phone-cc:focus-within{border-color:var(--pf-focus,#6547ef)}
.phone-field input[aria-invalid="true"],.phone-field[data-invalid] input{border-color:#e5484d}`;
  let styled = false;

  function attach(input) {
    if (input._phone) return input._phone;
    if (!styled) { const st = document.createElement('style'); st.textContent = CSS; document.head.prepend(st); styled = true; }
    const wrap = document.createElement('div'); wrap.className = 'phone-field';
    const cc = document.createElement('label'); cc.className = 'phone-cc';
    const fl = document.createElement('span'); fl.className = 'pf-flag'; fl.setAttribute('aria-hidden', 'true');
    const code = document.createElement('span'); code.className = 'pf-code'; code.setAttribute('aria-hidden', 'true');
    const sel = document.createElement('select'); sel.setAttribute('aria-label', 'Код страны');
    for (const c of LIST) sel.add(new Option(flag(c.iso) + ' ' + c.name + (c.code ? ' +' + c.code : ''), c.iso));
    cc.append(fl, code, sel);
    input.parentNode.insertBefore(wrap, input);
    wrap.append(cc, input);
    input.removeAttribute('maxlength');
    input.setAttribute('inputmode', 'tel');
    let country = BY_ISO.RU, touched = false;

    function show() {
      sel.value = country.iso; fl.textContent = flag(country.iso); code.textContent = country.code ? '+' + country.code : '';
      input.placeholder = country.example;
    }
    // Из того, что в поле, — страна и цифры номера без кода. front — сколько цифр срезано в начале (код страны,
    // «8» у российского номера): на столько сдвигается курсор.
    let front = 0;
    function parse(raw) {
      const plus = raw.trim().startsWith('+');
      let d = raw.replace(/\D/g, '');
      front = 0;
      if (plus || country === OTHER) {
        const c = byCode(d);
        if (c && d.length > c.code.length) { country = c; front = c.code.length; d = d.slice(front); }
        else { country = OTHER; return d.slice(0, OTHER.max); }
      } else if (country.code === '7' && d.length === 11 && /^[78]/.test(d)) { front = 1; d = d.slice(1); } // 8 900 … и 7 900 …
      else if (country.code && d.length > country.max && d.startsWith(country.code)) { front = country.code.length; d = d.slice(front); }
      // +7: первая цифра номера подсказывает страну — 6/7 Казахстан, остальные Россия.
      if (country.code === '7' && d) country = /^[67]/.test(d) ? BY_ISO.KZ : BY_ISO.RU;
      return d.slice(0, country.max);
    }
    function render(d, caretDigits) {
      const v = country === OTHER ? (d ? '+' + d : '') : format(d, country.mask);
      input.value = v;
      show();
      if (caretDigits != null && document.activeElement === input) { const p = posAfterDigits(v, caretDigits); input.setSelectionRange(p, p); }
    }
    function validate() {
      const d = input.value.replace(/\D/g, '');
      if (!d) return { ok: false, value: '', message: 'Введите номер телефона.' };
      if (country === OTHER) {
        if (d.length < OTHER.min || d.length > OTHER.max) return { ok: false, value: '', message: 'Введите номер целиком: + код страны и номер, от 8 до 15 цифр.' };
        return { ok: true, value: '+' + d, message: '' };
      }
      const need = country.min === country.max ? country.min + ' цифр' : 'от ' + country.min + ' до ' + country.max + ' цифр';
      if (d.length < country.min) return { ok: false, value: '', message: 'Номер неполный: после +' + country.code + ' нужно ' + need + '.' };
      if (!country.start.test(d)) return { ok: false, value: '', message: 'Проверьте номер: ' + country.name + ', после +' + country.code + ' номер начинается с ' + country.startText + '.' };
      return { ok: true, value: '+' + country.code + d, message: '' };
    }
    function mark() { const r = validate(); const bad = touched && !r.ok && input.value.trim() !== ''; if (bad) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid'); return r; }

    input.addEventListener('input', () => {
      const caret = countDigits(input.value, input.selectionStart == null ? input.value.length : input.selectionStart);
      const d = parse(input.value);
      render(d, Math.max(0, Math.min(d.length, caret - front)));
      if (touched) mark();
    });
    // Стирание скобки или дефиса стирает цифру перед ними, иначе маска вернёт знак обратно и курсор застрянет.
    input.addEventListener('keydown', (e) => {
      if (e.key !== 'Backspace' || input.selectionStart !== input.selectionEnd || !input.selectionStart) return;
      const v = input.value, p = input.selectionStart;
      if (country === OTHER || /\d/.test(v[p - 1])) return;
      const n = countDigits(v, p);
      if (!n) return;
      e.preventDefault();
      const d = v.replace(/\D/g, '');
      const nd = d.slice(0, n - 1) + d.slice(n);
      render(nd.slice(0, country.max), n - 1);
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    input.addEventListener('blur', () => { touched = true; mark(); });
    sel.addEventListener('change', () => {
      country = BY_ISO[sel.value] || BY_ISO.RU;
      const d = input.value.replace(/\D/g, '');
      render(country === OTHER ? d : d.slice(0, country.max));
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.focus();
    });

    const ctrl = {
      validate,                                   // проверка без подсветки поля (пока человек печатает)
      check() { touched = true; return mark(); }, // проверка с подсветкой (при отправке формы)
      country: () => country,
      // Номер с кодом («+79001234567») — выбрать страну и разложить по маске.
      set(v) { const d = String(v || '').replace(/\D/g, ''); const c = byCode(d); if (c) { country = c; render(d.slice(c.code.length, c.code.length + c.max)); } else { country = OTHER; render(d); } },
      input
    };
    input._phone = ctrl;
    show();
    return ctrl;
  }

  window.AlloPhone = { attach, get: (input) => input._phone || attach(input), countries: LIST };
})();
