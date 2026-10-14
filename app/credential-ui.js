(function () {
  function getDialog() {
    let dialog = document.getElementById('credential-reveal-dialog');
    if (dialog) return dialog;
    dialog = document.createElement('dialog');
    dialog.id = 'credential-reveal-dialog';
    dialog.className = 'employee-dialog credential-reveal-dialog';
    dialog.innerHTML = '<div class="dialog-heading"><h2>Credential password</h2><button class="icon-button" type="button" aria-label="Close">×</button></div><p class="credential-secret" aria-live="polite"></p>';
    dialog.querySelector('button').addEventListener('click', function () { dialog.close(); });
    document.body.appendChild(dialog);
    return dialog;
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

  document.addEventListener('DOMContentLoaded', function () {
    const addDialog = document.getElementById('credential-create');
    if (addDialog) {
      const close = addDialog.querySelector('[data-close-credential]');
      if (close) close.addEventListener('click', function () { addDialog.close(); });
      const employee = addDialog.querySelector('select[name="employee_id"]');
      if (employee) { filterSoftware(employee); employee.addEventListener('change', function () { filterSoftware(employee); }); }
      if (addDialog.dataset.autoOpen === '1') addDialog.showModal();
    }
    document.querySelectorAll('select[name="employee_id"]').forEach(function (employee) { filterSoftware(employee); employee.addEventListener('change', function () { filterSoftware(employee); }); });
  });

  document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-reveal-credential]');
    if (!button) return;
    button.disabled = true;
    try {
      const body = new URLSearchParams({ csrf: csrfToken(), credential_id: button.dataset.revealCredential });
      let response = await fetch('credential-reveal.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
      if (response.status === 428) {
        const password = window.prompt('Confirm your Inventory Manager password to reveal this credential.');
        if (password === null) return;
        body.set('reauth_password', password);
        response = await fetch('credential-reveal.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
      }
      if (!response.ok) {
        window.alert(response.status === 403 ? 'You are not authorized to reveal this credential.' : 'Unable to reveal credential.');
        return;
      }
      const result = await response.json();
      const dialog = getDialog();
      const output = dialog.querySelector('.credential-secret');
      output.textContent = result.password;
      dialog.showModal();
      window.setTimeout(function () { if (dialog.open) dialog.close(); output.textContent = ''; }, 30000);
    } catch (_) {
      window.alert('Unable to reveal credential.');
    } finally {
      button.disabled = false;
    }
  });
})();
