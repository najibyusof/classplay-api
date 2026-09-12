# ClassPay Updated Database Design

## 1. Purpose

This document defines the recommended MySQL database design for the ClassPay system.

The design covers:

- Admin authentication
- Student authentication
- Sponsor management
- Role-based access control (RBAC)
- Organization management
- Classes
- Class schedules
- Class participants
- Recurring payment schedules
- Actual payments
- Optional additional Infaq
- QR and merchant payment information
- Payment transactions
- Payment proof
- Notifications
- WhatsApp / Telegram reminder tracking
- Mobile device registration
- Audit logging

The application architecture is:

```text
Flutter Mobile App
        │
        │ REST API / JSON
        ▼
Laravel API Backend
        │
        ▼
      MySQL
```

---

# 2. Core Business Hierarchy

The most important business hierarchy is:

```text
ORGANIZATION
    │
    ├── Organization Admin(s)
    │
    └── Classes
          │
          ├── Class Schedule
          │
          ├── Payment Configuration
          │
          └── Participants
                │
                ├── Students
                └── Sponsors
                       │
                       ▼
                Payment Schedules
                       │
                       ▼
                    Payments
                       │
              ┌────────┴────────┐
              ▼                 ▼
       Payment Transactions  Payment Proofs
```

An Organization is the main business boundary.

Every Class **must belong to exactly one Organization**.

---

# 3. Recommended Tables

## Authentication & RBAC

1. `users`
2. `roles`
3. `permissions`
4. `user_roles`
5. `role_permissions`

## Organization

6. `organizations`
7. `organization_admins`

## Class Management

8. `classes`
9. `class_schedules`
10. `class_payment_settings`
11. `class_participants`

## Sponsor Management

12. `sponsor_students`

## Payment

13. `payment_schedules`
14. `payments`
15. `payment_transactions`
16. `payment_proofs`

## Notification

17. `notifications`
18. `notification_logs`
19. `user_devices`
20. `notification_templates`

## System

21. `audit_logs`

---

# 4. Entity Relationship Overview

```text
users
  │
  ├────────< user_roles >──────── roles
  │                                  │
  │                                  └──< role_permissions >── permissions
  │
  ├────────< organization_admins >── organizations
  │                                      │
  │                                      ├──< classes
  │                                      │      │
  │                                      │      ├──< class_schedules
  │                                      │      │
  │                                      │      ├──1 class_payment_settings
  │                                      │      │
  │                                      │      └──< class_participants >── users
  │                                      │                                      │
  │                                      │                                      └──< sponsor_students >
  │                                      │
  │                                      └──< payment_schedules
  │                                               │
  │                                               └──< payments
  │                                                      │
  │                                                      ├──< payment_transactions
  │                                                      └──< payment_proofs
  │
  ├────────< notifications >────────< notification_logs
  │
  ├────────< user_devices
  │
  └────────< audit_logs
```

---

# 5. Table: users

Stores all authenticated users.

Supported users:

- Admin
- Student
- Sponsor

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | User ID |
| name | VARCHAR(150) | No | Full name |
| phone | VARCHAR(30) UNIQUE | No | Login phone number |
| email | VARCHAR(150) | Yes | Optional email |
| password | VARCHAR(255) | No | Hashed password |
| user_type | ENUM | No | `admin`, `student`, `sponsor` |
| status | ENUM | No | `active`, `inactive`, `suspended` |
| phone_verified_at | TIMESTAMP | Yes | Phone verification time |
| last_login_at | TIMESTAMP | Yes | Last successful login |
| created_at | TIMESTAMP | No | Created time |
| updated_at | TIMESTAMP | No | Updated time |
| deleted_at | TIMESTAMP | Yes | Soft deletion |

## Relationships

```text
users 1 ──── * user_roles
users 1 ──── * organization_admins
users 1 ──── * class_participants
users 1 ──── * sponsor_students
users 1 ──── * payments
users 1 ──── * notifications
users 1 ──── * user_devices
users 1 ──── * audit_logs
```

---

# 6. RBAC

## 6.1 roles

| Field | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| name | VARCHAR(100) UNIQUE |
| description | VARCHAR(255) NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Recommended initial roles:

```text
ADMIN
STUDENT
SPONSOR
```

---

## 6.2 permissions

| Field | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| name | VARCHAR(150) UNIQUE |
| description | VARCHAR(255) NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Examples:

```text
organization.view
organization.create
organization.update
organization.delete

class.view
class.create
class.update
class.delete

participant.view
participant.create
participant.update
participant.delete

payment.view
payment.create
payment.update
payment.verify

notification.view
notification.send
```

---

## 6.3 user_roles

| Field | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| user_id | BIGINT UNSIGNED FK |
| role_id | BIGINT UNSIGNED FK |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Constraints:

```text
UNIQUE(user_id, role_id)
```

Relationship:

```text
users * ─── * roles
```

---

## 6.4 role_permissions

| Field | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| role_id | BIGINT UNSIGNED FK |
| permission_id | BIGINT UNSIGNED FK |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Constraints:

```text
UNIQUE(role_id, permission_id)
```

---

# 7. Table: organizations

Represents an organization that owns classes.

Examples:

```text
Al-Huda Education
Taman Ilmu
Demo Learning Centre
```

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | Organization ID |
| name | VARCHAR(200) | No | Organization name |
| code | VARCHAR(50) UNIQUE | Yes | Optional organization code |
| description | TEXT | Yes | Description |
| logo_path | VARCHAR(500) | Yes | Logo |
| status | ENUM | No | `active`, `inactive` |
| created_by | BIGINT UNSIGNED FK | No | Admin who created it |
| created_at | TIMESTAMP | No | Created time |
| updated_at | TIMESTAMP | No | Updated time |
| deleted_at | TIMESTAMP | Yes | Soft deletion |

## Relationships

```text
organizations 1 ──── * organization_admins
organizations 1 ──── * classes
organizations 1 ──── 1+ classes
```

The system should enforce that an active organization can contain active classes.

---

# 8. Table: organization_admins

Associates administrators with organizations.

This is preferable to assigning administrators only to individual classes because the new requirement makes Organization the main access boundary.

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | ID |
| organization_id | BIGINT UNSIGNED FK | No | Organization |
| user_id | BIGINT UNSIGNED FK | No | Admin |
| is_primary | BOOLEAN | No | Primary administrator |
| status | ENUM | No | `active`, `inactive` |
| created_at | TIMESTAMP | No | Created time |
| updated_at | TIMESTAMP | No | Updated time |

Constraint:

```text
UNIQUE(organization_id, user_id)
```

Relationship:

```text
organizations * ─── * users
```

Example:

```text
Al-Huda Education
   │
   ├── Admin A
   └── Admin B
```

---

# 9. Table: classes

Every class belongs to one organization.

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | Class ID |
| organization_id | BIGINT UNSIGNED FK | No | Owning organization |
| name | VARCHAR(150) | No | Class name |
| description | TEXT | Yes | Description |
| teacher_name | VARCHAR(150) | Yes | Teacher name |
| status | ENUM | No | `draft`, `active`, `inactive`, `completed` |
| start_date | DATE | Yes | Class start |
| end_date | DATE | Yes | Class end |
| created_by | BIGINT UNSIGNED FK | No | Admin who created it |
| created_at | TIMESTAMP | No | Created time |
| updated_at | TIMESTAMP | No | Updated time |
| deleted_at | TIMESTAMP | Yes | Soft deletion |

## Critical constraint

```text
organization_id NOT NULL
```

Relationship:

```text
organizations 1 ──── * classes
```

Example:

```text
Al-Huda Education
   │
   ├── Quran Class
   ├── Fardhu Ain
   └── Bahasa Arab
```

---

# 10. Table: class_schedules

Stores when the class occurs.

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | Schedule ID |
| class_id | BIGINT UNSIGNED FK | No | Class |
| day_of_week | TINYINT | No | 0-6 |
| start_time | TIME | No | Start |
| end_time | TIME | Yes | End |
| timezone | VARCHAR(50) | No | Default `Asia/Kuala_Lumpur` |
| recurrence_type | ENUM | No | `weekly`, `fortnightly`, `monthly` |
| effective_from | DATE | No | Schedule start |
| effective_until | DATE | Yes | Schedule end |
| created_at | TIMESTAMP | No | Created |
| updated_at | TIMESTAMP | No | Updated |

Relationship:

```text
classes 1 ──── * class_schedules
```

---

# 11. Table: class_payment_settings

Stores payment rules for a class.

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | ID |
| class_id | BIGINT UNSIGNED FK UNIQUE | No | Class |
| required_amount | DECIMAL(12,2) | No | Required amount |
| currency | CHAR(3) | No | `MYR` |
| payment_frequency | ENUM | No | `weekly`, `fortnightly`, `monthly` |
| bank_name | VARCHAR(100) | Yes | Bank |
| bank_account_name | VARCHAR(150) | Yes | Account name |
| bank_account_number | VARCHAR(100) | Yes | Account number |
| qr_code_path | VARCHAR(500) | Yes | QR image |
| merchant_payment_url | TEXT | Yes | Merchant payment URL |
| allow_additional_infaq | BOOLEAN | No | Allow extra contribution |
| minimum_infaq | DECIMAL(12,2) | Yes | Minimum extra amount |
| maximum_infaq | DECIMAL(12,2) | Yes | Maximum extra amount |
| reminder_enabled | BOOLEAN | No | Enable reminders |
| reminder_days_before | INT | Yes | Days before due date |
| reminder_days_after | INT | Yes | Days after due date |
| created_at | TIMESTAMP | No | Created |
| updated_at | TIMESTAMP | No | Updated |

Relationship:

```text
classes 1 ──── 1 class_payment_settings
```

---

# 12. Table: class_participants

Connects users to classes.

This table supports both:

- Students
- Sponsors

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | ID |
| class_id | BIGINT UNSIGNED FK | No | Class |
| user_id | BIGINT UNSIGNED FK | No | Participant |
| participant_type | ENUM | No | `student`, `sponsor` |
| status | ENUM | No | `active`, `inactive`, `removed` |
| joined_at | TIMESTAMP | No | Joined |
| left_at | TIMESTAMP | Yes | Left class |
| created_at | TIMESTAMP | No | Created |
| updated_at | TIMESTAMP | No | Updated |

Constraint:

```text
UNIQUE(class_id, user_id)
```

Relationship:

```text
classes 1 ──── * class_participants
users   1 ──── * class_participants
```

This allows:

```text
Student A
   ├── Organization A / Class 1
   ├── Organization A / Class 2
   └── Organization B / Class 3
```

---

# 13. Table: sponsor_students

Defines sponsor-to-student relationships.

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | ID |
| sponsor_id | BIGINT UNSIGNED FK -> users.id | No | Sponsor |
| student_id | BIGINT UNSIGNED FK -> users.id | No | Student |
| relationship_type | VARCHAR(100) | Yes | Optional relationship |
| status | ENUM | No | `active`, `inactive` |
| start_date | DATE | Yes | Start |
| end_date | DATE | Yes | End |
| created_at | TIMESTAMP | No | Created |
| updated_at | TIMESTAMP | No | Updated |

Constraint:

```text
UNIQUE(sponsor_id, student_id)
```

Relationship:

```text
Sponsor
   │
   ├── Student A
   ├── Student B
   └── Student C
```

---

# 14. Table: payment_schedules

Represents the payment obligation generated for a participant.

Example:

```text
Quran Class
Weekly
RM50

15 Sep
22 Sep
29 Sep
06 Oct
```

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | Schedule ID |
| class_id | BIGINT UNSIGNED FK | No | Class |
| class_participant_id | BIGINT UNSIGNED FK | No | Participant |
| period_start | DATE | No | Payment period start |
| period_end | DATE | No | Payment period end |
| due_date | DATE | No | Payment due date |
| required_amount | DECIMAL(12,2) | No | Required amount at generation |
| status | ENUM | No | `upcoming`, `pending`, `partially_paid`, `paid`, `overdue`, `cancelled` |
| generated_at | TIMESTAMP | No | Generated time |
| created_at | TIMESTAMP | No | Created |
| updated_at | TIMESTAMP | No | Updated |

Relationship:

```text
classes 1 ──── * payment_schedules

class_participants 1 ──── * payment_schedules
```

Important:

`required_amount` should be stored on the generated schedule so that future changes to class pricing do not rewrite historical obligations.

---

# 15. Table: payments

Represents an actual payment attempt/transaction against a payment schedule.

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | Payment ID |
| payment_schedule_id | BIGINT UNSIGNED FK | No | Payment obligation |
| payer_id | BIGINT UNSIGNED FK | No | User making payment |
| required_amount | DECIMAL(12,2) | No | Required amount |
| additional_infaq | DECIMAL(12,2) | No | Extra contribution |
| total_amount | DECIMAL(12,2) | No | Required + Infaq |
| currency | CHAR(3) | No | `MYR` |
| status | ENUM | No | `initiated`, `pending`, `processing`, `paid`, `failed`, `rejected`, `refunded`, `cancelled` |
| payment_method | ENUM | No | `qr`, `merchant`, `bank_transfer`, `manual` |
| paid_at | TIMESTAMP | Yes | Successful payment time |
| verified_at | TIMESTAMP | Yes | Verification time |
| verified_by | BIGINT UNSIGNED FK | Yes | Admin verifier |
| reference_number | VARCHAR(150) | Yes | Internal reference |
| notes | TEXT | Yes | Notes |
| created_at | TIMESTAMP | No | Created |
| updated_at | TIMESTAMP | No | Updated |

Relationship:

```text
payment_schedules 1 ──── * payments
users (payer)      1 ──── * payments
users (verifier)   1 ──── * payments
```

---

# 16. Payment and Infaq Rule

The system must keep these values separate:

```text
Required Amount       RM50
Additional Infaq      RM20
----------------------------
Total Payment         RM70
```

Laravel should calculate:

```text
required_amount = payment_schedule.required_amount

additional_infaq = validated Flutter input

total_amount =
    required_amount + additional_infaq
```

Flutter must never be trusted to determine the final amount.

---

# 17. Table: payment_transactions

Stores communication with an external payment merchant/gateway.

One payment can have multiple transaction attempts.

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | ID |
| payment_id | BIGINT UNSIGNED FK | No | Payment |
| gateway_name | VARCHAR(100) | No | Gateway/provider |
| transaction_reference | VARCHAR(200) | Yes | Internal gateway transaction |
| gateway_reference | VARCHAR(200) | Yes | Gateway reference |
| request_amount | DECIMAL(12,2) | No | Amount sent |
| response_status | VARCHAR(100) | Yes | Gateway status |
| response_code | VARCHAR(100) | Yes | Gateway response |
| response_message | TEXT | Yes | Gateway message |
| request_payload | JSON | Yes | Raw request data |
| response_payload | JSON | Yes | Raw response data |
| initiated_at | TIMESTAMP | No | Start |
| completed_at | TIMESTAMP | Yes | Completion |
| created_at | TIMESTAMP | No | Created |
| updated_at | TIMESTAMP | No | Updated |

Relationship:

```text
payments 1 ──── * payment_transactions
```

---

# 18. Table: payment_proofs

Used for manual bank-transfer verification.

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | ID |
| payment_id | BIGINT UNSIGNED FK | No | Payment |
| file_path | VARCHAR(500) | No | File location |
| original_filename | VARCHAR(255) | Yes | Original name |
| mime_type | VARCHAR(100) | Yes | File type |
| file_size | BIGINT | Yes | Size |
| submitted_at | TIMESTAMP | No | Submission time |
| reviewed_at | TIMESTAMP | Yes | Review time |
| reviewed_by | BIGINT UNSIGNED FK | Yes | Admin |
| status | ENUM | No | `pending`, `approved`, `rejected` |
| rejection_reason | TEXT | Yes | Reason |
| created_at | TIMESTAMP | No | Created |
| updated_at | TIMESTAMP | No | Updated |

Relationship:

```text
payments 1 ──── * payment_proofs
```

Flow:

```text
Student pays
     ↓
Upload proof
     ↓
Admin reviews
     ↓
Approve / Reject
     ↓
Payment status updated
```

---

# 19. Table: notifications

Stores in-app notifications.

## Fields

| Field | Type | Null | Description |
|---|---|---:|---|
| id | BIGINT UNSIGNED PK | No | ID |
| user_id | BIGINT UNSIGNED FK | No | Recipient |
| type | VARCHAR(100) | No | Notification type |
| title | VARCHAR(255) | No | Title |
| message | TEXT | No | Message |
| data | JSON | Yes | Additional data/deep-link data |
| related_type | VARCHAR(100) | Yes | Related entity |
| related_id | BIGINT | Yes | Related ID |
| read_at | TIMESTAMP | Yes | Read time |
| sent_at | TIMESTAMP | Yes | Sent time |
| created_at | TIMESTAMP | No | Created |
| updated_at | TIMESTAMP | No | Updated |

Examples:

```text
payment.reminder
payment.success
payment.failed
payment.overdue
class.added
class.removed
```

Relationship:

```text
users 1 ──── * notifications
```

---

# 20. Table: notification_logs

Tracks notification delivery through external channels.

## Fields

| Field | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| notification_id | BIGINT UNSIGNED FK |
| channel | ENUM |
| recipient | VARCHAR(255) |
| status | ENUM |
| provider_message_id | VARCHAR(255) NULL |
| sent_at | TIMESTAMP NULL |
| delivered_at | TIMESTAMP NULL |
| error_message | TEXT NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Channels:

```text
push
email
whatsapp
telegram
```

Statuses:

```text
pending
sent
delivered
failed
```

Relationship:

```text
notifications 1 ──── * notification_logs
```

---

# 21. Table: user_devices

Stores mobile devices for push notifications.

## Fields

| Field | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| user_id | BIGINT UNSIGNED FK |
| device_token | TEXT |
| platform | ENUM(android, ios) |
| device_name | VARCHAR(150) NULL |
| app_version | VARCHAR(50) NULL |
| last_seen_at | TIMESTAMP NULL |
| status | ENUM(active, inactive) |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Relationship:

```text
users 1 ──── * user_devices
```

A user can have multiple devices.

---

# 22. Table: notification_templates

Stores reusable reminder messages.

## Fields

| Field | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| name | VARCHAR(100) |
| notification_type | VARCHAR(100) |
| channel | ENUM |
| subject | VARCHAR(255) NULL |
| body | TEXT |
| status | ENUM(active, inactive) |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Example:

```text
Payment Reminder

Assalamualaikum {{student_name}},

Payment for {{organization_name}}
- Class: {{class_name}}
- Amount: RM{{amount}}
- Due: {{due_date}}

Please make payment using:
{{payment_link}}
```

---

# 23. Table: audit_logs

Tracks important administrative and system actions.

## Fields

| Field | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| user_id | BIGINT UNSIGNED FK NULL |
| action | VARCHAR(100) |
| entity_type | VARCHAR(100) |
| entity_id | BIGINT NULL |
| old_values | JSON NULL |
| new_values | JSON NULL |
| ip_address | VARCHAR(45) NULL |
| user_agent | TEXT NULL |
| created_at | TIMESTAMP |

Examples:

```text
ORGANIZATION_CREATED
ORGANIZATION_UPDATED

CLASS_CREATED
CLASS_UPDATED
CLASS_ACTIVATED

STUDENT_ADDED_TO_CLASS
SPONSOR_ADDED_TO_CLASS

PAYMENT_APPROVED
PAYMENT_REJECTED

PAYMENT_SETTING_CHANGED
```

---

# 24. Complete Relationship Diagram

```text
                              ┌──────────────┐
                              │    USERS     │
                              └──────┬───────┘
                                     │
            ┌────────────────────────┼─────────────────────────┐
            │                        │                         │
            ▼                        ▼                         ▼
       USER_ROLES              ORGANIZATION_ADMINS        USER_DEVICES
            │                        │
            ▼                        ▼
          ROLES               ORGANIZATIONS
            │                        │
            ▼                        ├───────────────┐
   ROLE_PERMISSIONS                  │               │
                                     ▼               ▼
                                  CLASSES       ORGANIZATION
                                     │             ADMINS
                 ┌───────────────────┼───────────────────┐
                 │                   │                   │
                 ▼                   ▼                   ▼
        CLASS_SCHEDULES    CLASS_PAYMENT_SETTINGS   CLASS_PARTICIPANTS
                                                         │
                                                         ▼
                                                     USERS
                                                         │
                                      ┌──────────────────┴─────────────┐
                                      ▼                                ▼
                              PAYMENT_SCHEDULES                  SPONSOR_STUDENTS
                                      │
                                      ▼
                                   PAYMENTS
                                      │
                          ┌───────────┼───────────┐
                          ▼           ▼           ▼
                 PAYMENT_TRANSACTIONS  PAYMENT_PROOFS

USERS
  │
  ▼
NOTIFICATIONS
  │
  ▼
NOTIFICATION_LOGS

USERS
  │
  ▼
AUDIT_LOGS
```

---

# 25. Key Relationships

## Organization → Classes

```text
organizations 1 ──── * classes
```

One organization can have many classes.

Every class must have:

```text
classes.organization_id
```

---

## Organization → Admins

```text
organizations * ─── * users
```

through:

```text
organization_admins
```

This allows multiple admins to manage one organization.

---

## Class → Participants

```text
classes 1 ──── * class_participants
```

A class can have many students/sponsors.

---

## Student → Classes

```text
users * ─── * classes
```

through:

```text
class_participants
```

A student can belong to multiple classes.

---

## Sponsor → Students

```text
users (sponsor)
      │
      ▼
sponsor_students
      │
      ▼
users (student)
```

A sponsor can support multiple students.

---

## Class → Payment Schedule

```text
classes 1 ──── * payment_schedules
```

Payment schedules are generated for participants.

---

## Payment Schedule → Payment

```text
payment_schedules 1 ──── * payments
```

This separation is important because a payment schedule represents the obligation, while a payment represents an actual payment attempt/transaction.

---

# 26. Example Data

## Organization

```text
ID: 1
Name: Al-Huda Education
Status: Active
```

## Classes

```text
ID: 1
Organization: Al-Huda Education
Name: Quran Class
Teacher: Cikgu Ahmad

ID: 2
Organization: Al-Huda Education
Name: Fardhu Ain
Teacher: Cikgu Aisyah
```

## Student

```text
ID: 101
Name: Ahmad Daniel
Phone: +60123456789
User Type: student
```

## Class Membership

```text
Class: Quran Class
Student: Ahmad Daniel
Status: Active
```

## Payment Schedule

```text
Class: Quran Class
Student: Ahmad Daniel

Due Date: 15 Sep 2026
Required Amount: RM50
Status: Pending
```

## Payment

```text
Required Amount: RM50
Additional Infaq: RM20
Total: RM70
Status: Paid
```

---

# 27. Payment Lifecycle

```text
PAYMENT SCHEDULE
       │
       ▼
   UPCOMING
       │
       ▼
    PENDING
       │
       ├───────────────┐
       │               │
       ▼               ▼
    PAYMENT          OVERDUE
       │               │
       ▼               ▼
  PROCESSING       REMINDER
       │               │
       ▼               │
      PAID ◄───────────┘
```

Actual payment status:

```text
initiated
    ↓
pending
    ↓
processing
    ↓
paid
```

Alternative:

```text
pending → failed
pending → cancelled
pending → rejected
paid → refunded
```

---

# 28. Payment Confirmation Flow

## Merchant Payment

```text
Flutter
   │
   ▼
Laravel API
   │
   ▼
Create Payment
   │
   ▼
Payment Merchant
   │
   ▼
Student Pays
   │
   ▼
Merchant
   │
   ▼
Webhook
   │
   ▼
Laravel
   │
   ├── Verify transaction
   ├── Update payment
   ├── Update payment schedule
   └── Create notification
   │
   ▼
PAID
```

## Manual Bank Transfer

```text
Student
   ↓
Bank Transfer
   ↓
Upload Proof
   ↓
Laravel
   ↓
Admin Review
   ↓
Approve
   ↓
Payment = PAID
```

---

# 29. Reminder Flow

```text
Laravel Scheduler
       ↓
Find payment schedules
       ↓
Check due date
       ↓
Check payment status
       ↓
Pending / Overdue?
       ↓
Check reminder configuration
       ↓
Create notification
       ↓
Send push notification
       ↓
Optional WhatsApp / Telegram
       ↓
Include payment link
       ↓
Student taps "Pay Now"
       ↓
Open payment screen
```

---

# 30. Organization Authorization

The organization should be an important authorization boundary.

## Admin

```text
Admin
  ↓
Organization Membership
  ↓
Organization
  ↓
Classes
  ↓
Participants
  ↓
Payments
```

An admin should only be able to access organizations assigned to them.

For example:

```text
Admin A
  ├── Organization A ✓
  └── Organization B ✗
```

---

# 31. Student Authorization

```text
Student
   ↓
Organization Membership
   ↓
Class Membership
   ↓
Own Payment Schedule
   ↓
Own Payment
```

A student must not be able to access another student's payment by changing an API ID.

Example:

```text
GET /api/student/payments/123
```

Laravel must verify that payment `123` belongs to the authenticated student before returning it.

---

# 32. Recommended Database Constraints

## Users

```text
UNIQUE(phone)
```

## Roles

```text
UNIQUE(name)
```

## Permissions

```text
UNIQUE(name)
```

## User Roles

```text
UNIQUE(user_id, role_id)
```

## Role Permissions

```text
UNIQUE(role_id, permission_id)
```

## Organization Admins

```text
UNIQUE(organization_id, user_id)
```

## Class Participants

```text
UNIQUE(class_id, user_id)
```

## Sponsor Students

```text
UNIQUE(sponsor_id, student_id)
```

## Class Payment Settings

```text
UNIQUE(class_id)
```

---

# 33. Important Business Rules

## Rule 1 — Every Class Must Have an Organization

```text
classes.organization_id NOT NULL
```

A class cannot exist without an organization.

---

## Rule 2 — An Organization Can Have Multiple Classes

```text
Organization
 ├── Class A
 ├── Class B
 └── Class C
```

---

## Rule 3 — A Student Can Belong to Multiple Organizations

Example:

```text
Ahmad
 ├── Al-Huda Education
 │      ├── Quran
 │      └── Fardhu Ain
 │
 └── Taman Ilmu
        └── Mathematics
```

The relationship is derived through class participation.

---

## Rule 4 — A Student Can Belong to Multiple Classes

Use:

```text
class_participants
```

---

## Rule 5 — Sponsors Can Support Multiple Students

Use:

```text
sponsor_students
```

---

## Rule 6 — Payment Schedule Is the Obligation

Example:

```text
15 Sep
Required Amount = RM50
Status = Pending
```

---

## Rule 7 — Payment Is the Actual Payment

Example:

```text
Required = RM50
Infaq = RM20
Total = RM70
```

---

## Rule 8 — Laravel Is the Source of Truth

Laravel determines:

- Payment amount
- Payment status
- Due date
- Payment eligibility
- Reminder eligibility
- Payment confirmation

Flutter only displays the API result.

---

## Rule 9 — Never Trust Client-Side Payment Amount

Flutter may submit:

```json
{
  "additional_infaq": 20
}
```

Laravel calculates:

```text
required_amount = payment_schedule.required_amount
additional_infaq = validated input
total_amount = required_amount + additional_infaq
```

Never trust a Flutter-provided `total_amount`.

---

## Rule 10 — Historical Payment Amounts Must Not Change

If an admin changes a class from:

```text
RM50 → RM60
```

existing payment schedules should remain:

```text
RM50
```

Only newly generated schedules should use RM60.

---

# 34. Recommended Indexes

At minimum, add indexes for:

```text
users.phone

organizations.status

organizations.created_by

organization_admins.organization_id
organization_admins.user_id

classes.organization_id
classes.status

class_schedules.class_id

class_payment_settings.class_id

class_participants.class_id
class_participants.user_id

sponsor_students.sponsor_id
sponsor_students.student_id

payment_schedules.class_id
payment_schedules.class_participant_id
payment_schedules.due_date
payment_schedules.status

payments.payment_schedule_id
payments.payer_id
payments.status
payments.reference_number

payment_transactions.payment_id
payment_transactions.gateway_reference

payment_proofs.payment_id
payment_proofs.status

notifications.user_id
notifications.read_at

notification_logs.notification_id
notification_logs.status

user_devices.user_id
user_devices.device_token

audit_logs.user_id
audit_logs.entity_type
audit_logs.entity_id
```

---

# 35. Laravel Model Structure

Recommended Eloquent models:

```text
User
Role
Permission

Organization
OrganizationAdmin

ClassModel
ClassSchedule
ClassPaymentSetting
ClassParticipant

SponsorStudent

PaymentSchedule
Payment
PaymentTransaction
PaymentProof

Notification
NotificationLog
NotificationTemplate

UserDevice
AuditLog
```

Because `Class` can conflict conceptually with PHP/Laravel naming, using:

```text
ClassModel
```

or another clear model name such as:

```text
LearningClass
```

is recommended.

---

# 36. Recommended Eloquent Relationships

## User

```php
User
    belongsToMany(Role::class)
    hasMany(OrganizationAdmin::class)
    hasMany(ClassParticipant::class)
    hasMany(Payment::class, 'payer_id')
    hasMany(Notification::class)
    hasMany(UserDevice::class)
    hasMany(AuditLog::class)
```

## Organization

```php
Organization
    belongsTo(User::class, 'created_by')
    hasMany(OrganizationAdmin::class)
    hasMany(ClassModel::class)
```

## ClassModel

```php
ClassModel
    belongsTo(Organization::class)
    belongsTo(User::class, 'created_by')
    hasMany(ClassSchedule::class)
    hasOne(ClassPaymentSetting::class)
    hasMany(ClassParticipant::class)
    hasMany(PaymentSchedule::class)
```

## ClassParticipant

```php
ClassParticipant
    belongsTo(ClassModel::class, 'class_id')
    belongsTo(User::class)
    hasMany(PaymentSchedule::class)
```

## PaymentSchedule

```php
PaymentSchedule
    belongsTo(ClassModel::class)
    belongsTo(ClassParticipant::class)
    hasMany(Payment::class)
```

## Payment

```php
Payment
    belongsTo(PaymentSchedule::class)
    belongsTo(User::class, 'payer_id')
    belongsTo(User::class, 'verified_by')
    hasMany(PaymentTransaction::class)
    hasMany(PaymentProof::class)
```

## Notification

```php
Notification
    belongsTo(User::class)
    hasMany(NotificationLog::class)
```

---

# 37. Recommended Implementation Priority

## Phase 1 — Authentication & RBAC

```text
users
roles
permissions
user_roles
role_permissions
```

## Phase 2 — Organization

```text
organizations
organization_admins
```

## Phase 3 — Classes

```text
classes
class_schedules
class_payment_settings
```

## Phase 4 — Participants

```text
class_participants
sponsor_students
```

## Phase 5 — Recurring Payments

```text
payment_schedules
```

## Phase 6 — Payments

```text
payments
payment_transactions
payment_proofs
```

## Phase 7 — Notifications

```text
notifications
notification_logs
user_devices
notification_templates
```

## Phase 8 — Audit

```text
audit_logs
```

---

# 38. Final Recommended Database Architecture

```text
                         USERS
                           │
            ┌──────────────┼──────────────┐
            │              │              │
            ▼              ▼              ▼
          RBAC       ORGANIZATION     DEVICES
                           │
                           ▼
                      ORGANIZATION
                           │
                           │
                           ▼
                         CLASS
                           │
             ┌─────────────┼─────────────┐
             │             │             │
             ▼             ▼             ▼
          SCHEDULE      PAYMENT       PARTICIPANTS
                        SETTINGS          │
                                         │
                               ┌─────────┴─────────┐
                               ▼                   ▼
                           STUDENTS             SPONSORS
                               │
                               ▼
                       PAYMENT SCHEDULE
                               │
                               ▼
                            PAYMENT
                               │
                    ┌──────────┼──────────┐
                    ▼          ▼          ▼
                TRANSACTION   PROOF    NOTIFICATION
                    │                     │
                    ▼                     ▼
                 MERCHANT             REMINDER
```

The central relationship is:

```text
Organization
    ↓
Class
    ↓
Class Participant
    ↓
Payment Schedule
    ↓
Payment
```

This structure supports the current requirements while leaving room for multiple organizations, multiple administrators, students belonging to multiple classes, sponsors supporting multiple students, recurring payments, optional Infaq, merchant integration, payment verification, reminders, and future reporting.
