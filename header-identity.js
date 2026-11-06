(() => {
    const script = document.currentScript;
    if (!script) return;
    const image = new URL('media/sports-day-brand.jpg', script.src).href;
    const apply = () => {
        document.querySelectorAll('.sd-brand').forEach(brand => {
            const mark = brand.querySelector('.sd-mark');
            if (mark) {
                mark.textContent = '';
                mark.classList.add('sd-mascot-mark');
                mark.style.backgroundImage = `url("${image}")`;
            }
            const title = brand.querySelector('.sd-mark + span');
            if (title) {
                title.classList.add('sm-brand-title');
                const organization = document.createElement('span');
                organization.className = 'sm-brand-organization';
                organization.textContent = '新西兰中国高校校友';
                const event = document.createElement('span');
                event.className = 'sm-brand-event';
                event.textContent = '第五届奔Fun运动会2026';
                title.replaceChildren(organization, event);
            }
        });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply, {once:true});
    else apply();
})();
