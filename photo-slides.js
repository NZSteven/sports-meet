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

    const image = dialog.querySelector('img');
    const caption = dialog.querySelector('figcaption');
    const play = dialog.querySelector('[data-play]');
    const prev = dialog.querySelector('[data-prev]');
    const next = dialog.querySelector('[data-next]');
    let photos = [];
    let index = 0;
    let timer;

    function show(step = 0) {
        index = (index + step + photos.length) % photos.length;
        const photo = photos[index];
        image.src = photo.href;
        image.alt = photo.querySelector('img').alt;
        caption.textContent = `${index + 1} / ${photos.length}${image.alt ? ' · ' + image.alt : ''}`;
    }

    function setPlaying(playing) {
        clearInterval(timer);
        timer = playing && photos.length > 1 ? setInterval(() => { if (!document.hidden) show(1); }, 4500) : null;
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
    dialog.addEventListener('close', () => setPlaying(false));
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
