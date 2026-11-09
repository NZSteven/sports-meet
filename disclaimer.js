document.querySelectorAll('.sm-disclaimer-form').forEach(form => {
    const signer = form.querySelector('[name=signer_type]');
    const guardian = form.querySelector('[data-sm-guardian]');
    if (signer && guardian) {
        const updateGuardian = () => {
            const required = signer.value === 'guardian';
            guardian.hidden = !required;
            guardian.querySelectorAll('input').forEach(input => {
                input.disabled = !required;
                input.required = required;
            });
        };
        signer.addEventListener('change', updateGuardian);
        updateGuardian();
    }
    const school = form.querySelector('select[name=school_id]');
    const athlete = form.querySelector('select[name=athlete_id]');
    const signature = form.querySelector('[name=participant_signature]');
    const waiverFields = [...form.querySelectorAll('fieldset')];
    const waiverInputs = waiverFields.flatMap(fieldset => [...fieldset.querySelectorAll('input,select,textarea')]);
    waiverInputs.forEach(input => input.dataset.originalRequired = input.required ? '1' : '0');
    function updateExemption(selected) {
        const signed = selected?.dataset.waiverSigned === '1';
        const exempt = selected?.dataset.waiverExempt === '1' || (signed && form.querySelector('[name=action]')?.value === 'sports_meet_athlete_signup');
        waiverFields.forEach(fieldset => fieldset.hidden = exempt);
        waiverInputs.forEach(input => {input.disabled = exempt; input.required = !exempt && input.dataset.originalRequired === '1';});
        if(!exempt && signer) signer.dispatchEvent(new Event('change'));
        let notice = form.querySelector('[data-waiver-exempt-notice]');
        if(!notice){notice=document.createElement('p');notice.dataset.waiverExemptNotice='';notice.textContent='仅报名晚宴，无需签署参赛免责声明。';form.append(notice);}
        notice.textContent = signed ? '已完成当前免责声明签署，可直接注册账号。' : '仅报名晚宴，无需签署参赛免责声明。';
        notice.hidden=!exempt;
        const submit=form.querySelector('button[type=submit]');
        if(submit){if(!submit.dataset.originalText)submit.dataset.originalText=submit.textContent;const signup=form.querySelector('[name=action]')?.value === 'sports_meet_athlete_signup';submit.disabled=exempt&&!signup;submit.textContent=exempt?(signup?'注册账号':'无需签署'):submit.dataset.originalText;}
    }
    function syncName() {
        if (signature) signature.value = athlete?.selectedOptions[0]?.dataset.name || '';
        updateExemption(athlete?.selectedOptions[0]);
    }
    if (!school || !athlete) {
        const bound = form.querySelector('input[name=athlete_id]');
        if (signature && bound?.dataset.name && !signature.value) signature.value = bound.dataset.name;
        updateExemption(bound);
        return;
    }
    athlete.addEventListener('change', syncName);
    const [placeholder, ...options] = athlete.options;
    const initialAthlete = athlete.value;

    function filter() {
        athlete.replaceChildren(placeholder, ...options.filter(option => option.dataset.school === school.value));
        athlete.value = '';
        athlete.disabled = !school.value;
        syncName();
    }
    school.addEventListener('change', filter);
    filter();
    if (initialAthlete && Array.from(athlete.options).some(option => option.value === initialAthlete)) athlete.value = initialAthlete;
    syncName();
});
