import glob
import math
import os
import serial
import requests
import time
from datetime import datetime

# --- CONFIGURATION ---
SERIAL_PORT = os.environ.get("ACCIDENT_ALERTS_SERIAL_PORT", "COM3")
BAUD_RATE = 9600
BOT_TOKEN = os.environ.get("ACCIDENT_ALERTS_BOT_TOKEN")
if not BOT_TOKEN:
    raise SystemExit("Set ACCIDENT_ALERTS_BOT_TOKEN to your Telegram bot token.")

# Fallback chat that receives accident alerts when the nearest hospital has no
# chat_id of its own. The bridge picks the nearest approved hospital by lat/lng
# and sends the alert to that hospital's registered Telegram chat first; only
# if it has none does it fall back to this shared chat below.
ALERT_CHAT_ID = os.environ.get("ACCIDENT_ALERTS_CHAT_ID", "379998469")

# PHP web app endpoints
SERVER_BASE = os.environ.get("ACCIDENT_ALERTS_SERVER", "http://localhost/accident-alerts")
SERVER_URL = SERVER_BASE + "/endpoint.php"
HOSPITALS_SYNC_URL = SERVER_BASE + "/hospitals_sync.php"
CHECK_HOSPITAL_URL = SERVER_BASE + "/check_hospital.php"
REGISTER_HOSPITAL_URL = SERVER_BASE + "/register_hospital.php"
NOTIFICATIONS_URL = SERVER_BASE + "/pending_notifications.php"
NOTIFICATIONS_MARK_URL = SERVER_BASE + "/mark_notifications.php"

# How often (seconds) to check for approval/rejection notifications.
NOTIFICATION_CHECK_INTERVAL = 10

def find_serial_port():
    """Return the first connected Arduino, else the configured fallback."""
    for pattern in ("/dev/ttyACM*", "/dev/ttyUSB*"):
        matches = sorted(glob.glob(pattern))
        if matches:
            return matches[0]
    return SERIAL_PORT

def send_telegram_message(chat_id, message):
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/sendMessage"
    payload = {
        'chat_id': chat_id,
        'text': message
    }
    try:
        response = requests.post(url, json=payload)
        response.raise_for_status()
        print(f"[{datetime.now().strftime('%H:%M:%S')}] Sent to chat {chat_id}")
        return True
    except Exception as e:
        print(f"Error sending Telegram: {e}")
        return False

def tg_get_updates(offset):
    """Poll Telegram for new incoming messages."""
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/getUpdates"
    try:
        response = requests.get(url, params={"offset": offset, "timeout": 0}, timeout=15)
        response.raise_for_status()
        return response.json().get("result", [])
    except Exception as e:
        print(f"[{datetime.now().strftime('%H:%M:%S')}] Error polling Telegram: {e}")
        return []

registration_state = {}

def fetch_hospitals():
    """Return approved hospitals from the PHP backend as a list of dicts."""
    try:
        r = requests.get(HOSPITALS_SYNC_URL, timeout=10)
        r.raise_for_status()
        return r.json()
    except Exception as e:
        print(f"[{datetime.now().strftime('%H:%M:%S')}] Error fetching hospitals: {e}")
        return []

def check_hospital(name="", chat_id=""):
    """Return registration status for a name/chat_id, or {} on error."""
    params = {}
    if name:
        params["name"] = name
    if chat_id:
        params["chat_id"] = str(chat_id)
    try:
        r = requests.get(CHECK_HOSPITAL_URL, params=params, timeout=10)
        r.raise_for_status()
        return r.json()
    except Exception as e:
        print(f"[{datetime.now().strftime('%H:%M:%S')}] Error checking hospital: {e}")
        return {}

def already_registered_message(status):
    """Human-readable refusal when a hospital name/account already exists."""
    state = (status or {}).get("status") or "registered"
    return (f"⚠️ That hospital is already *{state}*. "
            f"Only one registration per hospital and per Telegram account is allowed.\n"
            f"Contact the admin to make changes.")

def haversine_km(lat1, lng1, lat2, lng2):
    r = 6371.0
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dp = math.radians(lat2 - lat1)
    dl = math.radians(lng2 - lng1)
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(math.sqrt(a))

def nearest_hospital(hospitals, lat, lng):
    """Return the nearest hospital dict and distance in km, or (None, None)."""
    best = None
    best_dist = None
    for h in hospitals:
        try:
            d = haversine_km(lat, lng, float(h["lat"]), float(h["lng"]))
        except (KeyError, TypeError, ValueError):
            continue
        if best_dist is None or d < best_dist:
            best, best_dist = h, d
    return best, best_dist

def handle_command(chat_id, text):
    if text == "/start" or text == "/help":
        send_telegram_message(
            chat_id,
            "Welcome to the Accident Alerts Bot! 🚨\n\n"
            "Commands:\n"
            "/registerhospital - start a hospital registration request\n"
            "/hospitals - list approved hospitals",
        )
        return

    if text == "/hospitals":
        hospitals = fetch_hospitals()
        if not hospitals:
            send_telegram_message(chat_id, "No hospitals approved yet.")
        else:
            msg = "🏥 Approved hospitals:\n\n"
            for h in hospitals:
                msg += f"• {h['name']} ({h['lat']:.6f}, {h['lng']:.6f})\n"
            send_telegram_message(chat_id, msg)
        return

    if text == "/registerhospital" or text.startswith("/registerhospital "):
        # One Telegram account may only register one hospital.
        status = check_hospital(chat_id=chat_id)
        if status.get("chat_exists"):
            send_telegram_message(
                chat_id,
                "⚠️ This Telegram account has already registered a hospital.\n"
                "Only one registration per account is allowed — contact the admin to make changes.",
            )
            return

        rest = text[len("/registerhospital"):].strip()
        if rest:
            # /registerhospital Hospital Name  → straight to location step
            status = check_hospital(name=rest)
            if status.get("name_exists"):
                send_telegram_message(chat_id, already_registered_message(status))
                return
            registration_state[chat_id] = {"step": "link", "name": rest}
            send_telegram_message(
                chat_id,
                f"📍 Hospital *{rest}*\n\n"
                f"📎 Send the Google Maps link or coordinates of its location.\n"
                f"Send `skip` to continue without it.",
            )
        else:
            # /registerhospital  → ask for the name first
            registration_state[chat_id] = {"step": "name"}
            send_telegram_message(chat_id, "🏥 What is the hospital name?")
        return

    # Driver for the registration conversation
    if chat_id in registration_state:
        step = registration_state[chat_id]["step"]

        if step == "name":
            name = text.strip()[:100]
            if name:
                status = check_hospital(name=name)
                if status.get("name_exists"):
                    registration_state.pop(chat_id, None)
                    send_telegram_message(chat_id, already_registered_message(status))
                    return
                registration_state[chat_id]["name"] = name
                registration_state[chat_id]["step"] = "link"
                send_telegram_message(
                    chat_id,
                    f"📍 Hospital *{name}*\n\n"
                    f"📎 Send the Google Maps link or coordinates of its location.\n"
                    f"Send `skip` to continue without it.",
                )
            else:
                send_telegram_message(chat_id, "🏥 What is the hospital name?")
        elif step == "link":
            if text.strip().lower() == "skip":
                registration_state[chat_id]["reference"] = ""
            else:
                registration_state[chat_id]["reference"] = text.strip()[:300]
            complete_registration(chat_id, registration_state[chat_id])
        else:
            send_telegram_message(chat_id, "Send the Google Maps link or coordinates, please.")
    else:
        send_telegram_message(
            chat_id,
            "Send /registerhospital to start a hospital registration request.",
        )

def complete_registration(chat_id, state):
    name = state["name"]
    reference = state.get("reference", "")

    try:
        r = requests.post(
            REGISTER_HOSPITAL_URL,
            data={"name": name, "chat_id": chat_id, "reference": reference},
            timeout=10,
        )
        print(f"[{datetime.now().strftime('%H:%M:%S')}] register_hospital: HTTP {r.status_code} {r.text}")
        try:
            res = r.json()
        except Exception:
            res = {}
        if res.get("status") == "ok":
            send_telegram_message(
                chat_id,
                f"✅ Registration request received for *{name}*.\n"
                f"⏳ Waiting for *admin approval*. You'll be notified of the decision.",
            )
        else:
            send_telegram_message(
                chat_id,
                f"⚠️ {res.get('message', 'Could not save the request.')}",
            )
    except Exception as e:
        print(f"[{datetime.now().strftime('%H:%M:%S')}] Error registering hospital: {e}")
        send_telegram_message(chat_id, "❌ Could not save the request. Check the server is running.")

    registration_state.pop(chat_id, None)

def process_notifications():
    """Send queued approved/rejected notices, then mark them delivered."""
    try:
        r = requests.get(NOTIFICATIONS_URL, timeout=10)
        r.raise_for_status()
        notices = r.json()
    except Exception as e:
        print(f"[{datetime.now().strftime('%H:%M:%S')}] Error fetching notifications: {e}")
        return

    if not notices:
        return

    sent_ids = []
    for n in notices:
        chat_id = n.get("chat_id")
        if not chat_id:
            continue
        if n.get("type") == "approved":
            msg = (f"🏥 Hospital *{n['hospital']}* was APPROVED ✅\n\n"
                   f"It is now active for accident alert routing.")
        else:
            msg = (f"🏥 Hospital *{n['hospital']}* was REJECTED ❌\n\n"
                   f"It will NOT receive accident alerts.")
        if send_telegram_message(chat_id, msg):
            sent_ids.append(str(n["id"]))

    if sent_ids:
        try:
            requests.post(NOTIFICATIONS_MARK_URL, data={"ids": ",".join(sent_ids)}, timeout=10)
        except Exception as e:
            print(f"[{datetime.now().strftime('%H:%M:%S')}] Error marking notifications: {e}")

def save_alert_to_dashboard(hospital, lat, lng):
    data = {
        "lat": lat,
        "lng": lng,
        "hospital": hospital,
    }
    try:
        response = requests.post(SERVER_URL, data=data, timeout=10)
        print(f"[{datetime.now().strftime('%H:%M:%S')}] Dashboard save: "
              f"HTTP {response.status_code} {response.text}")
    except Exception as e:
        print(f"[{datetime.now().strftime('%H:%M:%S')}] Error saving to dashboard: {e}")

def format_alert(hospital, lat, lng, distance, maplink, routed):
    now = datetime.now().strftime("%H:%M:%S")
    if routed:
        routing = (f"🏥 Nearest Hospital:\n{hospital}\n\n"
                   f"📏 Distance:\n{distance} km")
    else:
        routing = "🏥 Nearest Hospital:\nNo hospital available"
    return (
        f"🚨 ACCIDENT ALERT 🚨\n\n"
        f"An accident has been detected!\n\n"
        f"📍 Location:\n{maplink or 'Unknown'}\n\n"
        f"{routing}\n\n"
        f"⏰ Time: {now}\n\n"
        f"Please respond immediately!"
    )

def main():
    port = find_serial_port()
    print(f"Opening serial port {port}...")
    try:
        ser = serial.Serial(port, BAUD_RATE, timeout=1)
        time.sleep(2)
        print("Listening for Arduino alerts...")
    except Exception as e:
        print(f"Error opening serial port: {e}")
        return

    in_alert = False
    hospital = ""
    chat_id = ""
    lat = ""
    lng = ""
    distance = ""
    maplink = ""
    last_update_id = 0
    last_poll = 0
    last_notify_check = 0

    while True:
        try:
            # Poll Telegram for commands (~every 1s) so /start works
            if time.time() - last_poll >= 1:
                last_poll = time.time()
                for update in tg_get_updates(last_update_id + 1):
                    if int(update["update_id"]) > last_update_id:
                        last_update_id = int(update["update_id"])
                    msg = update.get("message", {})
                    txt = msg.get("text", "")
                    if txt:
                        handle_command(msg["chat"]["id"], txt)

            # Forward approval/rejection notifications to hospital chats (~every 10s)
            if time.time() - last_notify_check >= NOTIFICATION_CHECK_INTERVAL:
                last_notify_check = time.time()
                process_notifications()

            if ser.in_waiting > 0:
                line = ser.readline().decode('utf-8', errors='ignore').strip()

                if line == "===ALERT_START===":
                    in_alert = True
                    hospital = chat_id = lat = lng = distance = maplink = ""
                    continue

                if line == "===ALERT_END===" and in_alert:
                    in_alert = False

                    if not maplink and lat and lng:
                        maplink = f"https://maps.google.com/?q={lat},{lng}"

                    # Route to the nearest APPROVED hospital (bridge-side logic).
                    matched = None
                    if lat and lng:
                        matched, dist_km = nearest_hospital(fetch_hospitals(), float(lat), float(lng))
                        if matched:
                            hospital = matched["name"]
                            chat_id = matched.get("chat_id") or chat_id
                            distance = f"{dist_km:.1f}"

                    routed = matched is not None
                    if not routed:
                        # No approved hospital to route to: detect the accident
                        # and log it, but do NOT dispatch a Telegram alert.
                        hospital = hospital or "No hospital available"
                        distance = ""

                    if routed:
                        msg = format_alert(hospital, lat, lng, distance, maplink, routed)
                        # Send to the nearest hospital's own registered chat;
                        # fall back to the shared alert chat if it has none.
                        send_chat = chat_id or ALERT_CHAT_ID
                        if send_chat:
                            send_telegram_message(send_chat, msg)
                        else:
                            print(f"[{datetime.now().strftime('%H:%M:%S')}] No target chat for alert (nearest hospital has no chat_id)")
                    else:
                        print(f"[{datetime.now().strftime('%H:%M:%S')}] Accident detected ({lat},{lng}) but no approved hospital available — no alert sent")
                    save_alert_to_dashboard(hospital, lat, lng)

                    # Tell the Arduino what to show on its LCD: the routed hospital name, or a
                    # clear "not sent" note when no hospital was available (so it
                    # never falsely claims the alert was sent). The Arduino only
                    # waits a few seconds for this — if it's late or lost, it
                    # falls back to its generic message, and a failure here never
                    # blocks anything else.
                    lcd_msg = hospital if routed else "Alert not sent: no hospital available"
                    try:
                        ser.write(f"HOSP:{lcd_msg}\n".encode("utf-8"))
                    except Exception as e:
                        print(f"[{datetime.now().strftime('%H:%M:%S')}] Error writing hospital name back to Arduino: {e}")
                    continue

                if in_alert:
                    if line.startswith("HOSPITAL:"):
                        hospital = line.replace("HOSPITAL:", "").strip()
                    elif line.startswith("CHATID:"):
                        chat_id = line.replace("CHATID:", "").strip()
                    elif line.startswith("LAT:"):
                        lat = line.replace("LAT:", "").strip()
                    elif line.startswith("LNG:"):
                        lng = line.replace("LNG:", "").strip()
                    elif line.startswith("DISTANCE:"):
                        distance = line.replace("DISTANCE:", "").strip()
                    elif line.startswith("MAPLINK:"):
                        maplink = line.replace("MAPLINK:", "").strip()

        except Exception as e:
            print(f"Error: {e}")
            break

if __name__ == "__main__":
    main()