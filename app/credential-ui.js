(function () {
  const revealDurationMs = 10000;
  let expiryTimer = null;
  let countdownTimer = null;
  let revealDeadline = 0;
  let revealRequestVersion = 0;
  let revealRequestController = null;

  function getDialog() {
    let dialog = document.getElementById('credential-reveal-dialog');
    if (dialog) return dialog;
    dialog = document.createElement('dialog');
    dialog.id = 'credential-reveal-dialog';
    dialog.className = 'employee-dialog credential-reveal-dialog';
    dialog.innerHTML = '<div class="dialog-heading"><h2>Credential password</h2><button class="icon-button" type="button" aria-label="Close">×</button></div><p class="credential-secret" aria-live="polite" draggable="false"></p><p class="credential-reveal-countdown" role="status" aria-live="polite"></p>';
    dialog.querySelector('button').addEventListener('click', function () { closeReveal(dialog); });
    dialog.addEventListener('close', clearReveal);
    ['copy', 'cut', 'contextmenu', 'dragstart', 'selectstart'].forEach(function (eventName) {
      dialog.addEventListener(eventName, function (event) {
        if (event.preventDefault) event.preventDefault();
      });
    });
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) closeReveal(dialog);
    });
    document.body.appendChild(dialog);
    return dialog;
  }

  function clearReveal() {
    if (expiryTimer !== null) window.clearTimeout(expiryTimer);
    if (countdownTimer !== null) window.clearInterval(countdownTimer);
    expiryTimer = null;
    countdownTimer = null;
    revealDeadline = 0;
    const dialog = document.getElementById('credential-reveal-dialog');
    if (!dialog) return;
    const output = dialog.querySelector('.credential-secret');
    const countdown = dialog.querySelector('.credential-reveal-countdown');
    if (output) output.textContent = '';
    if (countdown) countdown.textContent = '';
  }

  function closeReveal(dialog) {
    revealRequestVersion++;
    if (revealRequestController) revealRequestController.abort();
    revealRequestController = null;
    clearReveal();
    if (dialog && dialog.open) dialog.close();
  }

  function updateCountdown(countdown) {
    const seconds = Math.max(0, Math.ceil((revealDeadline - Date.now()) / 1000));
    countdown.textContent = 'Password disappears in ' + seconds + (seconds === 1 ? ' second' : ' seconds');
    if (seconds === 0) closeReveal(document.getElementById('credential-reveal-dialog'));
  }

  function showReveal(dialog, output, countdown, password) {
    clearReveal();
    output.textContent = password;
    revealDeadline = Date.now() + revealDurationMs;
    dialog.showModal();
    updateCountdown(countdown);
    countdownTimer = window.setInterval(function () { updateCountdown(countdown); }, 250);
    expiryTimer = window.setTimeout(function () { closeReveal(dialog); }, revealDurationMs);
  }

  function csrfToken() {
    const input = document.querySelector('input[name="csrf"]');
    return input ? input.value : '';
  }


  function filterSoftware(employeeSelect) {
    if (!employeeSelect) return;
    const form = employeeSelect.closest('form');
    const softwareSelect = form && form.querySelector('select[name="software_installation_id"]');
    if (!softwareSelect) return;
    const employeeId = employeeSelect.value;
    Array.from(softwareSelect.options).forEach(function (option) {
      if (!option.value) { option.hidden = false; return; }
      option.hidden = employeeId !== '' && option.dataset.employee !== employeeId;
      if (option.hidden && option.selected) softwareSelect.value = '';
    });
  }

  function bindDepartmentDisplay(employeeSelect) {
    if (!employeeSelect) return;
    const form = employeeSelect.closest('form');
    const departmentDisplay = form && form.querySelector('input[id$="-department-display"]');
    if (!departmentDisplay) return;
    const updateDepartment = function () {
      const selected = employeeSelect.options[employeeSelect.selectedIndex];
      const departmentName = selected ? (selected.dataset.departmentName || '').trim() : '';
      departmentDisplay.value = departmentName || 'No department assigned';
    };
    updateDepartment();
    employeeSelect.addEventListener('change', updateDepartment);
  }

  function bindCredentialHistoryDetails() {
    const activeAnimations = new WeakMap();
    document.querySelectorAll('.credentials-page .credential-secondary details').forEach(function (details) {
      const summary = details.querySelector(':scope > summary');
      const content = details.querySelector('.credential-detail-grid');
      if (!summary || !content || typeof content.animate !== 'function') return;
      summary.addEventListener('click', function (event) {
        event.preventDefault();
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
          details.open = !details.open;
          return;
        }
        const previous = activeAnimations.get(details);
        const shouldOpen = previous && previous.closing ? true : !details.open;
        if (previous) previous.animation.cancel();
        if (shouldOpen) {
          details.open = true;
          const animation = content.animate(
            [{ opacity: 0, transform: 'translateY(-5px)' }, { opacity: 1, transform: 'translateY(0)' }],
            { duration: 220, easing: 'ease-out' }
          );
          activeAnimations.set(details, { animation: animation, closing: false });
          animation.onfinish = function () { activeAnimations.delete(details); };
          return;
        }
        const animation = content.animate(
          [{ opacity: 1, transform: 'translateY(0)' }, { opacity: 0, transform: 'translateY(-5px)' }],
          { duration: 180, easing: 'ease-in' }
        );
        activeAnimations.set(details, { animation: animation, closing: true });
        animation.onfinish = function () {
          details.open = false;
          activeAnimations.delete(details);
        };
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindCredentialHistoryDetails();
    const addDialog = document.getElementById('credential-create');
    if (addDialog) {
      const close = addDialog.querySelector('[data-close-credential]');
      if (close) close.addEventListener('click', function () { addDialog.close(); });
      const employee = addDialog.querySelector('select[name="employee_id"]');
      if (employee) { filterSoftware(employee); employee.addEventListener('change', function () { filterSoftware(employee); }); }
      if (addDialog.dataset.autoOpen === '1') addDialog.showModal();
    }
    document.querySelectorAll('select[name="employee_id"]').forEach(function (employee) { filterSoftware(employee); employee.addEventListener('change', function () { filterSoftware(employee); }); bindDepartmentDisplay(employee); });
  });

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) closeReveal(document.getElementById('credential-reveal-dialog'));
  });

  document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-reveal-credential]');
    if (!button) return;
    const dialog = getDialog();
    closeReveal(dialog);
    const requestVersion = revealRequestVersion;
    const requestController = new AbortController();
    revealRequestController = requestController;
    button.disabled = true;
    let body = null;
    let result = null;
    try {
      body = new URLSearchParams({ csrf: csrfToken(), credential_id: button.dataset.revealCredential });
      let response = await fetch('credential-reveal.php', { method: 'POST', cache: 'no-store', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body, signal: requestController.signal });
      if (response.status === 428) {
        const password = window.prompt('Confirm your Inventory Manager password to reveal this credential.');
        if (password === null) return;
        body.set('reauth_password', password);
        response = await fetch('credential-reveal.php', { method: 'POST', cache: 'no-store', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body, signal: requestController.signal });
      }
      if (requestVersion !== revealRequestVersion || document.hidden) return;
      if (!response.ok) {
        window.alert(response.status === 403 ? 'You are not authorized to reveal this credential.' : 'Unable to reveal credential.');
        return;
      }
      result = await response.json();
      const output = dialog.querySelector('.credential-secret');
      const countdown = dialog.querySelector('.credential-reveal-countdown');
      showReveal(dialog, output, countdown, String(result.password ?? ''));
    } catch (error) {
      if (!error || error.name !== 'AbortError') window.alert('Unable to reveal credential.');
    } finally {
      if (body) body.delete('reauth_password');
      if (result && Object.prototype.hasOwnProperty.call(result, 'password')) result.password = '';
      if (requestVersion === revealRequestVersion) revealRequestController = null;
      button.disabled = false;
    }
  });
})();
