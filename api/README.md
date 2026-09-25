# OJ Apartment — PHP API

## Setup

### 1. Requirements
- PHP 8.1+
- MySQL 8.0+ / MariaDB 10.6+
- Apache or Nginx with mod_rewrite
- A local server like [XAMPP](https://www.apachefriends.org/) or [Laragon](https://laragon.org/)

### 2. Database
```bash
mysql -u root -p -e "CREATE DATABASE oj_apartment CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p oj_apartment < api/database.sql
```

### 3. Configuration
Edit `api/config.php` and fill in:
- `DB_USER` / `DB_PASS`
- `APP_URL` (e.g. `http://localhost/OJ APARTMENT`)
- M-Pesa Daraja credentials from [developer.safaricom.co.ke](https://developer.safaricom.co.ke)
- `APP_SECRET` (random 32+ char string)

Or copy to `api/config.local.php` (never committed).

### 4. M-Pesa Callback URL
Your server must be reachable by Safaricom.  
For local dev use [ngrok](https://ngrok.com/):
```bash
ngrok http 80
# Then set MPESA_CALLBACK_URL in config.php to the ngrok https URL
```

---

## API Endpoints

| File | Actions |
|------|---------|
| `auth.php`       | `register`, `login`, `logout`, `me`, `change_password`, `grant_access` |
| `apartments.php` | `list`, `get`, `reserve`, `cancel`, `create`, `update`, `delete` |
| `mpesa.php`      | `stk_push`, `b2c_send`, `callback`, `b2c_result`, `status` |
| `contact.php`    | `submit`, `list`, `update`, `delete` |
| `payroll.php`    | `staff_list`, `staff_get`, `staff_create`, `staff_update`, `staff_delete`, `run`, `history`, `withdrawal`, `settings_get`, `settings_save` |

All endpoints accept `?action=<action>` as a query param.  
POST bodies are JSON. Responses are always:
```json
{ "success": true, "message": "OK", "data": {} }
```

---

## Default Admin
- Email: `admin@ojapartment.co.ke`
- Password: `password` — **change this immediately after setup.**
