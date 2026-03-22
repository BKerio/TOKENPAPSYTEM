# TOKENPAPSYSTEM Backend

A robust Laravel-based backend for the TOKENPAPSYSTEM, managing electricity token vending, multi-vendor support, and seamless M-Pesa integrations.

---

## 🚀 Key Features

### 🏦 M-Pesa Integration
- **STK Push**: Automated payment requests for customer convenience.
- **Secure Callbacks**: Real-time processing of payment notifications.
- **Transaction Query**: Reliable status checks for pending transactions.

### 🏢 Multi-Vendor Management
- **Custom Branding**: Individual vendors can upload logos and configure dashboard branding.
- **Independent Configuration**: Per-vendor M-Pesa credentials and SMS provider settings.
- **Oversight**: Admin tools for monitoring vendor activity and vending statistics.

### ⚡ Meter & Token Management
- **Meter Inventory**: Create, assign, and manage electricity meters.
- **Automated Vending**: Instant generation of tokens upon successful payment.
- **Location Based Management**: Organise meters and customers by County, Constituency, and Ward.

### 📱 Communication & Auth
- **SMS Integration**: OTP delivery and transaction notifications via TiaraConnect.
- **Flexible Auth**: JWT/Sanctum based authentication with support for Google Login.
- **Customer Portal**: OTP-based login for simplified customer access.

---

## 🛠 Technology Stack

- **Framework**: Laravel 12
- **Database**: MongoDB (via `jenssegers/mongodb`)
- **Language**: PHP 8.2+
- **Integrations**: 
  - Safaricom Daraja API (M-Pesa)
  - TiaraConnect (SMS Gateway)
  - Google OAuth

---

## ⚙️ Setup Instructions

### 1. Requirements
- PHP 8.2 or higher
- Composer
- MongoDB Server
- extensions: `mongodb`, `curl`, `json`, `mbstring`

### 2. Installation
```bash
# Clone the repository and navigate to backend
composer install
```

### 3. Configuration
Copy the `.env.example` file to `.env` and configure your:
- MongoDB connection (`DB_CONNECTION=mongodb`)
- M-Pesa credentials (`MPESA_CONSUMER_KEY`, `MPESA_CONSUMER_SECRET`, etc.)
- SMS gateway settings
- Google Client ID/Secret

### 4. Database Initialization
```bash
php artisan migrate
php artisan db:seed
```

### 5. Running the Application
```bash
php artisan serve
```

---

## 📡 API Overview

| Group | Method | Endpoint | Description |
|---|---|---|---|
| **Auth** | POST | `/api/admin/login` | Login for admins/vendors |
| **Customer** | POST | `/api/customer/send-otp` | Trigger OTP for customer login |
| **M-Pesa** | POST | `/api/mpesa/stkpush` | Initiate STK Push payment |
| **Meters** | GET | `/api/admin/meters` | List assigned meters |
| **Tokens** | POST | `/api/tokens/generate` | Manually generate a token |
| **Config** | PUT | `/api/vendor/mpesa-config`| Update vendor M-Pesa settings |

*For full documentation, refer to the Postman collection or Swagger UI (if available).*

---

## 🛡 System Monitoring & Logs
The system includes built-in monitoring at `/api/admin/vending-control` to track real-time vending performance and system health.
