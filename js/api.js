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
    if (!res.ok) throw new Error('Ошибка создания бункера');
    return res.json();
  },

  async update(id, data) {
    const res = await fetch('/api/bunkers/' + id, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    if (!res.ok) throw new Error('Ошибка обновления бункера');
    return res.json();
  },

  async remove(id) {
    const res = await fetch('/api/bunkers/' + id, {
      method: 'DELETE'
    });
    if (!res.ok) throw new Error('Ошибка удаления бункера');
    return res.json();
  }
};
