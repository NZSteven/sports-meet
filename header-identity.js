(() => {
    const apply = () => {
        document.querySelectorAll('.sd-brand').forEach(brand => {
            const mark = brand.querySelector('.sd-mark');
            if (mark) {
                mark.textContent = '';
                mark.classList.add('sd-mascot-mark');
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
        document.querySelectorAll('.sd-header-inner > nav').forEach((nav, index) => {
            nav.id ||= 'sm-main-navigation-' + (index + 1);
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'sm-menu-toggle';
            toggle.setAttribute('aria-controls', nav.id);
            toggle.innerHTML = '<span aria-hidden="true"></span>';
            const setExpanded = expanded => {
                toggle.setAttribute('aria-expanded', String(expanded));
                toggle.setAttribute('aria-label', expanded ? '收起主菜单' : '展开主菜单');
            };
            setExpanded(false);
            nav.parentElement.prepend(toggle);
            toggle.addEventListener('click', () => setExpanded(toggle.getAttribute('aria-expanded') !== 'true'));
            nav.parentElement.addEventListener('keydown', event => {
                if (event.key !== 'Escape' || toggle.getAttribute('aria-expanded') !== 'true' || !toggle.offsetWidth) return;
                setExpanded(false);
                toggle.focus();
            });
        });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply, {once:true});
    else apply();
})();
