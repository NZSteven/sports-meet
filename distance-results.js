(() => {
  'use strict';
  function init() {
    document.querySelectorAll('[data-final-project-select]').forEach(select => {
      select.addEventListener('change', () => { if (select.value) select.form.requestSubmit(); });
    });
    document.querySelectorAll('[data-distance-table]').forEach(table => {
      const rows = Array.from(table.querySelectorAll('tbody tr')).map(tr => ({
        tr, yes: tr.querySelector('input[name$="[confirmed]"]'), mark: tr.querySelector('[data-distance]'),
        tie: tr.querySelector('[data-tie-order]'), out: tr.querySelector('[data-auto-place]')
      })).filter(r => r.mark);
      if (!rows.length) return;
      const form = table.closest('form');
      const status = document.createElement('p'); status.className = 'race-distance-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); table.parentNode.after(status);
      let errors = [];
      function update() {
        errors = []; const valid = [];
        rows.forEach(r => {
          r.out.textContent = '—'; r.tr.classList.remove('race-distance-tied'); r.tie.disabled = true;
          r.mark.setCustomValidity(''); r.tie.setCustomValidity('');
          if (!r.yes.checked) return;
          const raw = r.mark.value.trim();
          if (!/^\d{1,5}(\.\d{1,4})?$/.test(raw) || Number(raw) < 0) { errors.push({input:r.mark,message:'已确认参赛者须填写距离；无有效距离请填0。'}); return; }
          if (Number(raw) === 0) return;
          const parts = raw.split('.'); const units = Number(parts[0])*10000 + Number((parts[1]||'').padEnd(4,'0'));
          valid.push({row:r,units});
        });
        valid.sort((a,b) => b.units-a.units);
        for (let i=0;i<valid.length;) {
          let j=i+1;while(j<valid.length && valid[j].units===valid[i].units)j++;
          const group=valid.slice(i,j);
          if (group.length>1 && i<3) {
            group.forEach(x=>{x.row.tie.disabled=false;x.row.tr.classList.add('race-distance-tied');});
            const values=group.map(x=>x.row.tie.value.trim());
            const orders=values.map(Number).sort((a,b)=>a-b);
            if(!values.every(v=>/^\d+$/.test(v)) || !orders.every((v,k)=>v===k+1)) {
              errors.push({input:group[0].row.tie,message:`同距离影响前三名，请裁判填写同距顺序1至${group.length}。`});i=j;continue;
            }
            group.sort((a,b)=>Number(a.row.tie.value)-Number(b.row.tie.value));
          }
          group.forEach((x,k)=>{if(i+k<3)x.row.out.textContent=String(i+k+1);});i=j;
        }
        status.textContent = errors.length ? errors[0].message : '名次已按有效距离由大到小自动计算。暂存不公示，提交完成后计分。';
      }
      rows.forEach(r => {
        r.mark.addEventListener('input',()=>{rows.forEach(x=>x.tie.value='');update();});
        r.yes.addEventListener('change',update);
        r.tie.addEventListener('input',update);
      });
      form.addEventListener('submit',e=>{update();if(e.submitter && e.submitter.value==='finalize' && errors.length){e.preventDefault();const error=errors[0];error.input.setCustomValidity(error.message);error.input.reportValidity();error.input.focus();}});
      update();
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
