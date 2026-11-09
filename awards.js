document.querySelectorAll('.sm-awards').forEach(root => {
    let state = JSON.parse(root.querySelector('[data-awards-initial]').textContent);
    const grid = root.querySelector('[data-awards-grid]');
    const status = root.querySelector('[data-awards-status]');
    const overlay = root.querySelector('[data-awards-overlay]');
    const celebration = root.querySelector('[data-awards-celebration]');
    const podium = root.querySelector('[data-awards-podium]');
    const controls = [...root.querySelectorAll('[data-awards-action]')];
    const admin = !!root.dataset.nonce;
    let saving = false, polling = false, timers = [];
    const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function element(tag, text, className) {const el = document.createElement(tag);if(text !== undefined)el.textContent=text;if(className)el.className=className;return el;}
    function updateControls() {
        const valid = {points:state.stage < 4,bronze:state.stage === 4,silver:state.stage === 5,gold:state.stage === 6};
        controls.forEach(button => button.disabled = !admin || saving || !overlay.hidden || !valid[button.dataset.awardsAction]);
        const next = ['公布积分','公布第六名积分','公布第五名积分','公布第四名积分','积分已公布'];
        controls[0].textContent=next[Math.min(state.stage,4)];
        const style=root.querySelector('[data-awards-style]');if(style){style.value=state.style||'classic';style.disabled=saving||!overlay.hidden;}
    }
    function closeCelebration() {timers.forEach(clearTimeout);timers=[];overlay.hidden=true;celebration.replaceChildren();overlay.querySelectorAll('.sm-confetti').forEach(el=>el.remove());updateControls();}
    function podiumRender() {
        podium.replaceChildren();
        for(const rank of [2,1,3]) {
            if(state.stage < 8-rank)continue;
            const winners=state.teams.filter(t=>t.rank===rank && t.revealed);
            if(!winners.length)continue;
            const card=element('article',undefined,`medal-${rank}`);
            card.append(element('span',['','🥇 冠军','🥈 亚军','🥉 季军'][rank]),element('strong',winners.map(t=>t.name).join('、')),element('span',winners[0].score+' 分'));
            podium.append(card);
        }
    }
    function animateMedal(rank) {
        closeCelebration();overlay.hidden=false;overlay.style.background=state.style==='festival'?'radial-gradient(ellipse at 50% 15%,#ee8b70,#326bb3 75%)':state.style==='wheel'?'radial-gradient(ellipse at 50% 15%,#af6ce1,#322269 75%)':'';updateControls();
        const winners=state.teams.filter(t=>t.rank===rank && t.revealed);
        const label=['','冠军','亚军','季军'][rank];
        function show() {
            celebration.replaceChildren(element('div',['','🥇','🥈','🥉'][rank],'sm-medal'),element('p',(state.demo?'【测试积分】':'')+label+'荣耀揭晓'),element('h3',winners.length?winners.map(t=>t.name).join('、'):'本名次空缺'),element('p',winners.length?winners[0].score+' 分':'同分并列，名次顺延'));
            overlay.style.background=state.style==='festival'?'radial-gradient(ellipse at 50% 15%,#ee8b70,#326bb3 75%)':state.style==='wheel'?'radial-gradient(ellipse at 50% 15%,#af6ce1,#322269 75%)':rank===1?'radial-gradient(ellipse at 50% 15%,#ad842e,#172b32 65%)':rank===2?'radial-gradient(ellipse at 50% 15%,#8199af,#172b32 65%)':'radial-gradient(ellipse at 50% 15%,#ad7246,#172b32 65%)';
            if(!reduced()&&winners.length)for(let i=0;i<(rank===1?90:45);i++){const piece=element('i',undefined,'sm-confetti');piece.style.left=Math.random()*100+'%';piece.style.background=['#ffcb54','#42b6d2','#f48e78','#fff1c9'][i%4];piece.style.animationDelay=Math.random()*1.8+'s';overlay.append(piece);}
            timers.push(setTimeout(closeCelebration,rank===1?9000:6500));
        }
        if(reduced()){show();return;}
        if(state.style==='festival'){
            celebration.replaceChildren(element('p',label+'冲线时刻！'),element('div','🏃‍♀️  🏃  🏃‍♂️','sm-festival-runners'),element('div','🏁 为Fun而奔，荣耀即将到站 🏁','sm-festival-finish'));
            timers.push(setTimeout(show,3300));return;
        }
        if(state.style==='wheel'){
            const wheel=element('div','🏆','sm-lucky-wheel');
            const reel=element('h3','精彩即将揭晓','sm-wheel-name');
            celebration.replaceChildren(element('p',label+' · 荣耀转盘'),wheel,reel,element('p','名次按比赛积分揭晓'));
            const names=state.teams.map(t=>t.name);
            for(let i=0;i<24;i++)timers.push(setTimeout(()=>{reel.textContent=names[Math.floor(Math.random()*names.length)];},i*80+i*i*3));
            timers.push(setTimeout(show,4500));return;
        }
        let n=3;
        function countdown(){celebration.replaceChildren(element('p',label+'即将揭晓'),element('div',String(n--),'sm-countdown'));if(n>0)timers.push(setTimeout(countdown,800));else timers.push(setTimeout(show,800));}
        countdown();
    }
    function trophies() {
        const cards=[...grid.children];cards.forEach(card=>{const cup=card.querySelector('.sm-awards-cup');cup.hidden=true;cup.classList.remove('is-large');});
        let chosen;
        if(!state.stage){chosen=cards.map(card=>({card,key:Math.random()})).sort((a,b)=>a.key-b.key).slice(0,6).map(x=>x.card);}
        else chosen=cards.filter((card,i)=>state.teams[i].rank<=6);
        chosen.forEach(card=>card.querySelector('.sm-awards-cup').hidden=false);
        if(!reduced())chosen.forEach(card=>{if(Math.random()>.55)card.querySelector('.sm-awards-cup').classList.add('is-large');});
    }
    function render(next, animate=true) {
        const previous=state;state=next;root.dataset.style=state.style||'classic';grid.replaceChildren();
        state.teams.forEach(team=>{
            const card=element('article',undefined,team.revealed?'is-revealed':'');const cup=element('span','🏆','sm-awards-cup');cup.hidden=true;cup.setAttribute('aria-hidden','true');
            card.append(cup,element('strong',team.name),element('span',team.revealed?'第'+team.rank+'名 · '+team.score+'分':'•••','sm-awards-score'+(team.revealed?'':' is-masked')));grid.append(card);
        });
        status.textContent=state.title;podiumRender();trophies();
        if(state.stage===0 || state.demo!==previous.demo)closeCelebration();
        if(animate&&state.revision!==previous.revision&&state.stage>=5)animateMedal(8-state.stage);
        updateControls();
    }
    async function command(action) {
        if(!admin||saving)return;saving=true;updateControls();
        try{
            const response=await fetch(root.dataset.controlUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':root.dataset.nonce},body:JSON.stringify({action,revision:state.revision,demo:!!state.demo})});
            const data=await response.json();if(!response.ok)throw Error(data.message||'公布失败，请重试。');render(data);
        }catch(error){status.textContent=error.message;}finally{saving=false;updateControls();}
    }
    root.querySelector('[data-awards-style]')?.addEventListener('change',event=>command('style_'+event.target.value));
    root.querySelector('[data-awards-demo-start]')?.addEventListener('click',()=>command('demo_start'));
    root.querySelector('[data-awards-demo-end]')?.addEventListener('click',()=>command('demo_end'));
    controls.forEach(button=>button.addEventListener('click',()=>command(button.dataset.awardsAction)));
    root.querySelector('[data-awards-close]').addEventListener('click',closeCelebration);
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!overlay.hidden)closeCelebration();});
    root.querySelector('[data-awards-reset]')?.addEventListener('click',()=>{const confirm=root.querySelector('[data-awards-reset-confirm]');if(confirm.checked){confirm.checked=false;command('reset');}else status.textContent='请先勾选确认重新开始公布。';});
    async function refresh(){
        if(document.hidden||saving||polling)return;polling=true;
        try{const response=await fetch(root.dataset.awardsUrl,{cache:'no-store',credentials:'same-origin'});if(!response.ok)return;const next=await response.json();if(next.style!==state.style || next.demo!==state.demo || next.revision!==state.revision || (!state.stage&&JSON.stringify(next.teams)!==JSON.stringify(state.teams)))render(next);}
        catch(error){}finally{polling=false;}
    }
    render(state,false);setInterval(()=>{if(!document.hidden&&!reduced())trophies();},1000);setInterval(refresh,2500);document.addEventListener('visibilitychange',refresh);
});
