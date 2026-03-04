const AuthAPI = {
  async check() {
    const res = await fetch('/api/auth');
    const data = await res.json();
    return data.authenticated ? data.user : null;
  },
  async login(login, password) {
    const res = await fetch('/api/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ login, password })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Ошибка авторизации');
    return data.user;
  },
  async logout() {
    await fetch('/api/logout', { method: 'POST' });
  }
};

const BunkerAPI = {
  async getAll(params = {}) {
    const query = new URLSearchParams();
    if (params.district) query.set('district', params.district);
    if (params.wasteType) query.set('wasteType', params.wasteType);
    if (params.contractor) query.set('contractor', params.contractor);
    const qs = query.toString();
    const url = '/api/bunkers' + (qs ? '?' + qs : '');
    const res = await fetch(url);
    if (!res.ok) throw new Error('Ошибка загрузки бункеров');
    return res.json();
  },

  async create(data) {
    const res = await fetch('/api/bunkers', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    if (res.status === 401) throw new Error('auth_required');
    if (res.status === 403) throw new Error('readonly');
    if (!res.ok) throw new Error('Ошибка создания бункера');
    return res.json();
  },

  async update(id, data) {
    const res = await fetch('/api/bunkers/' + id, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    if (res.status === 401) throw new Error('auth_required');
    if (res.status === 403) throw new Error('readonly');
    if (!res.ok) throw new Error('Ошибка обновления бункера');
    return res.json();
  },

  async remove(id) {
    const res = await fetch('/api/bunkers/' + id, { method: 'DELETE' });
    if (res.status === 401) throw new Error('auth_required');
    if (res.status === 403) throw new Error('readonly');
    if (!res.ok) throw new Error('Ошибка удаления бункера');
    return res.json();
  }
};
