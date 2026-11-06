document.querySelectorAll('.sm-disclaimer-form').forEach(form => {
    const school = form.querySelector('[name=school_id]');
    const athlete = form.querySelector('[name=athlete_id]');
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
