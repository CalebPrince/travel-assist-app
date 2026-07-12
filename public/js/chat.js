(function () {
  const chatWindow = document.getElementById('chat-window');
  const chatCard = document.getElementById('chat-card');
  const form = document.getElementById('chat-form');
  const input = document.getElementById('chat-input');

  const intakeCard = document.getElementById('intake-card');
  const intakeProgress = document.getElementById('intake-progress');
  const intakeQuestion = document.getElementById('intake-question');
  const intakeChoiceButtons = document.getElementById('intake-choice-buttons');
  const intakeTextForm = document.getElementById('intake-text-form');
  const intakeInput = document.getElementById('intake-input');
  const intakeBack = document.getElementById('intake-back');
  const intakeSkip = document.getElementById('intake-skip');

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

  // Pulls out any `[ASK: ...]` lines the advisor used to request specific
  // pieces of information, leaving the rest of its message intact.
  function extractAskQuestions(raw) {
    const questions = [];
    const cleaned = raw
      .replace(/^\s*\[ASK:\s*(.+?)\]\s*$/gm, (match, q) => {
        questions.push(q.trim());
        return '';
      })
      .replace(/\n{3,}/g, '\n\n')
      .trim();
    return { cleaned, questions };
  }

  // Walks the user through a batch of advisor questions one at a time,
  // inline in the chat, then hands the combined Q&A back as one message.
  function appendWizard(questions, onDone) {
    input.disabled = true;
    form.classList.add('d-none');

    const wizard = document.createElement('div');
    wizard.className = 'card shadow-sm mb-3';
    wizard.innerHTML = `
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-muted small wizard-progress"></span>
          <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 wizard-skip">Skip, I'll just type &rarr;</button>
        </div>
        <div class="fw-semibold mb-2 wizard-question"></div>
        <form class="wizard-form">
          <div class="mb-2">
            <input type="text" class="form-control wizard-input" autocomplete="off" required>
          </div>
          <div class="d-flex justify-content-between">
            <button type="button" class="btn btn-link text-decoration-none px-0 invisible wizard-back">&larr; Back</button>
            <button type="submit" class="btn btn-primary btn-sm">Next</button>
          </div>
        </form>
      </div>
    `;
    chatWindow.appendChild(wizard);
    chatWindow.scrollTop = chatWindow.scrollHeight;

    const progressEl = wizard.querySelector('.wizard-progress');
    const questionEl = wizard.querySelector('.wizard-question');
    const formEl = wizard.querySelector('.wizard-form');
    const inputEl = wizard.querySelector('.wizard-input');
    const backEl = wizard.querySelector('.wizard-back');
    const skipEl = wizard.querySelector('.wizard-skip');

    const answers = [];
    let idx = 0;

    function render() {
      progressEl.textContent = `Question ${idx + 1} of ${questions.length}`;
      questionEl.textContent = questions[idx];
      backEl.classList.toggle('invisible', idx === 0);
      inputEl.value = answers[idx] || '';
      inputEl.focus();
    }

    function resumeFreeText() {
      wizard.remove();
      form.classList.remove('d-none');
      input.disabled = false;
    }

    formEl.addEventListener('submit', (event) => {
      event.preventDefault();
      const value = inputEl.value.trim();
      if (!value) return;
      answers[idx] = value;
      idx += 1;

      if (idx >= questions.length) {
        wizard.remove();
        form.classList.remove('d-none');
        input.disabled = false;
        onDone(questions.map((q, i) => `${q} → ${answers[i]}`).join('\n'));
      } else {
        render();
      }
    });

    backEl.addEventListener('click', () => {
      if (idx === 0) return;
      idx -= 1;
      render();
    });

    skipEl.addEventListener('click', () => {
      resumeFreeText();
      input.focus();
    });

    render();
  }

  async function sendMessage(message) {
    appendBubble('user', message);
    input.disabled = true;

    const pending = appendBubble('assistant', 'Thinking...');

    try {
      const { reply } = await Api.sendChatMessage(message);
      const { cleaned, questions } = extractAskQuestions(reply);

      if (cleaned) {
        pending.innerHTML = renderAssistantMarkdown(cleaned);
      } else {
        pending.remove();
      }

      if (questions.length > 0) {
        appendWizard(questions, (combinedAnswer) => sendMessage(combinedAnswer));
        return;
      }
    } catch (err) {
      pending.innerHTML = `<em>Something went wrong: ${escapeHtml(err.message)}</em>`;
    }

    input.disabled = false;
    input.focus();
    chatWindow.scrollTop = chatWindow.scrollHeight;
  }

  // --- Step-by-step intake wizard ---
  // Gathers the same triage fields the advisor's system prompt would otherwise
  // have to ask for in free text, then kicks off the chat with one structured
  // opening message so the advisor can skip straight to real guidance.
  const STEPS = [
    { key: 'intent', question: 'Are you studying abroad, or traveling generally?', type: 'choice' },
    { key: 'passport', question: "What's your passport / citizenship country?", type: 'text', placeholder: 'e.g. Nigeria' },
    { key: 'destination', question: "What's your target destination? (Not sure yet? Just say so.)", type: 'text', placeholder: 'e.g. Canada, or "not sure yet"' },
    { key: 'timeline', question: "What's your timeline?", type: 'text', placeholder: 'e.g. Fall 2027, or "within 3 months"' },
  ];

  const answers = {};
  const urlIntent = new URLSearchParams(window.location.search).get('intent');
  const firstStepIndex = (urlIntent === 'study' || urlIntent === 'travel') ? 1 : 0;
  let stepIndex = firstStepIndex;
  if (firstStepIndex === 1) {
    answers.intent = urlIntent;
  }

  function renderStep() {
    const step = STEPS[stepIndex];
    intakeProgress.textContent = `Step ${stepIndex + 1} of ${STEPS.length}`;
    intakeQuestion.textContent = step.question;
    intakeBack.classList.toggle('invisible', stepIndex === firstStepIndex);

    if (step.type === 'choice') {
      intakeChoiceButtons.classList.remove('d-none');
      intakeTextForm.classList.add('d-none');
    } else {
      intakeChoiceButtons.classList.add('d-none');
      intakeTextForm.classList.remove('d-none');
      intakeInput.placeholder = step.placeholder || '';
      intakeInput.value = answers[step.key] || '';
      intakeInput.focus();
    }
  }

  function goNext(value) {
    answers[STEPS[stepIndex].key] = value;
    stepIndex += 1;

    if (stepIndex >= STEPS.length) {
      finishIntake();
    } else {
      renderStep();
    }
  }

  function goBack() {
    if (stepIndex === firstStepIndex) return;
    stepIndex -= 1;
    renderStep();
  }

  function finishIntake() {
    intakeCard.classList.add('d-none');
    chatCard.classList.remove('d-none');

    const intentLabel = answers.intent === 'study' ? 'study abroad' : 'travel generally';
    const message = `I'm planning to ${intentLabel}. My passport/citizenship country is ${answers.passport}. `
      + `My target destination is ${answers.destination}. My timeline is ${answers.timeline}.`;
    sendMessage(message);
  }

  intakeChoiceButtons.querySelectorAll('button[data-value]').forEach((btn) => {
    btn.addEventListener('click', () => goNext(btn.dataset.value));
  });

  intakeTextForm.addEventListener('submit', (event) => {
    event.preventDefault();
    const value = intakeInput.value.trim();
    if (!value) return;
    intakeInput.value = '';
    goNext(value);
  });

  intakeBack.addEventListener('click', goBack);

  intakeSkip.addEventListener('click', () => {
    intakeCard.classList.add('d-none');
    chatCard.classList.remove('d-none');
    appendBubble('assistant', "No problem — tell me in your own words: are you studying abroad or traveling generally, what's your passport/citizenship country, your target destination, and your timeline?");
  });

  renderStep();

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const message = input.value.trim();
    if (!message) return;
    input.value = '';
    sendMessage(message);
  });
})();
