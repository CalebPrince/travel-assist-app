(function () {
  const chatWindow = document.getElementById('chat-window');
  const form = document.getElementById('chat-form');
  const input = document.getElementById('chat-input');

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // Minimal renderer for the advisor's Markdown-style output (## headers, -/[ ] lists, **bold**).
  function renderAssistantMarkdown(raw) {
    const lines = escapeHtml(raw).split('\n');
    let html = '';
    let inList = false;

    for (const line of lines) {
      const heading = line.match(/^##\s+(.*)/);
      const checklistItem = line.match(/^-\s+\[([ xX])\]\s+(.*)/);
      const bulletItem = line.match(/^-\s+(.*)/);

      if (heading) {
        if (inList) { html += '</ul>'; inList = false; }
        html += `<h2>${heading[1]}</h2>`;
        continue;
      }

      if (checklistItem || bulletItem) {
        if (!inList) { html += '<ul>'; inList = true; }
        const text = checklistItem ? checklistItem[2] : bulletItem[1];
        const box = checklistItem ? (checklistItem[1].trim() ? '☑' : '☐') + ' ' : '';
        html += `<li>${box}${text}</li>`;
        continue;
      }

      if (inList) { html += '</ul>'; inList = false; }
      if (line.trim() === '') { html += '<br>'; continue; }
      html += `<p>${line.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')}</p>`;
    }

    if (inList) html += '</ul>';
    return html;
  }

  function appendBubble(role, content) {
    const bubble = document.createElement('div');
    bubble.className = `bubble ${role}`;
    bubble.innerHTML = role === 'assistant' ? renderAssistantMarkdown(content) : escapeHtml(content);
    chatWindow.appendChild(bubble);
    chatWindow.scrollTop = chatWindow.scrollHeight;
    return bubble;
  }

  async function sendMessage(message) {
    appendBubble('user', message);
    input.disabled = true;

    const pending = appendBubble('assistant', 'Thinking...');

    try {
      const { reply } = await Api.sendChatMessage(message);
      pending.innerHTML = renderAssistantMarkdown(reply);
    } catch (err) {
      pending.innerHTML = `<em>Something went wrong: ${escapeHtml(err.message)}</em>`;
    } finally {
      input.disabled = false;
      input.focus();
      chatWindow.scrollTop = chatWindow.scrollHeight;
    }
  }

  const intent = new URLSearchParams(window.location.search).get('intent');
  const intentOpeners = {
    study: "I'm planning to study abroad and need help mapping out the process.",
    travel: "I'm a general traveler looking to visit or relocate abroad.",
  };

  if (intentOpeners[intent]) {
    sendMessage(intentOpeners[intent]);
  } else {
    appendBubble('assistant', "We are going to take this step by step. Before we look at visas, let's nail down your origin passport and your destination targets so we map out the exact legal path for you.\n\nAre you planning to **study abroad**, or are you a **general traveler**? And what's your passport/citizenship country?");
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const message = input.value.trim();
    if (!message) return;
    input.value = '';
    sendMessage(message);
  });
})();
