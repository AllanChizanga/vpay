# Wallet API Documentation

Base URL: `http://your-app.test/api`  
Authentication: **Bearer Token** (Sanctum)

---

## 1. Show Wallet

**Endpoint:** `GET /wallet/{userId}`  
**Description:** Retrieve the wallet information for a specific user.

### Request
**Headers:**

Authorization: Bearer <your_token_here>
Accept: application/json


**URL Parameters:**
| Parameter | Type   | Description       |
|-----------|--------|-----------------|
| userId    | string | ID of the user   |

### Response (200 OK)
```json
{
  "wallet": {
    "id": "uuid",
    "user_id": "1",
    "balance": "100.00",
    "version": 0,
    "created_at": "2025-11-11T23:26:43.000000Z",
    "updated_at": "2025-11-11T23:26:43.000000Z"
  }
}

2. Credit Wallet

Endpoint: POST /wallet/deposit
Description: Add funds to a user’s wallet.

Request

Headers: