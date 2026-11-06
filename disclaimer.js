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
    if (!school || !athlete) return;
    const [placeholder, ...options] = athlete.options;

    function filter() {
        athlete.replaceChildren(placeholder, ...options.filter(option => option.dataset.school === school.value));
        athlete.value = '';
        athlete.disabled = !school.value;
    }
    school.addEventListener('change', filter);
    filter();
});
