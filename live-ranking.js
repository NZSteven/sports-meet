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
        row.dataset.rank = team.rank;
        const trophy = badge('sm-ranking-trophy', '🏆');
        trophy.setAttribute('aria-hidden', 'true');
        const rank = badge('sm-ranking-position', team.rank);
        rank.setAttribute('aria-label', `第 ${team.rank} 名`);
        const name = document.createElement('strong');
        name.className = 'sm-ranking-name';
        name.textContent = team.name;
        const score = badge('sm-ranking-score', '•••');
        score.setAttribute('role', 'img');
        score.setAttribute('aria-label', '积分暂不公开');
        row.append(trophy, rank, name, score);
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
            boards.forEach(board => board.querySelector('.sm-ranking-list').replaceChildren(...teams.slice(0, 6).map(teamRow)));
            previous = state;
        } catch (error) {
            // Keep the last rendered ranking if the request fails.
        } finally {
            loading = false;
        }
    }

    refresh();
    setInterval(refresh, 30000);
    document.addEventListener('visibilitychange', refresh);
})();
