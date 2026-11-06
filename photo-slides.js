(() => {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.querySelectorAll('.sm-hero-slides').forEach(carousel => {
        const slides = Array.from(carousel.querySelectorAll('.sm-hero-slide'));
        if (slides.length < 2) return;
        const controls = carousel.querySelector('.sm-slide-controls');
        const play = controls.querySelector('[data-sm-play]');
        let index = 0;
        let timer;

        function advance(step) {
            slides[index].classList.remove('is-active');
            slides[index].setAttribute('aria-hidden', 'true');
            index = (index + step + slides.length) % slides.length;
            slides[index].classList.add('is-active');
            slides[index].setAttribute('aria-hidden', 'false');
        }

        function setPlaying(playing) {
            clearInterval(timer);
            timer = playing ? setInterval(() => { if (!document.hidden) advance(1); }, 6000) : null;
            carousel.classList.toggle('is-playing', playing);
            play.textContent = playing ? '暂停轮播' : '播放轮播';
        }

        controls.hidden = false;
        controls.querySelector('[data-sm-prev]').addEventListener('click', () => {setPlaying(false); advance(-1);});
        controls.querySelector('[data-sm-next]').addEventListener('click', () => {setPlaying(false); advance(1);});
        play.addEventListener('click', () => setPlaying(!timer));
        setPlaying(!reducedMotion);
    });

    document.querySelectorAll('.sm-photo-carousel').forEach(carousel => {
        const track = carousel.querySelector('.sm-public-gallery');
        if (!track || track.children.length < 2) return;
        const controls = carousel.querySelector('.sm-strip-controls');
        const play = controls.querySelector('[data-sm-strip-play]');
        let timer;

        function move(direction) {
            const limit = track.scrollWidth - track.clientWidth;
            if (limit < 2) return;
            const step = track.children[1].offsetLeft - track.children[0].offsetLeft;
            let left = track.scrollLeft + direction * step;
            if (direction > 0 && track.scrollLeft >= limit - 2) left = 0;
            if (direction < 0 && track.scrollLeft <= 2) left = limit;
            track.scrollTo({left, behavior: reducedMotion ? 'auto' : 'smooth'});
        }

        function setPlaying(playing) {
            clearInterval(timer);
            timer = playing ? setInterval(() => {
                if (!document.hidden && !track.matches(':hover, :focus-within') && !document.querySelector('.sm-photo-viewer[open]')) move(1);
            }, 3500) : null;
            play.textContent = playing ? '暂停走马灯' : '播放走马灯';
        }

        function sizeChanged() {
            controls.hidden = track.scrollWidth <= track.clientWidth + 1;
        }
        controls.querySelector('[data-sm-strip-prev]').addEventListener('click', () => {setPlaying(false); move(-1);});
        controls.querySelector('[data-sm-strip-next]').addEventListener('click', () => {setPlaying(false); move(1);});
        play.addEventListener('click', () => setPlaying(!timer));
        window.addEventListener('resize', sizeChanged);
        sizeChanged();
        setPlaying(!reducedMotion);
    });

    const galleries = document.querySelectorAll('.sm-public-gallery');
    if (!galleries.length || typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) return;

    const dialog = document.createElement('dialog');
    dialog.className = 'sm-photo-viewer';
    dialog.setAttribute('aria-label', '相册照片播放器');
    dialog.innerHTML = '<button class="sm-view-close" type="button" aria-label="关闭照片">×</button>'
        + '<figure><img alt=""><figcaption></figcaption></figure><div class="sm-view-controls">'
        + '<button type="button" data-prev>上一张</button><button type="button" data-play>自动播放</button>'
        + '<button type="button" data-next>下一张</button></div>';
    document.body.append(dialog);

    let image = dialog.querySelector('img');
    const caption = dialog.querySelector('figcaption');
    const play = dialog.querySelector('[data-play]');
    const prev = dialog.querySelector('[data-prev]');
    const next = dialog.querySelector('[data-next]');
    let photos = [];
    let index = 0;
    let timer;
    let change = 0;
    let fade;

    async function show(step = 0, animated = false) {
        const revision = ++change;
        fade?.cancel();
        index = (index + step + photos.length) % photos.length;
        const photo = photos[index];
        const alt = photo.querySelector('img').alt;
        if (animated && !reducedMotion && image.animate) {
            const incoming = new Image();
            incoming.alt = alt;
            incoming.src = photo.href;
            try {
                await incoming.decode();
                if (revision !== change || !dialog.open) return;
                fade = image.animate([{opacity: 1}, {opacity: 0}], {duration: 220, fill: 'forwards'});
                await fade.finished;
            } catch (error) {
                return;
            }
            if (revision !== change || !dialog.open) return;
            image.replaceWith(incoming);
            image = incoming;
            fade = image.animate([{opacity: 0}, {opacity: 1}], {duration: 380});
        } else {
            image.src = photo.href;
            image.alt = alt;
        }
        caption.textContent = `${index + 1} / ${photos.length}${image.alt ? ' · ' + image.alt : ''}`;
    }

    function setPlaying(playing) {
        clearInterval(timer);
        timer = playing && photos.length > 1 ? setInterval(() => { if (!document.hidden) show(1, true); }, 4500) : null;
        play.textContent = timer ? '暂停播放' : '自动播放';
    }

    galleries.forEach(gallery => {
        const links = Array.from(gallery.querySelectorAll('figure > a'));
        links.forEach((link, position) => link.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            photos = links;
            index = position;
            play.disabled = prev.disabled = next.disabled = photos.length < 2;
            show();
            dialog.showModal();
            setPlaying(!reducedMotion);
        }));
    });

    dialog.querySelector('.sm-view-close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        change++;
        fade?.cancel();
        setPlaying(false);
    });
    dialog.addEventListener('click', event => {if (event.target === dialog) dialog.close();});
    prev.addEventListener('click', () => {setPlaying(false); show(-1);});
    next.addEventListener('click', () => {setPlaying(false); show(1);});
    play.addEventListener('click', () => setPlaying(!timer));
    dialog.addEventListener('keydown', event => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        setPlaying(false);
        show(event.key === 'ArrowLeft' ? -1 : 1);
    });
})();
