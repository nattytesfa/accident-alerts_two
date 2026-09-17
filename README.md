# Accident Alerts

Real-time accident alert notification system for hospitals. An Arduino sends GPS accident data over serial to a Python bridge, which computes the **nearest registered hospital**, sends the alert to that hospital's **Telegram** chat, and stores it on a live **web dashboard** with Google Maps integration.

## How It Works

```
Arduino (GPS + sensors)          Hospitals register via Telegram bot
    |  Serial / USB (GPS only)          ↓ (pending)
    v                            admin approves on dashboard
telegram_bridge.py               →    MySQL (approved list)
    |            \                         ↓
    v             v              bridge fetches approved list
Telegram        endpoint.php  (PHP)
                 |            |
                 v            v
             MySQL DB --> dashboard.php  (auto-refreshes every 10s)
```

The Arduino stays dumb: it only reports the accident's GPS coordinates.
The **bridge** decides which hospital is nearest (Haversine distance) and which
Telegram chat to notify — so new hospitals work immediately after approval,
with no re-upload and no RAM pressure on the Arduino.

## Features

- Live command center dashboard with key statistics
- Hospital registration **via the Telegram bot** + admin approval workflow
- Alerts routed to the **nearest approved hospital** automatically
- One-click Google Maps links for every accident location
- Instant Telegram notifications with structured alert messages
- Secure login system with password hashing
- Auto-refreshing dashboard (every 10 seconds)

## Prerequisites

| Component | Version | Purpose |
|-----------|---------|---------|
| PHP | 7.4+ | Web dashboard and API endpoints |
| MySQL / MariaDB | 5.7+ | Database |
| XAMPP / WAMP / LAMP | — | Apache + MySQL stack |
| Python | 3.7+ | Serial-to-Telegram bridge |
| Arduino board | — | Reads GPS, sends alert over serial |

### Python dependencies

```
pip install pyserial requests
```

## Quick Start

### 1. Clone the repository

```bash
git clone https://github.com/your-username/accident-alerts.git
cd accident-alerts
```

### 2. Set up the database

```bash
mysql -u root < setup.sql
```

> If you have a MySQL password, use: `mysql -u root -p < setup.sql`

### 3. Configure the database connection

Edit `db.php` if your MySQL credentials differ. The single admin account
(bot-request approver) is `ADMIN_USERNAME` (default `admin`).

### 4. Start the web dashboard

Start your XAMPP/LAMP stack (Apache + MySQL) and open:

```
http://localhost/accident-alerts/login.php
```

Register an account and sign in.

### 5. Create a Telegram bot

1. Open Telegram and message **[@BotFather](https://t.me/BotFather)**
2. Send `/newbot`, then choose a name (e.g. `Accident Alert Bot`)
3. Choose a username (must end in `bot`, e.g. `youraccidentalert_bot`)
4. BotFather replies with a **token** — copy it

### 6. Configure the Python bridge

Expose the bot token via environment (do not hardcode it):

```bash
export ACCIDENT_ALERTS_BOT_TOKEN="1234567890:AAHf..."
```

Other optional overrides:

| Env var | Default |
|---------|---------|
| `ACCIDENT_ALERTS_BOT_TOKEN` | (none — required) |
| `ACCIDENT_ALERTS_CHAT_ID` | nearest hospital's chat_id (single shared alert chat) |
| `ACCIDENT_ALERTS_SERIAL_PORT` | auto-detected `/dev/ttyACM*` / `/dev/ttyUSB*` |
| `ACCIDENT_ALERTS_SERVER` | `http://localhost/accident-alerts` |

### 7. Run the bridge

```bash
python3 telegram_bridge.py
```

The bridge opens the Arduino serial port and polls Telegram:

- **`/start`** — welcome + help
- **`/registerhospital <name>`** — a hospital sends a registration request; the
  bot auto-uses their chat ID and optionally takes a Google Maps link/coordinates
  as a reference for the admin. It is stored as **pending**.
- **`/hospitals`** — list approved hospitals

> The Arduino must not be uploading / Serial Monitor must not be open while the
> bridge runs — only one program may use the serial port at a time.

## Hospital Registration & Approval

1. Hospital sends `/registerhospital <name>` to the bot (their chat ID is
   auto-detected; they can paste a Maps link as a reference, or `skip`).
2. Admin signs in to the dashboard → **Pending Hospital Approvals** panel.
3. Admin opens the reference link, enters the **verified latitude/longitude**,
   clicks **Approve** (or **Reject**).
4. The hospital's chat gets an **approved ✅ / rejected ❌** Telegram notice.
5. Approved hospitals appear in the dashboard list and are used by the bridge
   for nearest-hospital routing. Rejected ones are never routed to.

Admins can also **add hospitals manually**: the "➕ Add Hospital" button on the
dashboard (admin account only) opens a form that saves the hospital straight to
the approved list with its coordinates and optional Telegram chat ID.

## Arduino Serial Format

The bridge expects alerts over serial. Only `LAT`/`LNG` are required — the
bridge ignores any hospital fields the sketch also sends:

```
===ALERT_START===
LAT:38.761700
LNG:-9.139400
===ALERT_END===
```

| Field | Required | Description |
|-------|----------|-------------|
| `LAT` | yes | GPS latitude of the accident |
| `LNG` | yes | GPS longitude of the accident |
| `HOSPITAL` | no | ignored — bridge picks the nearest approved hospital |
| `CHATID` | no | ignored unless no approved hospital matches |
| `DISTANCE` / `MAPLINK` | no | optional, otherwise built by the bridge |

## HTTP Endpoint API

`endpoint.php` can be called directly via HTTP POST (without the Python bridge):

```bash
curl -X POST http://localhost/accident-alerts/endpoint.php \
  -d "lat=38.7617&lng=-9.1394&hospital=Central+General+Hospital"
```

## Project Structure

```
accident-alerts/
├── setup.sql              # Database schema (alerts, users, hospitals, notifications)
├── db.php                 # Database connection + admin helpers
├── login.php              # User login page
├── register.php           # Account registration
├── logout.php             # Session logout
├── auth_check.php         # Authentication middleware
├── dashboard.php          # Live command center dashboard (+ hospital approvals)
├── endpoint.php           # HTTP API for receiving alerts
├── register_hospital.php  # Bot-facing API — saves a hospital request as pending
├── add_hospital.php       # Admin-only — adds an approved hospital manually
├── delete_hospital.php    # Admin-only — permanently deletes any hospital
├── approve_hospital.php   # Admin-only approve/reject + queues notification
├── hospitals_sync.php     # API returning APPROVED hospitals (used by bridge)
├── pending_notifications.php  # Unsent approve/reject notices (bridge consumes)
├── mark_notifications.php     # Marks notices delivered after send
├── styles.css             # Professional UI stylesheet
├── telegram_bridge.py     # Arduino → Telegram + dashboard bridge (nearest routing)
└── README.md
```

## Security Notes

- Passwords are hashed with `password_hash()` (bcrypt) — never stored in plain text
- SQL queries use prepared statements to prevent injection
- All HTML output is escaped with `htmlspecialchars()` to prevent XSS
- The bot token is read from the `ACCIDENT_ALERTS_BOT_TOKEN` environment
  variable — never commit it to the repository