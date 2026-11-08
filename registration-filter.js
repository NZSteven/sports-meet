(() => {
  'use strict';
  function start() {
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
            choice.hidden = false;
            if (!eligible && input.checked) conflict = true;
          } else {
            choice.hidden = false;
            input.disabled = !eligible;
            if (!eligible) input.checked = false;
          }
        });
        status.textContent = conflict
          ? '已有锁定的参赛项目不适用于当前组别，请恢复原组别或联系管理员核对。'
          : !group.value ? '所有比赛项目如下，请先选择上方组别，再勾选适用项目。'
          : count ? `当前组别可选 ${count} 个项目；灰色项目不适用于当前组别。`
          : '此组别暂时没有可报名项目，请联系管理员添加。';
        buttons.forEach(button => { button.disabled = conflict || !group.value; });
      }
      group.addEventListener('change', update);
      form.addEventListener('reset', () => setTimeout(update, 0));
      update();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
