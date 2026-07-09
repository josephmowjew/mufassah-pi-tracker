# Mufasah Device Tracking API

A comprehensive Laravel-based REST API for device tracking, theft prevention, and recovery management. The system enables users to register and track their electronic devices, receive real-time location updates via IoT modules, report stolen devices, and leverage community-powered recovery networks.

## Features

### Device Management
- Register and manage multiple device categories (smartphones, laptops, tablets, etc.)
- Link devices to IoT modules for real-time GPS tracking
- Track device location history with accuracy, speed, and heading data
- Manage device warranties and warranty claims

### Product Catalog
- Admin-managed brand and product model catalog
- Category-specific brand organization
- Cascading selection (Category > Brand > Model) for device registration

### Theft Prevention and Recovery
- Report stolen devices with detailed incident information
- Track theft report status (Reported, Investigating, Recovered, Closed)
- Community alert system for stolen device sightings
- Recovery network with community participation and rewards
- Success story sharing for recovered devices

### Family Management
- Create family groups for shared device monitoring
- Assign roles (admin, member) within families
- Share device access among family members

### Subscription System
- Multiple subscription tiers (Basic, Premium, Enterprise)
- Monthly and yearly billing cycles
- Payment processing with status tracking

### Geofencing
- Create circular geofences around locations
- Receive alerts when devices enter or exit geofences
- Track geofence events with timestamps

### Notifications
- In-app notification system
- Multiple notification types (alerts, updates, security)
- Read/unread status tracking

### Admin Features
- User management with role-based access control
- Device and IoT module inventory management
- Sales tracking with bulk device registration
- Theft report management and investigation
- Subscription and payment oversight
- Community alert moderation
- Comprehensive reporting and analytics

## Requirements

- PHP 8.4+
- Composer 2.x
- MySQL 8.0+ or PostgreSQL 13+
- Node.js 18+ (for frontend assets if applicable)

## Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd api
```

2. Install PHP dependencies:
```bash
composer install
```

3. Copy the environment file:
```bash
cp .env.example .env
```

4. Generate application key:
```bash
php artisan key:generate
```

5. Configure your database in `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=device_tracking
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

6. Run migrations and seeders:
```bash
php artisan migrate --seed
```

7. Start the development server:
```bash
php artisan serve
```

## Configuration

### Environment Variables

Key environment variables to configure:

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_NAME` | Application name | Mufasah |
| `APP_ENV` | Environment (local, production) | local |
| `APP_DEBUG` | Debug mode | true |
| `APP_URL` | Application URL | http://localhost |
| `DB_CONNECTION` | Database driver | mysql |
| `SANCTUM_STATEFUL_DOMAINS` | Domains for SPA authentication | localhost |
| `SESSION_DOMAIN` | Session cookie domain | localhost |

### CORS Configuration

For frontend integration, ensure `SANCTUM_STATEFUL_DOMAINS` includes your frontend domain:
```env
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,127.0.0.1
```

## Database Schema

### Core Tables

- `users` - User accounts with authentication
- `devices` - Registered devices with ownership
- `device_categories` - Device type categories
- `brands` - Product brands (category-specific)
- `product_models` - Product models under brands
- `iot_modules` - GPS tracking hardware modules
- `device_locations` - Location history records

### Tracking and Security

- `geofences` - Defined geofence boundaries
- `geofence_events` - Entry/exit events
- `theft_reports` - Stolen device reports
- `theft_report_updates` - Investigation updates
- `community_alerts` - Community sighting alerts
- `alert_sightings` - User-reported sightings

### Business Logic

- `subscriptions` - User subscription records
- `subscription_plans` - Available plans
- `payments` - Payment transactions
- `warranties` - Device warranty information
- `warranty_claims` - Warranty claim requests

### Family and Social

- `families` - Family group definitions
- `family_members` - Family membership
- `family_devices` - Shared device access

### Inventory

- `inventory_items` - Stock inventory
- `inventory_transactions` - Stock movements
- `sales` - Sales records
- `sale_items` - Individual sale line items

## API Documentation

### Authentication

The API uses Laravel Sanctum for authentication. Two authentication methods are supported:

1. **SPA Authentication** (cookie-based for same-domain frontends)
2. **Token Authentication** (for mobile apps and third-party integrations)

#### Login
```
POST /api/v1/auth/login
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "password"
}
```

#### Register
```
POST /api/v1/auth/register
Content-Type: application/json

{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password",
    "password_confirmation": "password"
}
```

### API Versioning

All API routes are prefixed with `/api/v1/`. The API follows RESTful conventions.

### Main Endpoints

#### User Endpoints
- `GET /api/v1/user` - Get authenticated user
- `PUT /api/v1/user/profile` - Update profile
- `PUT /api/v1/user/password` - Change password

#### Device Endpoints
- `GET /api/v1/devices` - List user's devices
- `POST /api/v1/devices` - Register new device
- `GET /api/v1/devices/{id}` - Get device details
- `PUT /api/v1/devices/{id}` - Update device
- `DELETE /api/v1/devices/{id}` - Remove device

#### Tracking Endpoints
- `GET /api/v1/devices/{id}/locations` - Get location history
- `POST /api/v1/devices/{id}/locations` - Record new location
- `GET /api/v1/devices/{id}/geofences` - Get device geofences
- `POST /api/v1/devices/{id}/geofences` - Create geofence

#### Theft Reports
- `GET /api/v1/theft-reports` - List user's theft reports
- `POST /api/v1/theft-reports` - Report stolen device
- `GET /api/v1/theft-reports/{id}` - Get report details

#### Admin Endpoints (require admin role)
- `GET /api/v1/admin/users` - List all users
- `GET /api/v1/admin/devices` - List all devices
- `GET /api/v1/admin/inventory` - Manage inventory
- `GET /api/v1/admin/catalog/brands` - Manage product catalog
- `GET /api/v1/admin/sales` - Sales management
- `GET /api/v1/admin/reports` - Analytics and reports

### Response Format

All API responses follow a consistent format:

**Success Response:**
```json
{
    "data": { ... },
    "message": "Operation successful"
}
```

**Error Response:**
```json
{
    "message": "Error description",
    "errors": {
        "field": ["Validation error message"]
    }
}
```

**Paginated Response:**
```json
{
    "data": [ ... ],
    "links": {
        "first": "...",
        "last": "...",
        "prev": null,
        "next": "..."
    },
    "meta": {
        "current_page": 1,
        "last_page": 10,
        "per_page": 15,
        "total": 150
    }
}
```

## Testing

Run the test suite:
```bash
php artisan test
```

Run specific tests:
```bash
php artisan test --filter=DeviceTest
```

Run with coverage:
```bash
php artisan test --coverage
```

## Default Accounts

After running seeders, the following accounts are available:

| Role | Email | Password |
|------|-------|----------|
| Super Admin | admin@mufasah.com | password |
| Store Admin | store@mufasah.com | password |
| Customer Service | support@mufasah.com | password |
| Customer | customer@mufasah.com | password |

## Project Structure

```
app/
├── Console/Commands/     # Artisan commands
├── Enums/               # PHP enums for status values
├── Http/
│   ├── Controllers/     # API controllers
│   ├── Middleware/      # Custom middleware
│   ├── Requests/        # Form request validation
│   └── Resources/       # API resource transformers
├── Models/              # Eloquent models
├── Policies/            # Authorization policies
├── Providers/           # Service providers
└── Services/            # Business logic services

database/
├── factories/           # Model factories for testing
├── migrations/          # Database migrations
└── seeders/            # Database seeders

routes/
├── api.php             # API routes
├── console.php         # Console routes
└── web.php             # Web routes

tests/
├── Feature/            # Feature tests
└── Unit/               # Unit tests
```

## Code Style

This project uses Laravel Pint for code formatting:

```bash
vendor/bin/pint
```

## Roles and Permissions

The system uses Spatie Laravel Permission for role-based access control:

- **super_admin** - Full system access
- **store_admin** - Store management, inventory, sales
- **customer_service** - Customer support, limited admin access
- **customer** - Standard user access

## License

This project is proprietary software. All rights reserved.
