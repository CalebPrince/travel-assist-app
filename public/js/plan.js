(function () {
  // --- Element refs -------------------------------------------------------
  const intakeCard = document.getElementById('intake-card');
  const intakeProgress = document.getElementById('intake-progress');
  const intakeQuestion = document.getElementById('intake-question');
  const intakeChoices = document.getElementById('intake-choices');
  const intakeTextForm = document.getElementById('intake-text-form');
  const intakeInput = document.getElementById('intake-input');
  const intakeBack = document.getElementById('intake-back');

  const loadingCard = document.getElementById('loading-card');
  const errorCard = document.getElementById('error-card');
  const errorText = document.getElementById('error-text');
  const errorRetry = document.getElementById('error-retry');

  const dashboard = document.getElementById('dashboard');
  const planSummary = document.getElementById('plan-summary');
  const planProfile = document.getElementById('plan-profile');
  const phasesEl = document.getElementById('phases');
  const nextStepEl = document.getElementById('plan-next-step');
  const progressBar = document.getElementById('plan-progress-bar');
  const progressLabel = document.getElementById('plan-progress-label');
  const completeCard = document.getElementById('plan-complete-card');
  const printBtn = document.getElementById('print-btn');
  const restartBtn = document.getElementById('restart-btn');

  function show(el) { el.classList.remove('d-none'); }
  function hide(el) { el.classList.add('d-none'); }
  function showOnly(el) {
    [intakeCard, loadingCard, errorCard, dashboard].forEach(hide);
    show(el);
  }

  // --- Intake wizard ------------------------------------------------------
  const INTENT_STEP = {
    key: 'intent',
    question: 'What kind of journey are you planning?',
    type: 'choice',
    options: [
      { value: 'study', label: '🎓 Studying Abroad' },
      { value: 'travel', label: '✈️ General Travel' },
    ],
  };

  const COMMON_STEPS = [
    { key: 'passport', question: "What's your passport / citizenship country?", type: 'text', placeholder: 'e.g. Nigeria' },
    { key: 'destination', question: "What's your target destination?", type: 'text', placeholder: 'e.g. Canada, or "not sure yet"' },
    { key: 'timeline', question: "What's your timeline?", type: 'text', placeholder: 'e.g. Fall 2027, or "within 3 months"' },
  ];

  function buildSteps(intent) {
    if (intent === 'study') {
      return [
        INTENT_STEP,
        {
          key: 'level', question: 'What level are you studying?', type: 'choice',
          options: [
            { value: 'Undergraduate', label: 'Undergraduate' },
            { value: 'Postgraduate', label: 'Postgraduate' },
            { value: 'Language program', label: 'Language program' },
          ],
        },
        { key: 'field', question: 'What field or program interests you?', type: 'text', placeholder: 'e.g. Computer Science' },
        ...COMMON_STEPS,
        { key: 'budget', question: "What's your rough budget? (optional)", type: 'text', placeholder: 'e.g. $20,000/year', optional: true },
      ];
    }
    return [
      INTENT_STEP,
      {
        key: 'purpose', question: "What's the purpose of your trip?", type: 'choice',
        options: [
          { value: 'Tourism', label: '🏝️ Tourism' },
          { value: 'Digital nomad', label: '💻 Digital nomad' },
          { value: 'Relocation', label: '🏠 Relocation' },
          { value: 'Business', label: '💼 Business' },
        ],
      },
      ...COMMON_STEPS,
    ];
  }

  const answers = {};
  let steps = [INTENT_STEP];
  let stepIndex = 0;
  let minStepIndex = 0;

  const urlIntent = new URLSearchParams(window.location.search).get('intent');
  if (urlIntent === 'study' || urlIntent === 'travel') {
    answers.intent = urlIntent;
    steps = buildSteps(urlIntent);
    stepIndex = 1;
    minStepIndex = 1;
  }

  function renderStep() {
    const step = steps[stepIndex];
    intakeProgress.textContent = `Step ${stepIndex + 1} of ${steps.length}`;
    intakeQuestion.textContent = step.question;
    intakeBack.classList.toggle('invisible', stepIndex === minStepIndex);

    if (step.type === 'choice') {
      hide(intakeTextForm);
      intakeChoices.innerHTML = '';
      step.options.forEach((opt) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-primary';
        btn.textContent = opt.label;
        btn.addEventListener('click', () => choose(step, opt.value));
        intakeChoices.appendChild(btn);
      });
      show(intakeChoices);
    } else {
      hide(intakeChoices);
      show(intakeTextForm);
      intakeInput.placeholder = step.placeholder || '';
      intakeInput.required = !step.optional;
      intakeInput.value = answers[step.key] || '';
      intakeInput.focus();
    }
  }

  function choose(step, value) {
    // Picking a (possibly different) intent rebuilds the remaining steps.
    if (step.key === 'intent') {
      answers.intent = value;
      steps = buildSteps(value);
    } else {
      answers[step.key] = value;
    }
    advance();
  }

  function advance() {
    stepIndex += 1;
    if (stepIndex >= steps.length) {
      generate();
    } else {
      renderStep();
    }
  }

  intakeTextForm.addEventListener('submit', (event) => {
    event.preventDefault();
    const step = steps[stepIndex];
    const value = intakeInput.value.trim();
    if (!value && !step.optional) return;
    answers[step.key] = value;
    advance();
  });

  intakeBack.addEventListener('click', () => {
    if (stepIndex === minStepIndex) return;
    stepIndex -= 1;
    renderStep();
  });

  function startWizard() {
    stepIndex = minStepIndex;
    showOnly(intakeCard);
    renderStep();
  }

  // --- Plan generation ----------------------------------------------------
  async function generate() {
    showOnly(loadingCard);
    try {
      const data = await Api.generatePlan(answers);
      renderDashboard(data);
    } catch (err) {
      errorText.textContent = err.message;
      showOnly(errorCard);
    }
  }

  errorRetry.addEventListener('click', generate);

  // --- Dashboard ----------------------------------------------------------
  let planId = null;
  let checkedSet = new Set();
  let totalItems = 0;
  let saveTimer = null;

  function itemId(p, i) { return `p${p}i${i}`; }

  function renderDashboard(data) {
    const plan = data.plan;
    planId = data.id;
    checkedSet = new Set(Array.isArray(data.checked) ? data.checked : []);

    planSummary.textContent = plan.summary || '';
    nextStepEl.textContent = plan.next_step || '';
    renderProfile(plan.profile || {});

    phasesEl.innerHTML = '';
    totalItems = 0;

    (plan.phases || []).forEach((phase, p) => {
      const items = Array.isArray(phase.items) ? phase.items : [];
      totalItems += items.length;

      const card = document.createElement('div');
      card.className = 'card phase-card shadow-sm mb-3';

      const head = document.createElement('div');
      head.className = 'card-header phase-head';
      const title = document.createElement('div');
      title.className = 'fw-semibold';
      title.textContent = phase.title || `Phase ${p + 1}`;
      head.appendChild(title);
      if (phase.description) {
        const desc = document.createElement('div');
        desc.className = 'small text-white-50';
        desc.textContent = phase.description;
        head.appendChild(desc);
      }
      card.appendChild(head);

      const body = document.createElement('div');
      body.className = 'card-body py-1';
      items.forEach((item, i) => body.appendChild(renderItem(item, itemId(p, i))));
      card.appendChild(body);

      phasesEl.appendChild(card);
    });

    updateProgress();
    showOnly(dashboard);
  }

  function renderItem(item, id) {
    const row = document.createElement('div');
    row.className = 'plan-item py-2 d-flex align-items-start gap-2';
    if (checkedSet.has(id)) row.classList.add('done');

    const box = document.createElement('input');
    box.type = 'checkbox';
    box.className = 'form-check-input mt-1 flex-shrink-0';
    box.checked = checkedSet.has(id);
    box.addEventListener('change', () => toggleItem(id, box.checked, row));

    const content = document.createElement('div');
    const text = document.createElement('div');
    text.className = 'plan-item-text';
    text.textContent = item.text || '';

    if (item.verify) {
      const badge = document.createElement('span');
      badge.className = 'badge bg-warning text-dark verify-badge ms-2';
      badge.textContent = 'Verify with embassy';
      text.appendChild(badge);
    }
    content.appendChild(text);

    if (item.detail) {
      const detail = document.createElement('div');
      detail.className = 'plan-item-detail';
      detail.textContent = item.detail;
      content.appendChild(detail);
    }

    const label = document.createElement('label');
    label.className = 'd-flex align-items-start gap-2 mb-0 w-100';
    label.style.cursor = 'pointer';
    label.appendChild(box);
    label.appendChild(content);
    row.appendChild(label);
    return row;
  }

  function renderProfile(profile) {
    planProfile.innerHTML = '';
    const intentChip = profile.intent === 'study' ? '🎓 Study abroad' : '✈️ General travel';
    const chips = [
      intentChip,
      profile.origin ? `🛂 From ${profile.origin}` : null,
      profile.destination ? `📍 To ${profile.destination}` : null,
      profile.timeline ? `🗓️ ${profile.timeline}` : null,
    ].filter(Boolean);

    chips.forEach((textValue) => {
      const chip = document.createElement('span');
      chip.className = 'profile-chip';
      chip.textContent = textValue;
      planProfile.appendChild(chip);
    });
  }

  function toggleItem(id, isChecked, row) {
    if (isChecked) checkedSet.add(id); else checkedSet.delete(id);
    row.classList.toggle('done', isChecked);
    updateProgress();
    scheduleSave();
  }

  function updateProgress() {
    const done = checkedSet.size;
    const pct = totalItems ? Math.round((done / totalItems) * 100) : 0;
    progressBar.style.width = `${pct}%`;
    progressBar.setAttribute('aria-valuenow', String(pct));
    progressLabel.textContent = `${done} of ${totalItems} done`;
    completeCard.classList.toggle('d-none', !(totalItems > 0 && done === totalItems));
  }

  // Debounce writes so rapid ticking sends one request, not one per click.
  function scheduleSave() {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      Api.savePlanProgress(planId, Array.from(checkedSet)).catch(() => {
        // A failed save is non-fatal: state stays in the UI and the next
        // toggle retries. Don't interrupt the visitor over it.
      });
    }, 600);
  }

  printBtn.addEventListener('click', () => window.print());

  restartBtn.addEventListener('click', () => {
    if (!confirm('Start a new plan? Your current checklist will be kept, but a fresh plan will be generated.')) return;
    // Reset intake to the top (keep any pre-selected intent from the URL).
    for (const key of Object.keys(answers)) {
      if (!(key === 'intent' && minStepIndex === 1)) delete answers[key];
    }
    startWizard();
  });

  // --- Boot ---------------------------------------------------------------
  // Resume an existing plan for this session if one exists; otherwise begin
  // the intake wizard.
  (async function boot() {
    try {
      const data = await Api.getPlan();
      if (data && data.plan) {
        renderDashboard(data);
        return;
      }
    } catch (err) {
      // Ignore and fall through to the wizard.
    }
    startWizard();
  })();
})();
