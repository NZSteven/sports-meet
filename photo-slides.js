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
        const originals = Array.from(track.children);
        const last = originals[originals.length - 1];
        let copies = [];
        let endCopy;
        let width = 0;
        let settleTimer;
        let timer;

        function wrap() {
            if (!endCopy) return;
            const start = originals[0].offsetLeft;
            const cycle = endCopy.offsetLeft - start;
            if (cycle <= 0) return;
            if (track.scrollLeft >= start + cycle - 1) track.scrollLeft -= cycle;
            else if (track.scrollLeft < start - 1) track.scrollLeft += cycle;
        }

        function move(direction) {
            if (!endCopy) return;
            wrap();
            const start = originals[0].offsetLeft;
            const step = originals[1].offsetLeft - start;
            const left = start + (Math.round((track.scrollLeft - start) / step) + direction) * step;
            track.scrollTo({left, behavior: reducedMotion ? 'auto' : 'smooth'});
        }

        function setPlaying(playing) {
            clearInterval(timer);
            timer = playing ? setInterval(() => {
                if (!document.hidden && !track.matches(':hover, :focus-within') && !document.querySelector('.sm-photo-viewer[open]')) move(1);
            }, 3500) : null;
            play.textContent = playing ? '暂停' : '播放';
        }

        function sizeChanged() {
            if (width === track.clientWidth) return;
            width = track.clientWidth;
            const fullWidth = last.offsetLeft + last.offsetWidth - originals[0].offsetLeft;
            const canLoop = width > 0 && fullWidth >= width - 1;
            controls.hidden = !canLoop;
            if (canLoop && !endCopy) {
                const cloneCards = () => originals.map((card, index) => {
                    const copy = card.cloneNode(true);
                    copy.dataset.smStripClone = index;
                    copy.setAttribute('aria-hidden', 'true');
                    copy.querySelector('a').tabIndex = -1;
                    return copy;
                });
                const before = cloneCards();
                const after = cloneCards();
                endCopy = after[0];
                copies = [...before, ...after];
                track.prepend(...before);
                track.append(...after);
            } else if (!canLoop && endCopy) {
                copies.forEach(copy => copy.remove());
                copies = [];
                endCopy = undefined;
            }
            track.scrollLeft = endCopy ? originals[0].offsetLeft : 0;
        }
        controls.querySelector('[data-sm-strip-prev]').addEventListener('click', () => {setPlaying(false); move(-1);});
        controls.querySelector('[data-sm-strip-next]').addEventListener('click', () => {setPlaying(false); move(1);});
        play.addEventListener('click', () => setPlaying(!timer));
        track.addEventListener('scroll', () => {
            clearTimeout(settleTimer);
            settleTimer = setTimeout(wrap, 160);
        }, {passive:true});
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
        const links = Array.from(gallery.querySelectorAll('figure:not([data-sm-strip-clone]) > a'));
        gallery.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const link = event.target.closest('figure > a');
            if (!link || !gallery.contains(link)) return;
            const clone = link.parentElement.dataset.smStripClone;
            const position = clone === undefined ? links.indexOf(link) : Number(clone);
            if (!links[position]) return;
            event.preventDefault();
            photos = links;
            index = position;
            play.disabled = prev.disabled = next.disabled = photos.length < 2;
            show();
            dialog.showModal();
            setPlaying(!reducedMotion);
        });
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
