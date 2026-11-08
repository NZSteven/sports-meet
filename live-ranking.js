(() => {
    const boards = Array.from(document.querySelectorAll('[data-sm-rank-url]'));
    if (!boards.length) return;
    let previous;
    let loading = false;

    function badge(className, text) {
        const element = document.createElement('span');
        element.className = className;
        element.textContent = text;
        return element;
    }

    function teamRow(team) {
        const row = document.createElement('li');
        row.dataset.teamId = team.id;
        const trophy = badge('sm-ranking-trophy', '🏆');
        trophy.hidden = !team.trophy;
        trophy.setAttribute('role', 'img');
        trophy.setAttribute('aria-label', '积分前六');
        const name = document.createElement('strong');
        name.className = 'sm-ranking-name';
        name.textContent = team.name;
        const score = badge('sm-ranking-score', '•••');
        score.setAttribute('role', 'img');
        score.setAttribute('aria-label', '积分暂不公开');
        row.append(trophy, name, score);
        return row;
    }

    async function refresh() {
        if (document.hidden || loading) return;
        loading = true;
        try {
            const response = await fetch(boards[0].dataset.smRankUrl, {cache: 'no-store'});
            if (!response.ok) return;
            const teams = await response.json();
            if (!Array.isArray(teams)) return;
            const state = JSON.stringify(teams);
            if (state === previous) return;
            boards.forEach(board => {
                const list = board.querySelector('.sm-ranking-list');
                const visible = ['top', 'home'].includes(board.dataset.smRankMode) ? teams.filter(team => team.trophy) : teams;
                const rows = Array.from(list.children);
                if (rows.length === visible.length && rows.every((row, i) => row.dataset.teamId === String(visible[i].id))) {
                    rows.forEach((row, i) => {
                        row.querySelector('.sm-ranking-trophy').hidden = !visible[i].trophy;
                        row.querySelector('.sm-ranking-name').textContent = visible[i].name;
                    });
                } else {
                    list.replaceChildren(...visible.map(teamRow));
                }
            });
            previous = state;
        } catch (error) {
            // Keep the last rendered ranking if the request fails.
        } finally {
            loading = false;
        }
    }

    function pulseTrophies() {
        boards.forEach(board => {
            board.querySelectorAll('.sm-ranking-trophy.is-popped').forEach(cup => cup.classList.remove('is-popped'));
            if (document.hidden || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            const cups = Array.from(board.querySelectorAll('.sm-ranking-trophy:not([hidden])'));
            if (cups.length) cups[Math.floor(Math.random() * cups.length)].classList.add('is-popped');
        });
    }
    setInterval(pulseTrophies, 1000);
    refresh();
    setInterval(refresh, 30000);
    document.addEventListener('visibilitychange', refresh);
})();
