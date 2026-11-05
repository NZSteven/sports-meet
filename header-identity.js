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
            const slogan = brand.querySelector('small');
            if (slogan) slogan.textContent = '为Fun而奔，奔而得分';
        });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply, {once:true});
    else apply();
})();
