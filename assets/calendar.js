/* ==========================================================================
   calendar.js — the scheduling grid.

   The grid skeleton is rendered by schedule.php. This file loads the week's
   bookings from api.php, draws them onto the grid, and handles dragging.

   It never decides a conflict. Every create, move, and resize is sent to the
   server, and local state changes only after the server says yes; when the
   server refuses, the block goes back where it was because it never moved in
   the data. Vanilla JavaScript, no build step.
   ========================================================================== */

(function () {
  'use strict';

  var grid = document.getElementById('calGrid');
  if (!grid) { return; }

  var messages = document.getElementById('calMessages');
  var weekLabel = document.getElementById('weekLabel');
  var picker = document.getElementById('equipmentPicker');

  var SLOTS_PER_DAY = 48;
  var DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var DAY_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  var MONTH_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  var state = {
    equipmentId: parseInt(grid.getAttribute('data-equipment'), 10) || 0,
    weekStart: grid.getAttribute('data-week'),
    me: grid.getAttribute('data-me') || '',
    admin: grid.getAttribute('data-admin') === '1',
    csrf: grid.getAttribute('data-csrf'),
    reservations: [],
    selectedId: null,
    clipboard: null,
    hover: null,
    busy: false
  };

  /* --- dates ------------------------------------------------------------ */

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  /* 'YYYY-MM-DD HH:MM:SS' parsed by hand: Safari will not parse it reliably. */
  function parseDT(text) {
    var m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(text || '');
    if (!m) { return null; }
    return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], 0, 0);
  }

  function parseDate(text) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(text || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3], 0, 0, 0, 0) : new Date();
  }

  function isoDate(date) {
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
  }

  function isoDateTime(date) {
    return isoDate(date) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':00';
  }

  function dayDate(dayIndex) {
    var d = parseDate(state.weekStart);
    d.setDate(d.getDate() + dayIndex);
    return d;
  }

  /* A (day, slot) pair as a datetime. Slot 48 is midnight at the end of the day. */
  function slotToDateTime(dayIndex, slot) {
    var d = dayDate(dayIndex + Math.floor(slot / SLOTS_PER_DAY));
    var s = ((slot % SLOTS_PER_DAY) + SLOTS_PER_DAY) % SLOTS_PER_DAY;
    d.setHours(Math.floor(s / 2), (s % 2) * 30, 0, 0);
    return isoDateTime(d);
  }

  function slotLabel(slot) {
    var s = ((slot % SLOTS_PER_DAY) + SLOTS_PER_DAY) % SLOTS_PER_DAY;
    var hour = Math.floor(s / 2);
    var minute = (s % 2) * 30;
    var suffix = hour < 12 ? 'am' : 'pm';
    var shown = hour % 12 === 0 ? 12 : hour % 12;
    return shown + ':' + pad(minute) + ' ' + suffix;
  }

  function prettyDay(date) {
    return DAY_SHORT[date.getDay()] + ' ' + MONTH_SHORT[date.getMonth()] + ' ' + date.getDate();
  }

  /* --- messages --------------------------------------------------------- */

  var messageTimer = null;

  function say(text, kind) {
    if (!messages) { return; }
    clearActionBar(true);
    var box = document.createElement('div');
    box.className = 'flash flash-' + (kind || 'notice');
    box.textContent = text;
    messages.insertBefore(box, messages.firstChild);

    window.clearTimeout(messageTimer);
    messageTimer = window.setTimeout(function () {
      if (box.parentNode) { box.parentNode.removeChild(box); }
    }, kind === 'error' ? 9000 : 5000);
  }

  function clearMessages() {
    if (!messages) { return; }
    Array.prototype.slice.call(messages.querySelectorAll('.flash')).forEach(function (el) {
      el.parentNode.removeChild(el);
    });
  }

  /* --- server ----------------------------------------------------------- */

  function get(params) {
    var query = Object.keys(params).map(function (key) {
      return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
    }).join('&');

    return fetch('api.php?' + query, { credentials: 'same-origin' })
      .then(function (response) { return response.json(); });
  }

  function post(params) {
    var body = new FormData();
    Object.keys(params).forEach(function (key) {
      var value = params[key];
      if (Array.isArray(value)) {
        value.forEach(function (item) { body.append(key + '[]', item); });
      } else {
        body.append(key, value);
      }
    });

    return fetch('api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': state.csrf },
      body: body
    }).then(function (response) { return response.json(); });
  }

  /* Report a refusal from the server and put the grid back in step with it. */
  function refused(result) {
    say(result && result.error ? result.error : 'The server refused that change.', 'error');
    if (result && result.reload) {
      window.setTimeout(function () { window.location.reload(); }, 1500);
      return;
    }
    load();
  }

  /* --- loading and drawing ---------------------------------------------- */

  function load() {
    if (!state.equipmentId) { return Promise.resolve(); }

    return get({
      action: 'list',
      equipment_id: state.equipmentId,
      week_start: state.weekStart
    }).then(function (result) {
      if (!result || !result.ok) { return refused(result); }
      state.reservations = result.reservations || [];
      state.me = result.me || state.me;
      state.admin = !!result.admin;
      render();
    }).catch(function () {
      say('The calendar could not reach the server. Check the connection and reload.', 'error');
    });
  }

  /* The half-hour rows a booking occupies on one day of the shown week. */
  function segmentsFor(reservation) {
    var start = parseDT(reservation.start);
    var end = parseDT(reservation.end);
    var out = [];
    if (!start || !end) { return out; }

    for (var day = 0; day < 7; day++) {
      var dayStart = dayDate(day);
      var dayEnd = dayDate(day + 1);

      var from = start > dayStart ? start : dayStart;
      var to = end < dayEnd ? end : dayEnd;
      if (from >= to) { continue; }

      // Clock fields rather than a millisecond difference, so the two days a
      // year that are not twenty-four hours long still line up.
      var startSlot = (from.getTime() <= dayStart.getTime())
        ? 0
        : from.getHours() * 2 + (from.getMinutes() >= 30 ? 1 : 0);
      var endSlot = (to.getTime() >= dayEnd.getTime())
        ? SLOTS_PER_DAY
        : to.getHours() * 2 + (to.getMinutes() >= 30 ? 1 : 0);

      if (endSlot > startSlot) {
        out.push({ day: day, startSlot: startSlot, endSlot: endSlot });
      }
    }
    return out;
  }

  function clearDrawn() {
    Array.prototype.slice.call(grid.querySelectorAll('.cal-block, .cal-ghost')).forEach(function (el) {
      el.parentNode.removeChild(el);
    });
  }

  function place(element, day, startSlot, endSlot) {
    element.style.gridColumn = String(day + 2);
    element.style.gridRow = (startSlot + 2) + ' / span ' + (endSlot - startSlot);
  }

  function render() {
    clearDrawn();
    renderDayNames();

    state.reservations.forEach(function (reservation) {
      segmentsFor(reservation).forEach(function (segment) {
        var block = document.createElement('div');
        block.className = 'cal-block' + (reservation.mine ? ' mine' : '')
          + (state.selectedId === reservation.id ? ' selected' : '');
        block.setAttribute('data-id', String(reservation.id));
        block.setAttribute('tabindex', '0');
        block.setAttribute('role', 'button');
        block.setAttribute('aria-label',
          reservation.by + ', ' + slotLabel(segment.startSlot) + ' to ' + slotLabel(segment.endSlot));
        place(block, segment.day, segment.startSlot, segment.endSlot);

        // What the block says without being asked. How much of it survives
        // depends on how tall the block is, which labelBlocks() works out once
        // the browser has laid the grid out.
        var label = document.createElement('span');
        label.className = 'cal-block-label';

        var who = document.createElement('span');
        who.className = 'cal-block-who';
        who.textContent = reservation.by || 'Booked';
        label.appendChild(who);

        var when = document.createElement('span');
        when.className = 'cal-block-when';
        when.textContent = slotLabel(segment.startSlot) + '–' + slotLabel(segment.endSlot);
        label.appendChild(when);

        if (reservation.purpose) {
          var why = document.createElement('span');
          why.className = 'cal-block-purpose';
          why.textContent = reservation.purpose;
          label.appendChild(why);
        }

        block.appendChild(label);

        if (canChange(reservation)) {
          var top = document.createElement('div');
          top.className = 'grip grip-top';
          top.setAttribute('data-grip', 'start');
          var bottom = document.createElement('div');
          bottom.className = 'grip grip-bottom';
          bottom.setAttribute('data-grip', 'end');
          block.appendChild(top);
          block.appendChild(bottom);
        }

        grid.appendChild(block);
      });
    });

    labelBlocks();

    if (state.selectedId && !byId(state.selectedId)) {
      state.selectedId = null;
      clearActionBar();
    }
  }

  /**
   * Decide how much of each block's label fits.
   *
   * A half hour booking is one row tall, which at a full day on one screen is
   * around fifteen pixels: room for a name and nothing else. Two hours has room
   * for the name, the times, and what the booking is for. Rather than guess,
   * measure the block and let the class say what survives; the tooltip carries
   * the rest either way.
   */
  function labelBlocks() {
    Array.prototype.slice.call(grid.querySelectorAll('.cal-block')).forEach(function (block) {
      var height = block.offsetHeight;
      block.classList.remove('label-full', 'label-compact', 'label-tiny', 'label-none');

      // Measured against the type sizes in the stylesheet: three lines need
      // about fifty pixels, two need about twenty-four, one needs eleven. An
      // hour-long booking lands just over the two-line mark, which is the case
      // worth getting right because most bookings are an hour.
      if (height >= 50)      { block.classList.add('label-full'); }
      else if (height >= 24) { block.classList.add('label-compact'); }
      else if (height >= 11) { block.classList.add('label-tiny'); }
      else                   { block.classList.add('label-none'); }
    });
  }

  function renderDayNames() {
    var start = parseDate(state.weekStart);
    var todayIso = isoDate(new Date());

    Array.prototype.slice.call(grid.querySelectorAll('.cal-dayname')).forEach(function (cell) {
      var day = parseInt(cell.getAttribute('data-day'), 10);
      var date = dayDate(day);
      cell.classList.toggle('is-today', isoDate(date) === todayIso);
      cell.innerHTML = '';
      cell.appendChild(document.createTextNode(DAY_SHORT[day]));
      var num = document.createElement('span');
      num.className = 'dnum';
      num.textContent = String(date.getDate());
      cell.appendChild(num);
      cell.title = DAY_NAMES[day] + ', ' + MONTH_SHORT[date.getMonth()] + ' ' + date.getDate();
    });

    if (weekLabel) {
      var end = dayDate(6);
      var sameMonth = start.getMonth() === end.getMonth();
      weekLabel.textContent = MONTH_SHORT[start.getMonth()] + ' ' + start.getDate()
        + ' – ' + (sameMonth ? '' : MONTH_SHORT[end.getMonth()] + ' ') + end.getDate()
        + ', ' + end.getFullYear();
    }
  }

  function byId(id) {
    for (var i = 0; i < state.reservations.length; i++) {
      if (state.reservations[i].id === id) { return state.reservations[i]; }
    }
    return null;
  }

  function canChange(reservation) {
    return reservation.may_edit === true;
  }

  /* --- fitting the grid to the viewport ----------------------------------- */

  /**
   * How tall the grid can be without pushing the page into a scroll.
   *
   * The approved Illinois header sits above the grid and its height is not
   * something this file can know in advance, so it is measured. The row height
   * still falls out of the available viewport through CSS grid; this only says
   * how much viewport there is.
   */
  function fitGrid() {
    var top = grid.getBoundingClientRect().top + window.scrollY;
    var hint = document.querySelector('.cal-hintbar');
    var reserve = (hint ? hint.offsetHeight + 12 : 0) + 24;
    var available = window.innerHeight - top + window.scrollY - reserve;

    // Never so short that a half hour stops being clickable.
    document.documentElement.style.setProperty('--cal-height', Math.max(340, available) + 'px');

    // Row height just changed, so what fits inside a block has changed too.
    labelBlocks();
  }

  var fitPending = null;
  function fitSoon() {
    window.clearTimeout(fitPending);
    fitPending = window.setTimeout(fitGrid, 60);
  }

  fitGrid();
  window.addEventListener('resize', fitSoon);
  // The header is a web component and settles a moment after first paint.
  window.addEventListener('load', fitSoon);
  window.setTimeout(fitGrid, 400);

  /* --- pointer geometry -------------------------------------------------- */

  function geometry() {
    var corner = grid.querySelector('.cal-corner');
    var gridRect = grid.getBoundingClientRect();
    var cornerRect = corner.getBoundingClientRect();

    return {
      left: gridRect.left + cornerRect.width,
      top: gridRect.top + cornerRect.height,
      colWidth: (gridRect.width - cornerRect.width) / 7,
      rowHeight: (gridRect.height - cornerRect.height) / SLOTS_PER_DAY
    };
  }

  function clamp(value, low, high) {
    return value < low ? low : (value > high ? high : value);
  }

  function pointToCell(clientX, clientY) {
    var g = geometry();
    return {
      day: clamp(Math.floor((clientX - g.left) / g.colWidth), 0, 6),
      slot: clamp(Math.floor((clientY - g.top) / g.rowHeight), 0, SLOTS_PER_DAY - 1)
    };
  }

  /* --- ghost ------------------------------------------------------------- */

  var ghost = null;

  function showGhost(day, startSlot, endSlot) {
    if (!ghost) {
      ghost = document.createElement('div');
      ghost.className = 'cal-ghost';
      grid.appendChild(ghost);
    }
    place(ghost, day, startSlot, endSlot);
  }

  function hideGhost() {
    if (ghost && ghost.parentNode) { ghost.parentNode.removeChild(ghost); }
    ghost = null;
  }

  /* --- tooltip ----------------------------------------------------------- */

  var tip = null;

  function showTip(reservation, clientX, clientY) {
    if (!tip) {
      tip = document.createElement('div');
      tip.className = 'cal-tip';
      document.body.appendChild(tip);
    }
    var start = parseDT(reservation.start);
    var end = parseDT(reservation.end);
    var hours = Math.round((end - start) / 360000) / 10;

    tip.innerHTML = '';

    var who = document.createElement('strong');
    who.textContent = reservation.by || 'Booked';
    tip.appendChild(who);

    var when = document.createElement('div');
    when.className = 'tip-time';
    when.textContent = prettyDay(start) + ', '
      + slotLabel(start.getHours() * 2 + (start.getMinutes() >= 30 ? 1 : 0))
      + ' to ' + slotLabel(end.getHours() * 2 + (end.getMinutes() >= 30 ? 1 : 0))
      + '  (' + hours + (hours === 1 ? ' hour)' : ' hours)');
    tip.appendChild(when);

    if (reservation.purpose) {
      var purpose = document.createElement('div');
      purpose.textContent = reservation.purpose;
      tip.appendChild(purpose);
    }

    var status = document.createElement('div');
    status.className = 'tip-time';
    if (reservation.usage_id) {
      status.textContent = 'Use recorded, entry #' + reservation.usage_id + '.';
    } else if (end < new Date()) {
      status.textContent = 'Finished. Use not recorded yet.';
    } else if (canChange(reservation)) {
      status.textContent = 'Yours. Drag to move, drag an edge to resize.';
    } else {
      status.textContent = 'An administrator can move or cancel this.';
    }
    tip.appendChild(status);

    var box = tip.getBoundingClientRect();
    var x = Math.min(clientX + 14, window.innerWidth - box.width - 8);
    var y = clientY + 18 + box.height > window.innerHeight ? clientY - box.height - 12 : clientY + 18;
    tip.style.left = Math.max(8, x) + 'px';
    tip.style.top = Math.max(8, y) + 'px';
  }

  function hideTip() {
    if (tip && tip.parentNode) { tip.parentNode.removeChild(tip); }
    tip = null;
  }

  /* --- modal ------------------------------------------------------------- */

  function openModal(options) {
    var backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';

    var modal = document.createElement('form');
    modal.className = 'modal';

    var heading = document.createElement('h2');
    heading.textContent = options.title;
    modal.appendChild(heading);

    if (options.when) {
      var when = document.createElement('p');
      when.className = 'modal-when';
      when.textContent = options.when;
      modal.appendChild(when);
    }

    var body = document.createElement('div');
    body.innerHTML = options.bodyHtml || '';
    modal.appendChild(body);

    var actions = document.createElement('div');
    actions.className = 'form-actions';

    var confirm = document.createElement('button');
    confirm.type = 'submit';
    confirm.className = 'button';
    confirm.textContent = options.confirmLabel || 'Save';

    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'button button-secondary';
    cancel.textContent = 'Cancel';

    actions.appendChild(confirm);
    actions.appendChild(cancel);
    modal.appendChild(actions);
    backdrop.appendChild(modal);
    document.body.appendChild(backdrop);

    function close() {
      if (backdrop.parentNode) { backdrop.parentNode.removeChild(backdrop); }
      document.removeEventListener('keydown', onKey, true);
    }

    function onKey(event) {
      if (event.key === 'Escape') { event.stopPropagation(); close(); }
    }

    cancel.addEventListener('click', close);
    backdrop.addEventListener('mousedown', function (event) {
      if (event.target === backdrop) { close(); }
    });
    document.addEventListener('keydown', onKey, true);

    modal.addEventListener('submit', function (event) {
      event.preventDefault();
      confirm.disabled = true;
      var result = options.onConfirm(modal);
      Promise.resolve(result).then(function (keepOpen) {
        if (keepOpen !== true) { close(); }
        confirm.disabled = false;
      }, function () {
        confirm.disabled = false;
      });
    });

    var first = modal.querySelector('input, textarea, select');
    if (first) { first.focus(); }
    return { close: close, element: modal };
  }

  /* --- creating ---------------------------------------------------------- */

  function confirmCreate(day, startSlot, endSlot) {
    var date = dayDate(day);

    openModal({
      title: 'Book this time',
      when: prettyDay(date) + ', ' + slotLabel(startSlot) + ' to ' + slotLabel(endSlot),
      bodyHtml:
        '<div class="field"><label for="mHolder">Booked by</label>' +
        '<input type="text" id="mHolder" name="holder" value="' + escapeAttr(state.me) + '" readonly></div>' +
        '<div class="field"><label for="mPurpose">Purpose <span class="muted">(optional)</span></label>' +
        '<input type="text" id="mPurpose" name="purpose" placeholder="Shown when someone hovers the block."></div>',
      confirmLabel: 'Book it',
      onConfirm: function (form) {
        return post({
          action: 'create',
          equipment_id: state.equipmentId,
          start: slotToDateTime(day, startSlot),
          end: slotToDateTime(day, endSlot),
          purpose: form.querySelector('[name=purpose]').value
        }).then(function (result) {
          if (!result || !result.ok) { refused(result); return; }
          clearMessages();
          load();
        });
      }
    });
  }

  function escapeAttr(text) {
    return String(text).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  }

  /* --- the action bar for a selected block -------------------------------- */

  var actionBar = null;

  function clearActionBar(keepSelection) {
    if (actionBar && actionBar.parentNode) { actionBar.parentNode.removeChild(actionBar); }
    actionBar = null;
    if (!keepSelection) {
      state.selectedId = null;
      Array.prototype.slice.call(grid.querySelectorAll('.cal-block.selected')).forEach(function (el) {
        el.classList.remove('selected');
      });
    }
  }

  function select(id) {
    state.selectedId = id;
    Array.prototype.slice.call(grid.querySelectorAll('.cal-block')).forEach(function (el) {
      el.classList.toggle('selected', parseInt(el.getAttribute('data-id'), 10) === id);
    });
    showActionBar();
  }

  function showActionBar() {
    if (!messages) { return; }
    if (actionBar && actionBar.parentNode) { actionBar.parentNode.removeChild(actionBar); }

    var reservation = byId(state.selectedId);
    if (!reservation) { return; }

    var start = parseDT(reservation.start);
    var end = parseDT(reservation.end);
    var mine = canChange(reservation);

    actionBar = document.createElement('div');
    actionBar.className = 'cal-actionbar';

    var who = document.createElement('span');
    who.className = 'who';
    who.textContent = reservation.by || 'Booked';
    actionBar.appendChild(who);

    var when = document.createElement('span');
    when.className = 'when';
    when.textContent = prettyDay(start) + ', '
      + slotLabel(start.getHours() * 2 + (start.getMinutes() >= 30 ? 1 : 0)) + '–'
      + slotLabel(end.getHours() * 2 + (end.getMinutes() >= 30 ? 1 : 0))
      + (reservation.purpose ? ' · ' + reservation.purpose : '');
    actionBar.appendChild(when);

    var row = document.createElement('div');
    row.className = 'button-row';

    row.appendChild(button('Record use', function () {
      var url = 'index.php?equipment_id=' + reservation.equipment
        + '&use_date=' + encodeURIComponent(isoDate(start))
        + '&operator=' + encodeURIComponent(reservation.by)
        + '&reservation_id=' + reservation.id;
      window.location.href = url;
    }));

    if (mine) {
      row.appendChild(button('Repeat…', function () { openRepeat(reservation); }));
      row.appendChild(button('Copy', function () { copyBlock(reservation); }));
      row.appendChild(button('Cancel booking', function () { removeBlock(reservation); }, 'button-danger'));
    }
    row.appendChild(button('Done', function () { clearActionBar(); }));

    actionBar.appendChild(row);
    messages.insertBefore(actionBar, messages.firstChild);
  }

  function button(label, onClick, extraClass) {
    var el = document.createElement('button');
    el.type = 'button';
    el.className = 'button button-small ' + (extraClass || 'button-secondary');
    el.textContent = label;
    el.addEventListener('click', onClick);
    return el;
  }

  /* --- repeat, copy, delete ---------------------------------------------- */

  function openRepeat(reservation) {
    var start = parseDT(reservation.start);
    var end = parseDT(reservation.end);
    var ownDay = start.getDay();

    var boxes = DAY_SHORT.map(function (name, index) {
      return '<label><input type="checkbox" name="days" value="' + index + '"'
        + (index === ownDay ? ' checked' : '') + '> ' + name + '</label>';
    }).join('');

    openModal({
      title: 'Repeat across the week',
      when: slotLabel(start.getHours() * 2 + (start.getMinutes() >= 30 ? 1 : 0)) + ' to '
        + slotLabel(end.getHours() * 2 + (end.getMinutes() >= 30 ? 1 : 0))
        + ', on the days you choose in the week shown.',
      bodyHtml: '<div class="field"><label>Days</label><div class="weekday-picker">' + boxes + '</div>'
        + '<p class="hint">Days that clash with an existing booking are skipped and named afterwards.</p></div>',
      confirmLabel: 'Create bookings',
      onConfirm: function (form) {
        var days = Array.prototype.slice.call(form.querySelectorAll('[name=days]:checked'))
          .map(function (box) { return box.value; });

        if (!days.length) { return true; }

        return post({
          action: 'repeat',
          equipment_id: state.equipmentId,
          week_start: state.weekStart,
          start: reservation.start,
          end: reservation.end,
          purpose: reservation.purpose,
          days: days
        }).then(function (result) {
          if (!result || !result.ok) { refused(result); return; }
          var made = (result.reservations || []).length;
          var skipped = result.skipped || [];
          say(made + ' booking' + (made === 1 ? '' : 's') + ' created.'
            + (skipped.length ? ' Skipped ' + skipped.join(', ') + '.' : ''),
            skipped.length ? 'notice' : 'success');
          load();
        });
      }
    });
  }

  function copyBlock(reservation) {
    var start = parseDT(reservation.start);
    var end = parseDT(reservation.end);
    var slots = Math.max(1, Math.round((end - start) / 1800000));

    state.clipboard = { slots: slots, purpose: reservation.purpose };
    say('Copied a ' + (slots / 2) + ' hour block. Point at a free slot and press Ctrl+V, or use paste from the keyboard.', 'success');
  }

  function pasteAt(day, slot) {
    if (!state.clipboard) { return; }
    var endSlot = Math.min(SLOTS_PER_DAY, slot + state.clipboard.slots);
    if (endSlot <= slot) { return; }

    post({
      action: 'create',
      equipment_id: state.equipmentId,
      start: slotToDateTime(day, slot),
      end: slotToDateTime(day, endSlot),
      purpose: state.clipboard.purpose || ''
    }).then(function (result) {
      if (!result || !result.ok) { refused(result); return; }
      clearMessages();
      load();
    });
  }

  function removeBlock(reservation) {
    if (!window.confirm('Cancel this booking?')) { return; }
    post({ action: 'delete', reservation_id: reservation.id }).then(function (result) {
      if (!result || !result.ok) { refused(result); return; }
      clearActionBar();
      clearMessages();
      load();
    });
  }

  /* --- dragging ----------------------------------------------------------- */

  var drag = null;

  grid.addEventListener('pointerdown', function (event) {
    if (event.button !== 0 && event.pointerType === 'mouse') { return; }
    if (!state.equipmentId) { return; }

    var blockEl = event.target.closest ? event.target.closest('.cal-block') : null;
    var cell = pointToCell(event.clientX, event.clientY);

    if (blockEl) {
      var reservation = byId(parseInt(blockEl.getAttribute('data-id'), 10));
      if (!reservation) { return; }

      select(reservation.id);

      if (!canChange(reservation)) { return; }

      var grip = event.target.getAttribute && event.target.getAttribute('data-grip');
      var start = parseDT(reservation.start);
      var end = parseDT(reservation.end);
      var startSlot = start.getHours() * 2 + (start.getMinutes() >= 30 ? 1 : 0);
      var lengthSlots = Math.max(1, Math.round((end - start) / 1800000));

      drag = {
        mode: grip ? ('resize-' + grip) : 'move',
        reservation: reservation,
        grabSlot: cell.slot,
        startSlot: startSlot,
        lengthSlots: lengthSlots,
        day: cell.day,
        moved: false
      };
      blockEl.classList.add('dragging');
      grid.setPointerCapture(event.pointerId);
      event.preventDefault();
      return;
    }

    if (event.target.classList && event.target.classList.contains('cal-cell')) {
      clearActionBar();
      drag = {
        mode: 'create',
        day: cell.day,
        anchorSlot: cell.slot,
        currentSlot: cell.slot,
        moved: false
      };
      showGhost(cell.day, cell.slot, cell.slot + 1);
      grid.setPointerCapture(event.pointerId);
      event.preventDefault();
    }
  });

  grid.addEventListener('pointermove', function (event) {
    var cell = pointToCell(event.clientX, event.clientY);
    state.hover = cell;

    if (!drag) {
      var blockEl = event.target.closest ? event.target.closest('.cal-block') : null;
      if (blockEl) {
        var reservation = byId(parseInt(blockEl.getAttribute('data-id'), 10));
        if (reservation) { showTip(reservation, event.clientX, event.clientY); return; }
      }
      hideTip();
      return;
    }

    hideTip();
    drag.moved = true;

    if (drag.mode === 'create') {
      drag.currentSlot = cell.slot;
      var from = Math.min(drag.anchorSlot, drag.currentSlot);
      var to = Math.max(drag.anchorSlot, drag.currentSlot) + 1;
      showGhost(drag.day, from, to);
      return;
    }

    if (drag.mode === 'move') {
      var offset = cell.slot - drag.grabSlot;
      var newStart = clamp(drag.startSlot + offset, 0, SLOTS_PER_DAY - drag.lengthSlots);
      showGhost(cell.day, newStart, newStart + drag.lengthSlots);
      return;
    }

    if (drag.mode === 'resize-start') {
      var top = clamp(cell.slot, 0, drag.startSlot + drag.lengthSlots - 1);
      showGhost(drag.day, top, drag.startSlot + drag.lengthSlots);
      return;
    }

    if (drag.mode === 'resize-end') {
      var bottom = clamp(cell.slot + 1, drag.startSlot + 1, SLOTS_PER_DAY);
      showGhost(drag.day, drag.startSlot, bottom);
    }
  });

  function endDrag(event) {
    if (!drag) { return; }

    var current = drag;
    drag = null;
    hideGhost();

    Array.prototype.slice.call(grid.querySelectorAll('.cal-block.dragging')).forEach(function (el) {
      el.classList.remove('dragging');
    });

    try { grid.releasePointerCapture(event.pointerId); } catch (ignored) {}

    var cell = pointToCell(event.clientX, event.clientY);

    if (current.mode === 'create') {
      var from = Math.min(current.anchorSlot, current.currentSlot);
      var to = Math.max(current.anchorSlot, current.currentSlot) + 1;
      confirmCreate(current.day, from, to);
      return;
    }

    if (!current.moved) { return; }

    var reservation = current.reservation;
    var newStartSlot;
    var newEndSlot;
    var targetDay = current.day;

    if (current.mode === 'move') {
      var offset = cell.slot - current.grabSlot;
      newStartSlot = clamp(current.startSlot + offset, 0, SLOTS_PER_DAY - current.lengthSlots);
      newEndSlot = newStartSlot + current.lengthSlots;
      targetDay = cell.day;
    } else if (current.mode === 'resize-start') {
      newStartSlot = clamp(cell.slot, 0, current.startSlot + current.lengthSlots - 1);
      newEndSlot = current.startSlot + current.lengthSlots;
    } else {
      newStartSlot = current.startSlot;
      newEndSlot = clamp(cell.slot + 1, current.startSlot + 1, SLOTS_PER_DAY);
    }

    var newStart = slotToDateTime(targetDay, newStartSlot);
    var newEnd = slotToDateTime(targetDay, newEndSlot);

    if (newStart === reservation.start && newEnd === reservation.end) { return; }

    post({
      action: 'update',
      reservation_id: reservation.id,
      start: newStart,
      end: newEnd
    }).then(function (result) {
      if (!result || !result.ok) { refused(result); return; }
      clearMessages();
      load().then(function () {
        if (state.selectedId === reservation.id) { showActionBar(); }
      });
    });
  }

  grid.addEventListener('pointerup', endDrag);

  // Pointer capture normally keeps the release on the grid, but a pointer let
  // go outside the window, or a capture the browser takes back, would otherwise
  // leave a drag half finished and a ghost stranded on the grid. Finishing on
  // the document as well costs nothing: endDrag clears the drag before it does
  // any work, so the second call it sometimes receives returns immediately.
  document.addEventListener('pointerup', endDrag);

  function cancelDrag(event) {
    drag = null;
    hideGhost();
    Array.prototype.slice.call(grid.querySelectorAll('.cal-block.dragging')).forEach(function (el) {
      el.classList.remove('dragging');
    });
    if (event) {
      try { grid.releasePointerCapture(event.pointerId); } catch (ignored) {}
    }
  }

  grid.addEventListener('pointercancel', cancelDrag);
  document.addEventListener('pointercancel', cancelDrag);
  window.addEventListener('blur', function () { cancelDrag(null); });

  grid.addEventListener('pointerleave', function () {
    hideTip();
    state.hover = null;
  });

  /* --- keyboard ------------------------------------------------------------ */

  document.addEventListener('keydown', function (event) {
    if (document.querySelector('.modal-backdrop')) { return; }

    var tag = (event.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') { return; }

    var reservation = state.selectedId ? byId(state.selectedId) : null;

    if (event.key === 'Escape') {
      clearActionBar();
      hideTip();
      return;
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'c' && reservation) {
      event.preventDefault();
      copyBlock(reservation);
      return;
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'v' && state.clipboard) {
      event.preventDefault();
      if (state.hover) {
        pasteAt(state.hover.day, state.hover.slot);
      } else {
        say('Point at the slot where the copy should start, then press Ctrl+V.', 'notice');
      }
      return;
    }

    if ((event.key === 'Delete' || event.key === 'Backspace') && reservation && canChange(reservation)) {
      event.preventDefault();
      removeBlock(reservation);
    }
  });

  /* --- navigation ----------------------------------------------------------- */

  function goToWeek(iso) {
    state.weekStart = iso;
    updateUrl();
    load();
  }

  function updateUrl() {
    if (!window.history || !window.history.replaceState) { return; }
    window.history.replaceState({}, '',
      'schedule.php?equipment_id=' + state.equipmentId + '&week=' + state.weekStart);
  }

  Array.prototype.slice.call(document.querySelectorAll('[data-nav]')).forEach(function (el) {
    el.addEventListener('click', function () {
      var move = el.getAttribute('data-nav');
      if (move === 'today') {
        var now = new Date();
        now.setDate(now.getDate() - now.getDay());
        goToWeek(isoDate(now));
        return;
      }
      var base = parseDate(state.weekStart);
      base.setDate(base.getDate() + (move === 'next' ? 7 : -7));
      goToWeek(isoDate(base));
    });
  });

  if (picker) {
    picker.addEventListener('change', function () {
      state.equipmentId = parseInt(picker.value, 10) || 0;
      state.selectedId = null;
      clearActionBar();
      updateUrl();
      load();
    });
  }

  window.addEventListener('resize', function () { hideTip(); });

  load();
}());
