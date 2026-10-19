const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const appDirectory = path.join(__dirname, '..', 'app');
let checked = 0;

for (const filename of fs.readdirSync(appDirectory).filter((name) => name.endsWith('.php'))) {
  const source = fs.readFileSync(path.join(appDirectory, filename), 'utf8');
  const scripts = source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi);
  let index = 0;
  for (const match of scripts) {
    new vm.Script(match[1], { filename: `${filename}:inline-script-${++index}` });
    checked++;
  }
}

console.log(`PASS: ${checked} inline JavaScript blocks parsed`);

const employeeSource = fs.readFileSync(path.join(appDirectory, 'employee.php'), 'utf8');
const hardwareScript = [...employeeSource.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)]
  .map((match) => match[1]).find((script) => script.includes("[data-hardware-state=\"assign\"]"));
if (!hardwareScript) throw new Error('Employee hardware assignment toggle script was not found');
const focusTarget = { focused: false, focus() { this.focused = true; } };
const form = { hidden: true, querySelector: () => focusTarget };
const button = { attributes: {}, handler: null,
  addEventListener(name, callback) { if (name === 'click') this.handler = callback; },
  setAttribute(name, value) { this.attributes[name] = value; } };
const document = {
  querySelector(selector) { return selector === '[data-hardware-state="assign"]' ? button : null; },
  getElementById(id) { return id === 'assign-hardware-form' ? form : null; },
};
vm.runInNewContext(hardwareScript, { document });
button.handler();
if (form.hidden || button.attributes['aria-expanded'] !== 'true' || !focusTarget.focused) throw new Error('Assign Hardware form did not open accessibly');
button.handler();
if (!form.hidden || button.attributes['aria-expanded'] !== 'false') throw new Error('Assign Hardware form did not close');
if (employeeSource.includes('hardware_register') || employeeSource.includes('availableAssets') || employeeSource.includes('Available hardware')) throw new Error('Employee Details still contains company-wide hardware registration or listing');
if (!employeeSource.includes('data-asset-picker') || !employeeSource.includes('hardware-assets.php?available_assets=1')) throw new Error('Employee assignment does not use Inventory asset search');
console.log('PASS: employee page only offers accessible assignment toggle and Inventory-backed asset search');

const usersSource = fs.readFileSync(path.join(appDirectory, 'users.php'), 'utf8');
const usersScript = [...usersSource.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)]
  .map((match) => match[1]).find((script) => script.includes('syncCredentialPermissions'));
if (!usersScript) throw new Error('User permission synchronization script was not found');
function eventNode(value = '') {
  return { value, listeners: {}, addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); },
    dispatch(name) { for (const callback of this.listeners[name] || []) callback(); } };
}
const accessMode = eventNode('role');
const userRole = eventNode('viewer');
const modeHelp = { textContent: '' };
const credentialPermissions = [
  { checked: false, disabled: true, dataset: { defaultViewer: '1' } },
  { checked: false, disabled: true, dataset: { defaultViewer: '0' } },
];
const generalPermissions = [{ disabled: true }, { disabled: true }];
const permissionMatrix = { querySelectorAll: () => generalPermissions };
const userDocument = {
  getElementById(id) { return ({ 'access-mode': accessMode, 'user-permission-matrix': permissionMatrix, 'edit-role': userRole, 'credential-access-mode-help': modeHelp })[id] || null; },
  querySelectorAll(selector) { return selector === '[data-credential-permission]' ? credentialPermissions : []; },
};
vm.runInNewContext(usersScript, { document: userDocument, window: {} });
if (!credentialPermissions[0].checked || credentialPermissions.some((input) => !input.disabled) || !modeHelp.textContent.includes('read-only')) {
  throw new Error('Role-default credential permissions should be visible and read-only');
}
accessMode.value = 'custom';
accessMode.dispatch('change');
if (credentialPermissions.some((input) => input.disabled) || generalPermissions.some((input) => input.disabled) || !modeHelp.textContent.includes('Customize')) {
  throw new Error('Custom Access did not enable both permission panels');
}
credentialPermissions[0].checked = false;
credentialPermissions[1].checked = true;
if (credentialPermissions[0].checked || !credentialPermissions[1].checked) throw new Error('Custom credential permissions could not be toggled individually');
accessMode.value = 'role';
accessMode.dispatch('change');
if (credentialPermissions.some((input) => !input.disabled) || !credentialPermissions[0].checked || credentialPermissions[1].checked) {
  throw new Error('Returning to role defaults did not restore read-only effective permissions');
}
console.log('PASS: user credential permissions switch from role-default read-only to individually editable Custom Access');
