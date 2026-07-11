(async function () {
  try {
    const { username } = await AdminApi.me();
    const el = document.getElementById('admin-username');
    if (el) el.textContent = username;
  } catch (err) {
    window.location.href = '/admin/login.html';
  }
})();
