# Introduction

API for the Mufasah Device Tracking System - A comprehensive platform for tracking and protecting electronic devices with IoT integration, geofencing, theft reporting, and subscription management.

<aside>
    <strong>Base URL</strong>: <code>http://localhost:8000</code>
</aside>

    Welcome to the Mufasah Device Tracking API documentation.

    This API provides endpoints for:
    - **Authentication** - User registration, login, and token management
    - **Device Management** - Register and manage tracked devices
    - **Location Tracking** - Real-time and historical location data
    - **Geofencing** - Create virtual boundaries with entry/exit alerts
    - **Theft Reporting** - Report and track stolen devices
    - **Subscriptions** - Manage subscription plans and payments
    - **Warranties** - Device warranty registration and claims
    - **Notifications** - Multi-channel notification preferences

    <aside>
    All authenticated endpoints require a Bearer token in the Authorization header.
    Get your token by logging in via the <code>/api/v1/auth/login</code> endpoint.
    </aside>

