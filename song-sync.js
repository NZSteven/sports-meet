document.querySelectorAll('.sm-song').forEach(section => {
    const audio = section.querySelector('audio');
    const lyrics = section.querySelector('.sm-song-lyrics');
    const follow = section.querySelector('[data-sm-follow-lyrics]');
    const lines = Array.from(section.querySelectorAll('.sm-lyric-line'));
    if (!audio || !lyrics || !follow || !lines.length) return;
    const times = lines.map(line => Number(line.dataset.time));
    let active = -1;

    function followLine() {
        if (active < 0 || !follow.checked) return;
        const line = lines[active].getBoundingClientRect();
        const box = lyrics.getBoundingClientRect();
        lyrics.scrollTop += line.top - box.top - (lyrics.clientHeight - line.height) / 2;
    }

    function update() {
        let index = times.length - 1;
        while (index >= 0 && times[index] > audio.currentTime) index--;
        if (active === index) return;
        if (active >= 0) lines[active].removeAttribute('aria-current');
        active = index;
        if (active >= 0) lines[active].setAttribute('aria-current', 'true');
        followLine();
    }

    ['timeupdate', 'seeking', 'loadedmetadata', 'play'].forEach(event => audio.addEventListener(event, update));
    follow.addEventListener('change', followLine);
    update();
});
