document.querySelectorAll('.race-lineup-card').forEach(card => {
    const toggle = card.querySelector('.race-lineup-toggle');
    const content = card.querySelector('.race-lineup-scroll');
    if (!toggle || !content) return;
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', String(expanded));
        toggle.textContent = expanded ? '收起名单' : '展开全部名单';
        card.classList.toggle('is-expanded', expanded);
    });
});
