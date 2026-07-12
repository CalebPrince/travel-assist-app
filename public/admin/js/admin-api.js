const AdminApi = {
  async request(path, options = {}) {
    const res = await fetch(path, {
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      ...options,
    });

    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
      const err = new Error(data.error || `Request failed: ${res.status}`);
      err.status = res.status;
      throw err;
    }

    return data;
  },

  me() {
    return this.request('../api/v1/admin/me');
  },

  login(username, password) {
    return this.request('../api/v1/admin/login', {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    });
  },

  logout() {
    return this.request('../api/v1/admin/logout', { method: 'POST' });
  },

  getSettings() {
    return this.request('../api/v1/admin/settings');
  },

  updateSettings(payload) {
    return this.request('../api/v1/admin/settings', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  changePassword(payload) {
    return this.request('../api/v1/admin/change-password', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};
