<?php
require 'auth_check.php';
require 'db.php';

$isAdmin = is_admin($conn);

$user = $conn->prepare("SELECT username FROM users WHERE id = ?");
$user->bind_param("i", $_SESSION['user_id']);
$user->execute();
$user->bind_result($username);
$user->fetch();
$user->close();

$result = $conn->query("SELECT * FROM alerts ORDER BY created_at DESC");
$total = $result->num_rows;

$recent = $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE created_at >= (NOW() - INTERVAL 1 DAY)")->fetch_assoc()['c'];

$hospitals = $conn->query("SELECT COUNT(DISTINCT hospital) AS c FROM alerts")->fetch_assoc()['c'];

/* Latest alert for the banner */
$result->data_seek(0);
$latest = $result->fetch_assoc();
$result->data_seek(0);

/* Per-hospital breakdown */
$breakdown = [];
$bd = $conn->query("SELECT hospital, COUNT(*) AS c FROM alerts WHERE hospital IS NOT NULL AND hospital != '' GROUP BY hospital ORDER BY c DESC");
while ($b = $bd->fetch_assoc()) {
    $breakdown[] = $b;
}

/* Last 24h activity by hour (server-side buckets, CSS chart) */
$hours = array_fill(0, 24, 0);
$hc = $conn->query("SELECT HOUR(created_at) AS h, COUNT(*) AS c FROM alerts WHERE created_at >= (NOW() - INTERVAL 24 HOUR) GROUP BY HOUR(created_at)");
while ($r = $hc->fetch_assoc()) {
    $hours[(int)$r['h']] = (int)$r['c'];
}
$maxHour = max($hours);

/* Hospital registration / approval data */
$pendingHospitals = [];
$ph = $conn->query("SELECT id, name, reference, created_at FROM hospitals WHERE status = 'pending' ORDER BY created_at DESC");
while ($p = $ph->fetch_assoc()) {
    $pendingHospitals[] = $p;
}

$approvedHospitalsList = [];
$ah = $conn->query("SELECT id, name, lat, lng, chat_id, created_at FROM hospitals WHERE status = 'approved' ORDER BY name ASC");
while ($a = $ah->fetch_assoc()) {
    $approvedHospitalsList[] = $a;
}

$rejectedHospitalsList = [];
$rh = $conn->query("SELECT id, name, created_at FROM hospitals WHERE status = 'rejected' ORDER BY created_at DESC");
while ($r = $rh->fetch_assoc()) {
    $rejectedHospitalsList[] = $r;
}

$approvedCount = (int)$conn->query("SELECT COUNT(*) AS c FROM hospitals WHERE status = 'approved'")->fetch_assoc()['c'];
$pendingCount = (int)$conn->query("SELECT COUNT(*) AS c FROM hospitals WHERE status = 'pending'")->fetch_assoc()['c'];
$rejectedCount = (int)$conn->query("SELECT COUNT(*) AS c FROM hospitals WHERE status = 'rejected'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Command Center - Accident Alerts</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>

  <nav class="navbar">
    <a href="dashboard.php" class="brand">
      <span class="brand-badge">🛰️</span>
      Accident Alerts
    </a>
    <div class="nav-links">
      <div class="profile-wrap" id="profileWrap">
        <div class="profile-badge">
          <button class="avatar avatar-btn" id="profileBtn" title="Profile menu" aria-haspopup="true" aria-expanded="false">
            <?= htmlspecialchars(strtoupper(substr($username, 0, 1))) ?>
            <span class="status-dot" aria-hidden="true"></span>
          </button>
        </div>
        <div class="profile-menu" id="profileMenu" role="menu">
          <div class="profile-menu-head">
            <span class="avatar avatar-sm"><?= htmlspecialchars(strtoupper(substr($username, 0, 1))) ?></span>
            <div class="profile-user">
              <div class="profile-user-name"><?= htmlspecialchars($username) ?></div>
              <div class="profile-user-role"><?= $isAdmin ? 'Administrator' : 'Member' ?></div>
            </div>
          </div>
          <?php if ($isAdmin): ?>
          <button type="button" class="profile-item nav-draggable" id="addHospitalBtn" data-nav-key="addhospital">
            <span class="profile-item-icon icon-add">➕</span>
            <span class="profile-item-text">Add Hospital</span>
            <span class="grip" aria-hidden="true">⠿</span>
          </button>
          <?php endif; ?>
          <a href="logout.php" class="profile-item nav-draggable" data-nav-key="logout">
            <span class="profile-item-icon icon-logout">↪</span>
            <span class="profile-item-text">Log out</span>
            <span class="grip" aria-hidden="true">⠿</span>
          </a>
          <div class="profile-menu-foot">Drag items to reorder</div>
        </div>
      </div>
    </div>
  </nav>

  <main class="container">

    <div class="page-head">
      <div>
        <h1>Command Center</h1>
        <p style="color:var(--muted);font-size:0.95rem;margin-top:4px;">Real-time monitoring of incoming accident alerts</p>
      </div>
      <span class="badge-live"><span class="dot"></span> Live</span>
    </div>

    <?php if ($latest): ?>
    <section class="alert-banner">
      <div class="alert-banner-icon">🚨</div>
      <div class="alert-banner-body">
        <div class="alert-banner-title">Latest Accident Reported</div>
        <div class="alert-banner-info">
          <span>🏥 <strong><?= htmlspecialchars($latest['hospital']) ?></strong></span>
          <span>⏰ <?= htmlspecialchars($latest['created_at']) ?></span>
          <span>📍 <a class="btn btn-maps" href="https://maps.google.com/?q=<?= (float)$latest['lat'] ?>,<?= (float)$latest['lng'] ?>" target="_blank" rel="noopener">Open in Maps</a></span>
        </div>
      </div>
      <span class="badge-live badge-red"><span class="dot dot-red"></span> Newest</span>
    </section>
    <?php endif; ?>

    <section class="stats">
      <div class="stat-card">
        <div class="stat-label">Total Alerts</div>
        <div class="stat-value pri"><?= (int)$total ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Last 24h</div>
        <div class="stat-value grn"><?= (int)$recent ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Hospitals alerted</div>
        <div class="stat-value amb"><?= (int)$hospitals ?></div>
      </div>
    </section>

    <section class="stats stats-approvals">
      <div class="stat-card stat-compact">
        <div class="stat-label">Approved</div>
        <div class="stat-value grn"><?= (int)$approvedCount ?></div>
      </div>
      <div class="stat-card stat-compact">
        <div class="stat-label">Pending Approval</div>
        <div class="stat-value amb"><?= (int)$pendingCount ?></div>
      </div>
      <div class="stat-card stat-compact">
        <div class="stat-label">Rejected</div>
        <div class="stat-value sec"><?= (int)$rejectedCount ?></div>
      </div>
    </section>

    <?php if ($isAdmin && $pendingHospitals): ?>
    <section class="table-card" id="approvals">
      <div class="card-head">
        <h2>Pending Hospital Approvals</h2>
        <span class="alert-count"><?= (int)count($pendingHospitals) ?></span>
      </div>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th>Hospital</th>
              <th>Reference</th>
              <th>Location (admin verified)</th>
              <th>Requested</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingHospitals as $p): ?>
            <tr>
              <td>
                <span class="hospital-name"><?= htmlspecialchars($p['name']) ?></span>
                <span class="tag-new">PENDING</span>
              </td>
              <td>
                <?php if (!empty($p['reference'])): ?>
                  <?php if (filter_var($p['reference'], FILTER_VALIDATE_URL)): ?>
                    <a class="btn btn-maps btn-sm" href="<?= htmlspecialchars($p['reference']) ?>" target="_blank" rel="noopener">View link</a>
                  <?php else: ?>
                    <span class="coords"><?= htmlspecialchars($p['reference']) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="coords">—</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="coord-fields">
                  <div class="coord-field">
                    <label class="coord-label"><span class="coord-dot dot-lat"></span> Lat</label>
                    <input class="coord-input" type="text" placeholder="8.5539" data-lat="<?= (int)$p['id'] ?>">
                  </div>
                  <div class="coord-field">
                    <label class="coord-label"><span class="coord-dot dot-lng"></span> Lng</label>
                    <input class="coord-input" type="text" placeholder="39.2780" data-lng="<?= (int)$p['id'] ?>">
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars($p['created_at']) ?></td>
              <td style="text-align:right;">
                <button class="btn btn-approve" data-action="approve" data-id="<?= (int)$p['id'] ?>">✓ Approve</button>
                <button class="btn btn-reject" data-action="reject" data-id="<?= (int)$p['id'] ?>">✕ Reject</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="panel-note" style="padding:0 1rem 1rem;">Enter the verified coordinates before approving — the bridge routes alerts to the nearest approved hospital.</p>
    </section>
    <?php endif; ?>

    <?php if ($approvedHospitalsList || $rejectedHospitalsList): ?>
    <section class="charts charts-hospitals">
      <div class="panel">
        <div class="panel-head">
          <h2>Approved Hospitals</h2>
          <div class="panel-tools">
            <span class="panel-note"><?= (int)$approvedCount ?> routed to</span>
          </div>
        </div>
        <?php if ($approvedHospitalsList): ?>
        <div class="hosp-list">
          <?php foreach ($approvedHospitalsList as $a): ?>
          <div class="hosp-card">
            <div class="hosp-card-icon">🏥</div>
            <div class="hosp-card-body">
              <div class="hosp-card-name"><?= htmlspecialchars($a['name']) ?></div>
              <div class="hosp-card-coords">📍 <?= (float)$a['lat'] ?>, <?= (float)$a['lng'] ?></div>
            </div>
            <a class="btn btn-maps btn-sm hosp-card-map" href="https://maps.google.com/?q=<?= (float)$a['lat'] ?>,<?= (float)$a['lng'] ?>" target="_blank" rel="noopener">🗺 View on Map</a>
            <?php if ($isAdmin): ?>
            <button class="btn-del" data-del="<?= (int)$a['id'] ?>" data-del-name="<?= htmlspecialchars($a['name'], ENT_QUOTES) ?>" title="Delete hospital">🗑</button>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-small">No approved hospitals yet.</div>
        <?php endif; ?>
      </div>

      <div class="panel">
        <div class="panel-head">
          <h2>Rejected Hospitals</h2>
          <span class="panel-note"><?= (int)$rejectedCount ?> never routed</span>
        </div>
        <?php if ($rejectedHospitalsList): ?>
        <div class="breakdown">
          <?php foreach ($rejectedHospitalsList as $r): ?>
          <div class="break-row">
            <div class="break-label"><?= htmlspecialchars($r['name']) ?></div>
            <span class="break-actions">
              <span class="coords"><?= htmlspecialchars($r['created_at']) ?></span>
              <?php if ($isAdmin): ?>
              <button class="btn-del" data-del="<?= (int)$r['id'] ?>" data-del-name="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>" title="Delete hospital">🗑</button>
              <?php endif; ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-small">No rejected hospitals.</div>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="charts">
      <div class="panel">
        <div class="panel-head">
          <h2>Activity — Last 24h</h2>
          <span class="panel-note">alerts per hour</span>
        </div>
        <div class="chart" role="img" aria-label="Alerts per hour over the last 24 hours">
          <?php for ($i = 0; $i < 24; $i++): ?>
            <?php $v = $hours[$i]; $pct = $maxHour > 0 ? (int)round($v / $maxHour * 100) : 0; ?>
            <div class="chart-col" title="<?= $i ?>:00 - <?= $v ?> alert(s)">
              <span class="chart-val"><?= $v ?></span>
              <span class="chart-bar" style="height:<?= max($pct, $v > 0 ? 4 : 1) ?>%;"></span>
              <span class="chart-label"><?= str_pad((string)$i, 2, '0', STR_PAD_LEFT) ?>h</span>
            </div>
          <?php endfor; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">
          <h2>Alerts by Hospital</h2>
          <span class="panel-note"><?= (int)count($breakdown) ?> with alerts</span>
        </div>
        <?php if ($breakdown): ?>
        <div class="breakdown">
          <?php foreach ($breakdown as $b): ?>
          <div class="break-row">
            <div class="break-label"><?= htmlspecialchars($b['hospital']) ?></div>
            <div class="break-track"><div class="break-fill" style="width:<?= $total > 0 ? round($b['c'] / $total * 100) : 0 ?>%;"></div></div>
            <div class="break-val"><?= (int)$b['c'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-small">No hospital activity yet.</div>
        <?php endif; ?>
      </div>
    </section>

    <section class="table-card">
      <div class="card-head">
        <h2>Incoming Alerts</h2>
        <span class="alert-count"><?= (int)$total ?></span>
      </div>
      <div class="table-scroll">
        <?php if ($total > 0): ?>
        <table>
          <thead>
            <tr>
              <th>Time</th>
              <th>Hospital</th>
              <th>Location</th>
              <th>Coordinates</th>
            </tr>
          </thead>
          <tbody>
            <?php $i = 0; while ($row = $result->fetch_assoc()): ?>
            <tr class="<?= $i === 0 ? 'row-latest' : '' ?>">
              <td><?= htmlspecialchars($row['created_at']) ?></td>
              <td>
                <span class="hospital-name"><?= htmlspecialchars($row['hospital']) ?></span>
                <?php if ($i === 0): ?><span class="tag-new">NEW</span><?php endif; ?>
              </td>
              <td>
                <a class="btn btn-maps" href="https://maps.google.com/?q=<?= (float)$row['lat'] ?>,<?= (float)$row['lng'] ?>" target="_blank" rel="noopener">📍 View on Map</a>
              </td>
              <td><span class="coords"><?= htmlspecialchars($row['lat']) ?>, <?= htmlspecialchars($row['lng']) ?></span></td>
            </tr>
            <?php $i++; endwhile; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">📭</div>
          <p>No incoming alerts right now.</p>
          <p style="font-size:0.85rem;">The dashboard refreshes automatically every 10 seconds.</p>
        </div>
        <?php endif; ?>
      </div>
    </section>

  </main>

  <footer class="footer">
    Accident Alerts Command Center · auto-refreshes every 10 seconds
  </footer>

  <script>
    /* ---------- Profile menu (open/close + drag-and-drop reorder) ---------- */
    (function () {
      var NAV_ORDER_KEY = 'nav_order';
      var wrap = document.getElementById('profileWrap');
      var menu = document.getElementById('profileMenu');
      var btn = document.getElementById('profileBtn');
      if (!wrap || !menu || !btn) return;

      function setOpen(open) {
        menu.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
      window.closeProfileMenu = function () { setOpen(false); };

      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        setOpen(!menu.classList.contains('open'));
      });
      document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target)) setOpen(false);
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setOpen(false);
      });

      /* Drag-and-drop reordering (persisted per browser) */
      function readOrder() {
        try { return JSON.parse(localStorage.getItem(NAV_ORDER_KEY) || 'null'); }
        catch (e) { return null; }
      }
      function writeOrder() {
        var order = Array.prototype.map.call(menu.children, function (el) {
          return el.dataset.navKey;
        });
        localStorage.setItem(NAV_ORDER_KEY, JSON.stringify(order));
      }
      function applyOrder() {
        var order = readOrder();
        if (!order || !order.length) return;
        var items = Array.prototype.slice.call(menu.children);
        items.sort(function (a, b) {
          var ia = order.indexOf(a.dataset.navKey);
          var ib = order.indexOf(b.dataset.navKey);
          if (ia === -1) ia = order.length + items.indexOf(a);
          if (ib === -1) ib = order.length + items.indexOf(b);
          return ia - ib;
        });
        items.forEach(function (item) { menu.appendChild(item); });
      }

      var dragEl = null;
      Array.prototype.forEach.call(menu.children, function (item) {
        item.addEventListener('dragstart', function (e) {
          dragEl = this;
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', '');
          this.classList.add('drag-source');
        });
        item.addEventListener('dragover', function (e) {
          if (dragEl && this !== dragEl) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
          }
        });
        item.addEventListener('drop', function (e) {
          if (!dragEl || this === dragEl) return;
          e.preventDefault();
          var rect = this.getBoundingClientRect();
          var after = (e.clientY - rect.top) > rect.height / 2;
          if (after) {
            if (this.nextSibling) menu.insertBefore(dragEl, this.nextSibling);
            else menu.appendChild(dragEl);
          } else {
            menu.insertBefore(dragEl, this);
          }
          writeOrder();
        });
        item.addEventListener('dragend', function () {
          this.classList.remove('drag-source');
          dragEl = null;
        });
      });
      applyOrder();
    })();
  </script>

  <script>
    /* Auto-refresh driven by JS instead of <meta http-equiv="refresh">.
       Never fires while a modal is open, and resets on user typing so a
       reload can't wipe what's being entered. */
    (function () {
      var REFRESH_MS = 10000;
      var timer = null;
      var paused = false;

      function isModalOpen() {
        return document.querySelector('.modal.open') !== null;
      }
      function schedule() {
        if (paused || isModalOpen()) return;
        if (timer) clearTimeout(timer);
        timer = setTimeout(function () { timer = null; location.reload(); }, REFRESH_MS);
      }
      function pause() { paused = true; if (timer) { clearTimeout(timer); timer = null; } }
      function resume() { paused = false; schedule(); }

      window.RefreshGuard = { pause: pause, resume: resume, schedule: schedule };

      document.addEventListener('input', schedule);
      document.addEventListener('click', function (e) {
        var target = e.target || document;
        if (typeof target.closest === 'function' && target.closest('.modal')) return;
        schedule();
      });
      document.addEventListener('keydown', schedule);
      schedule();
    })();
  </script>

  <?php if ($isAdmin): ?>
  <div id="addModal" class="modal" aria-hidden="true">
    <div class="modal-backdrop" data-close></div>
    <div class="modal-card">
      <div class="modal-head">
        <h3>➕ Add Hospital</h3>
        <button class="modal-close" data-close aria-label="Close">✕</button>
      </div>
      <form id="addHospitalForm">
        <div class="modal-body">
          <p class="modal-hint">Adds the hospital straight to the approved list — the bridge will route alerts to its chat.</p>
          <div class="form-group">
            <label for="add_name">Hospital name</label>
            <input id="add_name" name="name" type="text" placeholder="e.g. Yoya General Hospital" required>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label for="add_lat">Latitude</label>
              <input id="add_lat" name="lat" type="text" placeholder="8.5404" required>
            </div>
            <div class="form-group">
              <label for="add_lng">Longitude</label>
              <input id="add_lng" name="lng" type="text" placeholder="39.2598" required>
            </div>
          </div>
          <div class="form-group">
            <label for="add_chat">Telegram chat ID <span class="optional">(optional)</span></label>
            <input id="add_chat" name="chat_id" type="text" placeholder="379998469">
          </div>
        </div>
        <div class="modal-foot">
          <button type="button" class="btn btn-sm btn-maps" data-close>Cancel</button>
          <button type="submit" class="btn btn-approve btn-sm">Save</button>
        </div>
      </form>
    </div>
  </div>

  <div id="confirmModal" class="modal" aria-hidden="true">
    <div class="modal-backdrop" data-cclose></div>
    <div class="modal-card">
      <div class="modal-head">
        <h3>🗑 Delete hospital</h3>
        <button class="modal-close" data-cclose aria-label="Close">✕</button>
      </div>
      <div class="modal-body">
        <p id="confirmText" style="font-size:0.92rem;">Permanently delete this hospital?</p>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-sm btn-maps" data-cclose>Cancel</button>
        <button type="button" id="confirmDeleteBtn" class="btn btn-reject btn-sm">Delete</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div id="toast" class="toast" role="alert" aria-live="assertive"></div>

  <?php if ($isAdmin): ?>
  <script>
    function showToast(msg, type) {
      var t = document.getElementById('toast');
      t.textContent = msg;
      t.className = 'toast show ' + (type || 'info');
      clearTimeout(window._toastTimer);
      window._toastTimer = setTimeout(function () { t.className = 'toast'; }, 3800);
    }

    /* Restore coord inputs lost to the 10-second auto-refresh */
    document.querySelectorAll('.coord-input').forEach(function (inp) {
      var latKey = 'coord_lat_' + (inp.dataset.lat || '');
      var lngKey = 'coord_lng_' + (inp.dataset.lng || '');
      var key = inp.dataset.lat ? latKey : lngKey;
      if (localStorage.getItem(key)) inp.value = localStorage.getItem(key);
    });
    document.querySelectorAll('.coord-input').forEach(function (inp) {
      inp.addEventListener('input', function () {
        var latKey = 'coord_lat_' + (inp.dataset.lat || '');
        var lngKey = 'coord_lng_' + (inp.dataset.lng || '');
        var key = inp.dataset.lat ? latKey : lngKey;
        if (inp.value.trim()) localStorage.setItem(key, inp.value.trim());
        else localStorage.removeItem(key);
      });
    });

    /* Persist the Add Hospital modal fields so a reload doesn't lose them. */
    var ADD_FIELDS = ['add_name', 'add_lat', 'add_lng', 'add_chat'];
    ADD_FIELDS.forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      if (localStorage.getItem('add_' + id)) el.value = localStorage.getItem('add_' + id);
      el.addEventListener('input', function () {
        if (el.value.trim()) localStorage.setItem('add_' + id, el.value.trim());
        else localStorage.removeItem('add_' + id);
      });
    });

    document.querySelectorAll('#approvals [data-action]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var action = btn.dataset.action;
        var id = btn.dataset.id;
        var body = 'id=' + id + '&action=' + action;
        if (action === 'approve') {
          var latIn = document.querySelector('[data-lat="' + id + '"]');
          var lngIn = document.querySelector('[data-lng="' + id + '"]');
          if (!latIn.value.trim() || !lngIn.value.trim()) {
            showToast('Enter the hospital\u2019s latitude and longitude before approving.', 'warn');
            return;
          }
          body += '&lat=' + encodeURIComponent(latIn.value.trim()) +
                  '&lng=' + encodeURIComponent(lngIn.value.trim());
          /* Clear saved values before reloading */
          localStorage.removeItem('coord_lat_' + id);
          localStorage.removeItem('coord_lng_' + id);
        }
        btn.disabled = true;
        btn.textContent = '...';
        fetch('approve_hospital.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body
        })
        .then(function (r) { return r.json(); })
        .then(function () {
          showToast(action === 'approve' ? 'Hospital approved.' : 'Hospital rejected.', 'success');
          location.reload();
        })
        .catch(function () {
          btn.disabled = false;
          btn.textContent = '❌ Retry';
          showToast('Something went wrong reaching the server. Please retry.', 'error');
        });
      });
    });

    /* ----- Add Hospital modal ----- */
    var addModal = document.getElementById('addModal');
    var addForm = document.getElementById('addHospitalForm');

    function pauseRefresh() {
      if (window.RefreshGuard) window.RefreshGuard.pause();
    }
    function resumeRefresh() {
      if (window.RefreshGuard) window.RefreshGuard.resume();
    }
    function openAddModal(resetForm) {
      sessionStorage.setItem('add_modal_open', '1');
      pauseRefresh();
      addModal.classList.add('open');
      document.body.classList.add('modal-open');
      if (resetForm) {
        addForm.reset();
        setTimeout(function () { addForm.elements.name.focus(); }, 60);
      }
    }
    function closeAddModal() {
      sessionStorage.removeItem('add_modal_open');
      addModal.classList.remove('open');
      document.body.classList.remove('modal-open');
      resumeRefresh();
    }

    /* If a refresh still slipped through, reopen the modal without touching the form. */
    if (sessionStorage.getItem('add_modal_open') === '1') openAddModal(false);

    document.getElementById('addHospitalBtn').addEventListener('click', function () {
      openAddModal(true);
      if (window.closeProfileMenu) window.closeProfileMenu();
    });
    document.querySelectorAll('#addModal [data-close]').forEach(function (el) {
      el.addEventListener('click', closeAddModal);
    });
    addModal.addEventListener('click', function (e) {
      if (e.target === addModal) closeAddModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && addModal.classList.contains('open')) closeAddModal();
    });

    addForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = addForm.querySelector('[type="submit"]');
      btn.disabled = true;
      btn.textContent = '⏳ Saving…';
      fetch('add_hospital.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(new FormData(addForm)).toString()
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.status === 'ok') {
          ADD_FIELDS.forEach(function (id) { localStorage.removeItem('add_' + id); });
          closeAddModal();
          showToast('Hospital added to the approved list.', 'success');
          location.reload();
        } else {
          btn.disabled = false;
          btn.textContent = 'Save';
          showToast(res.message, 'error');
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Save';
        showToast('Something went wrong reaching the server.', 'error');
      });
    });

    /* ----- Delete hospital (confirm modal) ----- */
    var confirmModal = document.getElementById('confirmModal');
    var pendingDeleteId = null;

    function openConfirm(name, id) {
      document.getElementById('confirmText').textContent =
        'Permanently delete "' + name + '"? Alerts already recorded stay on the dashboard, but this hospital will no longer receive accident alerts. Its Telegram contact will be notified and can register a new hospital.';
      pendingDeleteId = id;
      sessionStorage.setItem('confirm_modal_open', JSON.stringify({ id: id, name: name }));
      pauseRefresh();
      confirmModal.classList.add('open');
      document.body.classList.add('modal-open');
    }
    function closeConfirm() {
      sessionStorage.removeItem('confirm_modal_open');
      confirmModal.classList.remove('open');
      document.body.classList.remove('modal-open');
      resumeRefresh();
      pendingDeleteId = null;
    }

    var storedConfirm = sessionStorage.getItem('confirm_modal_open');
    if (storedConfirm) {
      try {
        var d = JSON.parse(storedConfirm);
        if (d && d.id) openConfirm(d.name || 'this hospital', d.id);
      } catch (e) { /* ignore */ }
    }

    document.querySelectorAll('[data-del]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openConfirm(btn.dataset.delName, btn.dataset.del);
      });
    });
    document.querySelectorAll('#confirmModal [data-cclose]').forEach(function (el) {
      el.addEventListener('click', closeConfirm);
    });
    confirmModal.addEventListener('click', function (e) {
      if (e.target === confirmModal) closeConfirm();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && confirmModal.classList.contains('open')) closeConfirm();
    });

    document.getElementById('confirmDeleteBtn').addEventListener('click', function () {
      var btn = this;
      btn.disabled = true;
      btn.textContent = '⏳ Deleting…';
      fetch('delete_hospital.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + pendingDeleteId
      })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.status === 'ok') {
          showToast('Hospital deleted.', 'success');
          closeConfirm();
          location.reload();
        } else {
          btn.disabled = false;
          btn.textContent = 'Delete';
          showToast(res.message, 'error');
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Delete';
        showToast('Something went wrong reaching the server.', 'error');
      });
    });
  </script>
  <?php endif; ?>

</body>
</html>