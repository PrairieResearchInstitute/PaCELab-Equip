/* ==========================================================================
   app.js — the use entry form.

   Two jobs: fill the read-only rate fields from the chosen instrument, and keep
   the total charge current as the count changes. It also narrows the grant list
   to awards that cover the date of the run, which is a convenience only — the
   server checks the same rule and refuses the entry if it fails.

   No framework, no build step. Loaded at the end of the page, so the DOM exists.
   ========================================================================== */

/* --------------------------------------------------------------------------
   Handing a composed message to the mail client.

   The administrative panel builds a mailto: link when an instrument is retired
   or goes out of service. Following it opens whatever mail client the person
   uses, which on a campus desktop is Outlook, with the message ready but not
   sent. Nothing leaves the machine until they press send.

   It fires once per event. Reloading the page, or coming back to the URL later,
   does not reopen a compose window somebody has already dealt with.
   -------------------------------------------------------------------------- */
(function () {
  'use strict';

  var card = document.querySelector('[data-mailto]');
  if (!card) { return; }

  var href = card.getAttribute('data-mailto');
  var emailId = card.getAttribute('data-mailto-id');
  var csrf = card.getAttribute('data-csrf');
  var key = 'mailto-opened:' + (emailId || href).slice(0, 120);

  var alreadyOpened = false;
  try { alreadyOpened = window.sessionStorage.getItem(key) === '1'; } catch (ignored) {}
  if (alreadyOpened) { return; }
  try { window.sessionStorage.setItem(key, '1'); } catch (ignored) {}

  /* Tell the log the mail client was opened. This is the only claim the
     application can honestly make: not that the message was sent, only that it
     reached somebody's Outlook. */
  if (emailId && csrf) {
    var body = new FormData();
    body.append('action', 'mail_opened');
    body.append('email_id', emailId);
    var api = (window.location.pathname.indexOf('/admin/') > -1 ? '../' : '') + 'api.php';
    fetch(api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf },
      body: body
    }).catch(function () { /* the log is not worth interrupting anybody over */ });
  }

  // A tick after paint, so the page the person lands on is drawn behind the
  // compose window rather than appearing after it.
  window.setTimeout(function () { window.location.href = href; }, 350);
}());

/* --------------------------------------------------------------------------
   Remembering which sections were left open.

   The <details> elements do the opening and closing by themselves. This only
   records the state, so a screen someone has arranged the way they like stays
   that way on the next visit. Storage failing is not worth a broken page.
   -------------------------------------------------------------------------- */
(function () {
  'use strict';

  var sections = document.querySelectorAll('details[data-accordion]');
  if (!sections.length) { return; }

  Array.prototype.forEach.call(sections, function (section) {
    var key = 'accordion:' + section.getAttribute('data-accordion');

    try {
      var stored = window.localStorage.getItem(key);
      if (stored === 'open')   { section.open = true; }
      if (stored === 'closed') { section.open = false; }
    } catch (ignored) {}

    section.addEventListener('toggle', function () {
      try { window.localStorage.setItem(key, section.open ? 'open' : 'closed'); } catch (ignored) {}
    });
  });
}());

(function () {
  'use strict';

  var form = document.getElementById('useForm');
  if (!form) { return; }

  function role(name) { return form.querySelector('[data-role="' + name + '"]'); }

  var equipmentSelect = role('equipment');
  var grantSelect     = role('grant');
  var dateInput       = role('use-date');
  var countInput      = role('count');
  var countUnit       = role('count-unit');
  var rateDisplay     = role('rate-display');
  var subaccountField = role('subaccount-display');
  var grantHint       = role('grant-hint');
  var totalOut        = role('total');
  var formulaOut      = role('formula');
  var metaRate        = role('meta-rate');
  var metaUnit        = role('meta-unit');
  var metaSubaccount  = role('meta-subaccount');

  /* Every grant option as delivered, so filtering can put one back. */
  var allGrantOptions = [];
  if (grantSelect) {
    Array.prototype.forEach.call(grantSelect.options, function (option) {
      if (option.value !== '') { allGrantOptions.push(option); }
    });
  }

  var dash = '—';

  function money(amount) {
    return '$' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /* The noun that goes with a rate unit: "per sample" counts samples. */
  function nounFor(rateUnit) {
    if (rateUnit === 'per hour') { return 'hours'; }
    if (rateUnit === 'per run')  { return 'runs'; }
    return 'samples';
  }

  function selectedEquipment() {
    if (!equipmentSelect) { return null; }
    var option = equipmentSelect.options[equipmentSelect.selectedIndex];
    if (!option || !option.value) { return null; }
    return {
      rate: parseFloat(option.getAttribute('data-rate')) || 0,
      unit: option.getAttribute('data-unit') || '',
      subaccount: option.getAttribute('data-subaccount') || ''
    };
  }

  /* Show only grants whose award period covers the date of the run. */
  function filterGrants() {
    if (!grantSelect || !dateInput) { return; }

    var useDate = dateInput.value;
    var keep = grantSelect.value;
    var kept = false;
    var hidden = 0;

    while (grantSelect.options.length > 1) { grantSelect.remove(1); }

    allGrantOptions.forEach(function (option) {
      var start = option.getAttribute('data-start') || '';
      var end   = option.getAttribute('data-end') || '';
      var covers = (!useDate) || ((start === '' || start <= useDate) && (end === '' || end >= useDate));

      if (covers) {
        grantSelect.add(option);
        if (option.value === keep) { kept = true; }
      } else {
        hidden++;
      }
    });

    grantSelect.value = kept ? keep : '';

    if (grantHint) {
      if (hidden > 0) {
        grantHint.textContent = hidden === 1
          ? 'One grant does not cover ' + useDate + ' and is not listed. Ask an administrator if the charge belongs to it.'
          : hidden + ' grants do not cover ' + useDate + ' and are not listed. Ask an administrator if the charge belongs to one of them.';
        grantHint.className = 'hint grant-hint-warn';
      } else {
        grantHint.textContent = 'Only grants whose award period covers the date of the run are listed.';
        grantHint.className = 'hint';
      }
    }
  }

  /* Rate, subaccount, and the live total. */
  function refreshCharge() {
    var equipment = selectedEquipment();
    var count = parseInt(countInput && countInput.value, 10);
    if (isNaN(count) || count < 1) { count = 0; }

    if (!equipment) {
      if (rateDisplay)     { rateDisplay.value = dash; }
      if (subaccountField) { subaccountField.value = dash; }
      if (countUnit)       { countUnit.textContent = ''; }
      if (totalOut)        { totalOut.textContent = dash; }
      if (formulaOut)      { formulaOut.textContent = 'Choose an instrument and a count.'; }
      if (metaRate)        { metaRate.textContent = dash; }
      if (metaUnit)        { metaUnit.textContent = dash; }
      if (metaSubaccount)  { metaSubaccount.textContent = dash; }
      return;
    }

    var noun = nounFor(equipment.unit);

    if (rateDisplay)     { rateDisplay.value = money(equipment.rate) + ' ' + equipment.unit; }
    if (subaccountField) { subaccountField.value = equipment.subaccount || dash; }
    if (countUnit)       { countUnit.textContent = '(' + noun + ')'; }
    if (metaRate)        { metaRate.textContent = money(equipment.rate); }
    if (metaUnit)        { metaUnit.textContent = equipment.unit; }
    if (metaSubaccount)  { metaSubaccount.textContent = equipment.subaccount || dash; }

    var total = Math.round(equipment.rate * count * 100) / 100;

    if (totalOut)   { totalOut.textContent = count > 0 ? money(total) : dash; }
    if (formulaOut) {
      formulaOut.textContent = count > 0
        ? count + ' ' + (count === 1 ? noun.replace(/s$/, '') : noun) + ' × ' + money(equipment.rate)
        : 'Enter a count.';
    }
  }

  if (equipmentSelect) { equipmentSelect.addEventListener('change', refreshCharge); }
  if (countInput)      { countInput.addEventListener('input', refreshCharge); }
  if (dateInput)       { dateInput.addEventListener('change', filterGrants); }

  filterGrants();
  refreshCharge();

  /* A submitted form should not be submitted twice by an impatient second click. */
  form.addEventListener('submit', function () {
    var button = form.querySelector('button[type="submit"]');
    if (button) {
      window.setTimeout(function () {
        button.disabled = true;
        button.textContent = 'Saving…';
      }, 0);
    }
  });
}());

/* --------------------------------------------------------------------------
   The laboratory picker submits on change, so switching is one action rather
   than two. The Switch button beside it stays in the markup for anybody with
   script turned off, and is hidden here once this has taken over.
   -------------------------------------------------------------------------- */
(function () {
  'use strict';

  var picker = document.querySelector('[data-role="lab-picker"]');
  if (!picker || !picker.form) { return; }

  var button = picker.form.querySelector('button[type="submit"]');
  if (button) { button.hidden = true; }

  picker.addEventListener('change', function () { picker.form.submit(); });
}());
