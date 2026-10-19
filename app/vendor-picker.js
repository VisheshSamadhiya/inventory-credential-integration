(function () {
  document.querySelectorAll('[data-vendor-picker]').forEach(function (picker) {
    const search = picker.querySelector('[data-vendor-search]');
    const hidden = picker.querySelector('[data-vendor-id]');
    const list = picker.querySelector('.vendor-suggestions');
    const options = Array.from(picker.querySelectorAll('.vendor-option'));
    const details = Array.from(picker.querySelectorAll('[data-vendor-detail]'));
    if (!picker.id) picker.id = 'vendor-picker-' + Array.from(document.querySelectorAll('[data-vendor-picker]')).indexOf(picker);
    let activeIndex = -1;
    let selectedName = hidden.value && search.value ? search.value : '';

    function visibleOptions() { return options.filter(function (option) { return !option.hidden; }); }
    function openList() { list.hidden = false; search.setAttribute('aria-expanded', 'true'); }
    function closeList() { list.hidden = true; search.setAttribute('aria-expanded', 'false'); search.removeAttribute('aria-activedescendant'); activeIndex = -1; options.forEach(function (option) { option.setAttribute('aria-selected', 'false'); }); }
    function select(option) {
      const vendor = JSON.parse(option.dataset.vendor);
      search.value = vendor.vendor_name;
      selectedName = vendor.vendor_name;
      hidden.value = vendor.id;
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
      details.forEach(function (field) { field.value = vendor[field.dataset.vendorDetail] || ''; });
      search.setCustomValidity('');
      closeList();
    }
    function clearSelection() {
      search.value = '';
      selectedName = '';
      hidden.value = '';
      details.forEach(function (field) { field.value = ''; });
      search.setCustomValidity('');
      closeList();
    }
    function filter() {
      const query = search.value.trim().toLocaleLowerCase();
      if (hidden.value && search.value !== selectedName) {
        hidden.value = '';
        selectedName = '';
        details.forEach(function (field) { field.value = ''; });
      }
      options.forEach(function (option) {
        const vendor = JSON.parse(option.dataset.vendor);
        const text = [vendor.vendor_name, vendor.contact_person, vendor.phone, vendor.email, vendor.gstin].join(' ').toLocaleLowerCase();
        option.hidden = query !== '' && !text.includes(query);
      });
      const visible = visibleOptions();
      const empty = picker.querySelector('.vendor-suggestion-empty');
      if (empty) empty.hidden = visible.length > 0;
      activeIndex = -1;
      openList();
    }
    function mark(index) {
      const visible = visibleOptions();
      if (!visible.length) return;
      activeIndex = (index + visible.length) % visible.length;
      visible.forEach(function (option, i) {
        option.setAttribute('aria-selected', String(i === activeIndex));
        if (i === activeIndex) {
          if (!option.id) option.id = picker.id + '-option-' + activeIndex;
          search.setAttribute('aria-activedescendant', option.id);
        }
      });
      visible[activeIndex].scrollIntoView({ block: 'nearest' });
    }

    search.addEventListener('focus', filter);
    search.addEventListener('input', function () { hidden.value = ''; selectedName = ''; details.forEach(function (field) { field.value = ''; }); filter(); });
    search.addEventListener('keydown', function (event) {
      const visible = visibleOptions();
      if (event.key === 'ArrowDown' && visible.length) { event.preventDefault(); if (list.hidden) openList(); mark(activeIndex + 1); }
      else if (event.key === 'ArrowUp' && visible.length) { event.preventDefault(); mark(activeIndex < 0 ? visible.length - 1 : activeIndex - 1); }
      else if (event.key === 'Enter' && !list.hidden) { const choice = visible[activeIndex] || visible[0]; if (choice) { event.preventDefault(); select(choice); } }
      else if (event.key === 'Escape' && !list.hidden) { event.preventDefault(); closeList(); }
    });
    options.forEach(function (option) {
      option.addEventListener('pointerdown', function (event) { event.preventDefault(); });
      option.addEventListener('click', function () { select(option); search.focus(); });
      option.addEventListener('mouseenter', function () { mark(visibleOptions().indexOf(option)); });
    });
    picker.addEventListener('vendor-picker:select', function (event) {
      const vendorId = Number(event.detail && event.detail.vendorId);
      const option = options.find(function (candidate) {
        try { return Number(JSON.parse(candidate.dataset.vendor).id) === vendorId; } catch (_) { return false; }
      });
      if (option) select(option); else clearSelection();
    });
    picker.closest('form')?.addEventListener('submit', function (event) {
      const valid = !search.required || hidden.value !== '';
      search.setCustomValidity(valid ? '' : 'Choose an active vendor from the suggestions.');
      if (!valid) { event.preventDefault(); search.reportValidity(); search.focus(); }
    });
    document.addEventListener('pointerdown', function (event) { if (!picker.contains(event.target)) closeList(); });
    const initialVendorId = Number(picker.dataset.initialVendorId || 0);
    if (initialVendorId > 0) {
      const initialOption = options.find(function (option) {
        try { return Number(JSON.parse(option.dataset.vendor).id) === initialVendorId; } catch (_) { return false; }
      });
      if (initialOption) select(initialOption);
    }
  });
})();
