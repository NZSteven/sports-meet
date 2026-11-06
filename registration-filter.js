(() => {
  'use strict';
  function start() {
    document.querySelectorAll('select[name="relay_school"]').forEach(school => {
      const form = school.form;
      const players = form.querySelector('[data-relay-athletes]');
      const bibs = form.querySelector('[name="bibs"]');
      function updateRelay() {
        players.hidden = !school.value;
        bibs.closest('label').hidden = !!school.value;
        bibs.disabled = !!school.value;
        bibs.required = !school.value;
        players.querySelectorAll('[data-relay-school]').forEach(label => {
          label.hidden = label.dataset.relaySchool !== school.value;
          label.querySelector('input').disabled = label.hidden;
        });
      }
      school.addEventListener('change', updateRelay);
      updateRelay();
    });
    document.querySelectorAll('.race-event-picker').forEach(picker => {
      const form = picker.closest('form');
      const group = form && form.querySelector('[name="category"]');
      if (!group) return;
      const status = picker.querySelector('.race-filter-status');
      const choices = Array.from(picker.querySelectorAll('[data-race-categories]'));
      const buttons = form.querySelectorAll('button[type="submit"],button:not([type])');
      function update() {
        let count = 0, conflict = false;
        choices.forEach(choice => {
          const input = choice.querySelector('input[type="checkbox"]');
          const locked = choice.dataset.raceLocked === '1';
          let allowed = [];
          try { allowed = JSON.parse(choice.dataset.raceCategories); } catch (_) { /* Fail closed. */ }
          const eligible = Array.isArray(allowed) && allowed.includes(group.value);
          if (eligible) count++;
          if (locked) {
            input.disabled = true;
            choice.hidden = !eligible && !input.checked;
            if (!eligible && input.checked) conflict = true;
          } else {
            choice.hidden = !eligible;
            input.disabled = !eligible;
            if (!eligible) input.checked = false;
          }
        });
        status.textContent = conflict
          ? '已有锁定的参赛项目不适用于当前组别，请恢复原组别或联系管理员核对。'
          : !group.value ? '请先选择组别，再勾选参赛项目。'
          : count ? `当前组别可选 ${count} 个项目；不适用项目已隐藏。`
          : '此组别暂时没有可报名项目，请联系管理员添加。';
        buttons.forEach(button => { button.disabled = conflict || !group.value || count === 0; });
      }
      group.addEventListener('change', update);
      form.addEventListener('reset', () => setTimeout(update, 0));
      update();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
