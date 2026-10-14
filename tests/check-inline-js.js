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
  .map((match) => match[1]).find((script) => script.includes('[data-hardware-state]'));
if (!hardwareScript) throw new Error('Hardware toggle script was not found');
const makeButton = (state) => {
  const button = {
    dataset: { hardwareState: state }, attributes: {}, classes: new Set(),
    addEventListener(name, callback) { if (name === 'click') this.handler = callback; },
    setAttribute(name, value) { this.attributes[name] = value; }, click: null,
  };
  button.classList = { toggle(name, enabled) { enabled ? button.classes.add(name) : button.classes.delete(name); } };
  return button;
};
const buttons = [makeButton('register'), makeButton('assign')];
const makeForm = () => ({ hidden: true, querySelector: () => ({ focus() {} }) });
const forms = { register: makeForm(), assign: makeForm() };
const assetSelect = {
  selectedOptions: [{ dataset: { assetTag: 'TAG-001' } }],
  addEventListener(name, callback) { if (name === 'change') this.change = callback; },
};
const tagField = { value: '' };
const document = {
  querySelectorAll(selector) { return selector === '[data-hardware-state]' ? buttons : []; },
  getElementById(id) {
    return ({ 'register-hardware-form': forms.register, 'assign-hardware-form': forms.assign,
      'new-asset': assetSelect, 'selected-asset-tag': tagField })[id] || null;
  },
};
const trigger = (button) => button.handler();
vm.runInNewContext(hardwareScript, { document });
trigger(buttons[0]);
if (forms.register.hidden || !forms.assign.hidden || !buttons[0].classes.has('active')) throw new Error('Register did not open exclusively');
trigger(buttons[1]);
if (!forms.register.hidden || forms.assign.hidden || !buttons[1].classes.has('active')) throw new Error('Assign did not replace Register');
trigger(buttons[0]);
if (forms.register.hidden || !forms.assign.hidden || !buttons[0].classes.has('active')) throw new Error('Register did not replace Assign');
trigger(buttons[0]);
if (!forms.register.hidden || !forms.assign.hidden || buttons.some((button) => button.classes.has('active'))) throw new Error('Active form did not close');
assetSelect.change();
if (tagField.value !== 'TAG-001') throw new Error('Selected asset tag was not displayed');
console.log('PASS: hardware forms switch and close exclusively; selected asset tag is displayed');
