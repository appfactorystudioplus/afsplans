# Users Role and Permissions API

This document describes the portal API endpoints for checking user roles and permissions.

## Overview

The portal now stores permissions using module-specific CRUD action sets. Each module permission is represented as:

- `active` — whether the module is enabled for the user
- `c` — create permission
- `r` — read permission
- `u` — update permission
- `d` — delete permission

Example module permissions structure:

```json
"permissions": {
  "ecommerce": { "active": true, "c": true, "r": true, "u": true, "d": true },
  "processors": { "active": true, "c": false, "r": true, "u": false, "d": false }
}
```

## API Endpoints

### GET `/portal/api/users/current`

Returns the currently authenticated portal user, including role and permission sets.

Response:

```json
{
  "success": true,
  "data": {
    "user": {
      "id": 123,
      "username": "jdoe",
      "email": "jdoe@example.com",
      "firstName": "John",
      "lastName": "Doe",
      "role": "admin",
      "permissions": {
        "users": { "active": true, "c": true, "r": true, "u": true, "d": true },
        "ecommerce": { "active": true, "c": true, "r": true, "u": true, "d": true },
        // ... other modules
      },
      "superAdminOverride": false,
      "active": true,
      "lastLogin": "2026-04-03 12:00:00"
    }
  }
}
```

### GET `/portal/api/users/{id}/permissions`

Returns the role and permissions for a specific user.

Response:

```json
{
  "success": true,
  "data": {
    "id": 456,
    "role": "manager",
    "permissions": {
      "users": { "active": false, "c": false, "r": false, "u": false, "d": false },
      "ecommerce": { "active": true, "c": true, "r": true, "u": true, "d": true },
      "processors": { "active": true, "c": false, "r": true, "u": false, "d": false }
    }
  }
}
```

### GET `/portal/api/users`

Lists users in the portal. Each returned user record includes the current role and permission set.

### GET `/portal/api/users/{id}`

Returns a single user resource, including role and permissions.

### POST `/portal/api/users`

Creates a new user. The request body includes role and nested permissions.

Example request payload:

```json
{
  "firstName": "Jane",
  "lastName": "Doe",
  "username": "jane_doe",
  "email": "jane.doe@example.com",
  "password": "SuperSecret123",
  "role": "standard",
  "active": true,
  "permissions": {
    "ecommerce": { "active": true, "c": true, "r": true, "u": true, "d": true },
    "processors": { "active": true, "c": false, "r": true, "u": false, "d": false }
  }
}
```

### PUT `/portal/api/users/{id}`

Updates an existing user. Password is optional on update; if included it must match client-side confirmation. Permissions can be passed with nested CRUD action values.

### DELETE `/portal/api/users/{id}`

Deletes a user. Super-admin users are protected and cannot be deleted.

## Notes

- Super admins always have full access regardless of permission values.
- Admin users are expected to have module permissions assigned in the `users` permission module to access the user management section.
- Users section access is gated by the `users` permission module and its read permission (`r`).
- Modules that are enabled for a user can have fine-grained CRUD access configured individually.
