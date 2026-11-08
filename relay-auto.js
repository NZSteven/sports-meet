(() => {
  let busy = false;
  setInterval(async () => {
    const panel = document.querySelector('[data-relay-auto-url]');
    if (!panel || busy || document.hidden) return;
    busy = true;
    try {
      const response = await fetch(panel.dataset.relayAutoUrl, {credentials: 'same-origin', cache: 'no-store'});
      if (!response.ok) return;
      const data = await response.json();
      if (!data.success || typeof data.data?.html !== 'string') return;
      const template = document.createElement('template');
      template.innerHTML = data.data.html;
      const replacement = template.content.querySelector('[data-relay-auto-url]');
      if (replacement) panel.replaceWith(replacement);
    } catch (_) { /* Keep the last successful preview on temporary network errors. */ }
    finally { busy = false; }
  }, 20000);
})();
