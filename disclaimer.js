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
    function syncName() {
        if (signature) signature.value = athlete?.selectedOptions[0]?.dataset.name || '';
    }
    if (!school || !athlete) {
        const bound = form.querySelector('input[name=athlete_id]');
        if (signature && bound?.dataset.name && !signature.value) signature.value = bound.dataset.name;
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
