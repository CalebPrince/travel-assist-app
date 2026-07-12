const Api = {
  async sendChatMessage(message) {
    const res = await fetch('api/v1/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ message }),
    });

    const data = await res.json();

    if (!res.ok) {
      throw new Error(data.error || `Request failed: ${res.status}`);
    }

    return data;
  },

  async generatePlan(intake) {
    return this._json('api/v1/plan', { method: 'POST', body: JSON.stringify({ intake }) });
  },

  async getPlan() {
    return this._json('api/v1/plan', { method: 'GET' });
  },

  async savePlanProgress(id, checked) {
    return this._json('api/v1/plan/progress', { method: 'POST', body: JSON.stringify({ id, checked }) });
  },

  async _json(url, options) {
    const res = await fetch(url, {
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      ...options,
    });

    const data = await res.json();

    if (!res.ok) {
      throw new Error(data.error || `Request failed: ${res.status}`);
    }

    return data;
  },
};
