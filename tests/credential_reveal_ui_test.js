const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'app', 'credential-ui.js'), 'utf8');

function createHarness(responseFactory) {
  let now = 0;
  let nextTimerId = 1;
  let nextListenerId = 1;
  const timers = new Map();
  const calls = [];
  const alerts = [];

  function setTimer(callback, delay, interval) {
    const id = nextTimerId++;
    timers.set(id, { callback, at: now + delay, interval });
    return id;
  }

  function clearTimer(id) { timers.delete(id); }

  class FakeDate extends Date {
    static now() { return now; }
  }

  class Element {
    constructor() { this.listeners = new Map(); this.textContent = ''; this.open = false; this.disabled = false; this.dataset = {}; }
    addEventListener(name, callback) {
      if (!this.listeners.has(name)) this.listeners.set(name, []);
      this.listeners.get(name).push(callback);
    }
    dispatch(name, event = {}) {
      for (const callback of this.listeners.get(name) || []) callback({ target: this, preventDefault() { this.defaultPrevented = true; }, ...event });
    }
    querySelector(selector) { return this.parts[selector] || null; }
    showModal() { this.open = true; }
    close() { this.open = false; this.dispatch('close'); }
  }

  class Dialog extends Element {
    constructor() {
      super();
      this.parts = { button: new Element(), '.credential-secret': new Element(), '.credential-reveal-countdown': new Element() };
    }
  }

  const document = {
    hidden: false,
    listeners: new Map(),
    body: { appendChild(element) { document.dialog = element; } },
    addEventListener(name, callback) {
      if (!this.listeners.has(name)) this.listeners.set(name, []);
      this.listeners.get(name).push(callback);
    },
    dispatch(name, event = {}) { for (const callback of this.listeners.get(name) || []) callback(event); },
    createElement() { return new Dialog(); },
    getElementById(id) { return id === 'credential-reveal-dialog' ? this.dialog || null : null; },
    querySelector(selector) { return selector === 'input[name="csrf"]' ? { value: 'csrf-token' } : null; },
  };

  const button = { dataset: { revealCredential: '17' }, disabled: false };
  const context = {
    document,
    Date: FakeDate,
    URLSearchParams,
    AbortController,
    fetch: async (url, options) => { calls.push({ url, options }); return responseFactory(); },
    window: {
      setTimeout: (callback, delay) => setTimer(callback, delay, 0),
      clearTimeout: clearTimer,
      setInterval: (callback, delay) => setTimer(callback, delay, delay),
      clearInterval: clearTimer,
      prompt: () => null,
      alert: (message) => alerts.push(message),
    },
  };
  vm.runInNewContext(source, context, { filename: 'credential-ui.js' });

  return {
    document, button, calls, alerts, timers,
    async reveal() {
      const [handler] = document.listeners.get('click');
      await handler({ target: { closest: (selector) => selector === '[data-reveal-credential]' ? button : null } });
    },
    advance(milliseconds) {
      const end = now + milliseconds;
      while (true) {
        let selectedId = null;
        let selected = null;
        for (const [id, timer] of timers) if (timer.at <= end && (!selected || timer.at < selected.at)) { selectedId = id; selected = timer; }
        if (!selected) break;
        now = selected.at;
        if (!selected.interval) timers.delete(selectedId);
        selected.callback();
        if (selected.interval && timers.has(selectedId)) selected.at = now + selected.interval;
      }
      now = end;
    },
  };
}

function assert(condition, message) { if (!condition) throw new Error(message); }
const success = () => ({ ok: true, status: 200, async json() { return { password: 'display-only-secret' }; } });

(async () => {
  const expiry = createHarness(success);
  await expiry.reveal();
  const dialog = expiry.document.dialog;
  const output = dialog.querySelector('.credential-secret');
  const countdown = dialog.querySelector('.credential-reveal-countdown');
  assert(dialog.open && output.textContent === 'display-only-secret', 'An authorized response should open the reveal dialog.');
  assert(countdown.textContent === 'Password disappears in 10 seconds', 'The countdown should start at ten seconds.');
  assert(expiry.calls.length === 1 && expiry.calls[0].options.cache === 'no-store', 'Every reveal should use a fresh no-store request.');
  expiry.advance(5000);
  assert(countdown.textContent === 'Password disappears in 5 seconds', 'The countdown should show the remaining time.');
  expiry.advance(5000);
  assert(!dialog.open && output.textContent === '' && countdown.textContent === '', 'Expiry should close the dialog and clear plaintext.');
  assert(expiry.timers.size === 0, 'Expiry should cancel its timers.');

  const fresh = createHarness(success);
  await fresh.reveal();
  fresh.advance(5000);
  await fresh.reveal();
  assert(fresh.calls.length === 2 && fresh.document.dialog.querySelector('.credential-reveal-countdown').textContent === 'Password disappears in 10 seconds', 'A second reveal must make a fresh request and restart the full countdown.');
  fresh.advance(9999);
  assert(fresh.document.dialog.open, 'The restarted reveal should remain visible until its full ten-second window expires.');
  fresh.advance(1);
  assert(!fresh.document.dialog.open && fresh.document.dialog.querySelector('.credential-secret').textContent === '', 'The restarted reveal should clear when its own deadline expires.');

  const manual = createHarness(success);
  await manual.reveal();
  const manualDialog = manual.document.dialog;
  manualDialog.close();
  assert(!manualDialog.open && manualDialog.querySelector('.credential-secret').textContent === '', 'Manual close should clear plaintext immediately.');
  assert(manual.timers.size === 0, 'Manual close should cancel countdown timers.');

  const hidden = createHarness(success);
  await hidden.reveal();
  hidden.document.hidden = true;
  hidden.document.dispatch('visibilitychange');
  assert(!hidden.document.dialog.open && hidden.document.dialog.querySelector('.credential-secret').textContent === '', 'A hidden tab should close the dialog and clear plaintext.');
  assert(hidden.timers.size === 0, 'A hidden tab should cancel countdown timers.');

  const denied = createHarness(() => ({ ok: false, status: 403, async json() { return { error: 'denied' }; } }));
  await denied.reveal();
  assert(denied.calls.length === 1 && !denied.document.dialog.open, 'An unauthorized response must not display a password.');
  assert(denied.alerts.includes('You are not authorized to reveal this credential.'), 'An unauthorized reveal should report the denial.');

  const restrictions = manual.document.dialog;
  for (const eventName of ['copy', 'cut', 'contextmenu', 'dragstart', 'selectstart']) {
    let prevented = false;
    restrictions.dispatch(eventName, { preventDefault() { prevented = true; } });
    assert(prevented, `${eventName} should be blocked inside the reveal dialog.`);
  }
  console.log('PASS: credential reveal expiry, manual close, hidden-tab cleanup, denied requests, and interaction deterrents');
})().catch((error) => { console.error(error); process.exitCode = 1; });
