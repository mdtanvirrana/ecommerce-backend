# REST API Documentation — Nocfinity E-Commerce

Base URL: `http://localhost:8000/api/v1`

## Response Envelope

### Success
```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": {},
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 9,
    "total": 24
  }
}
```
(`meta` is included on all paginated endpoints.)

### Error
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

### Standard HTTP Status Codes
`200 OK`, `201 Created`, `400 Bad Request`, `401 Unauthorized`, `403 Forbidden`, `404 Not Found`, `409 Conflict`, `422 Unprocessable Entity`, `429 Too Many Requests`, `500 Internal Server Error`.

---

## 1. Customer Authentication (`/api/v1`)
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/register` | Public | Register customer account (strong password policy, bcrypt, returns user + Sanctum token) |
| POST | `/login` | Public (throttled: 5 attempts) | Customer login (generic error on failure, returns user + Sanctum token with `customer` ability) |
| POST | `/logout` | Customer | Revoke current customer Sanctum token |
| POST | `/password/forgot` | Public (throttled) | Send password reset email link |
| POST | `/password/reset` | Public | Reset password with token |
| GET | `/me` | Customer | Get authenticated customer profile |
| PATCH | `/me` | Customer | Update customer profile (name, phone) |

## 2. Storefront Catalog (`/api/v1`)
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/products` | Public | Paginated product listing. Query params: `search`, `category` (slug or id, includes child categories), `min_price`, `max_price`, `color`, `size`, `sort` (`price_asc`, `price_desc`, `newest`, `popular`), `featured`, `new_arrivals`, `top_selling`, `on_sale`, `per_page`, `page` |
| GET | `/products/{slug}` | Public | Product details with all images, category hierarchy, stock status, and related products |
| GET | `/categories` | Public | Nested active category tree with product counts |
| GET | `/categories/{slug}` | Public | Single category details with children & parent |

## 3. Shopping Cart & Coupons (`/api/v1`)
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/cart` | Customer | Retrieve persistent MySQL cart with live product prices, applied coupon discount, shipping estimate, and total |
| POST | `/cart/items` | Customer | Add item (`product_id`, `quantity`, optional `selected_color`, `selected_size`). Rejects inactive/out-of-stock/over-stock items |
| POST | `/cart/merge` | Customer | Merge guest local-storage cart items into authenticated customer cart after login |
| PATCH | `/cart/items/{id}` | Customer | Update item quantity (validated against stock) |
| DELETE | `/cart/items/{id}` | Customer | Remove cart item |
| POST | `/cart/coupon` | Customer | Apply validated coupon to persistent cart |
| DELETE | `/cart/coupon` | Customer | Remove coupon from cart |
| POST | `/coupons/validate` | Public / Customer | Validate coupon code against subtotal (or current cart) and return server-calculated discount |

## 4. Checkout, Payments & Customer Orders (`/api/v1`)
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/checkout` | Customer | Validate shipping form, revalidate prices/coupon/stock from DB, create pending Order + Payment + Stripe PaymentIntent, return `order` + `client_secret` |
| POST | `/orders/{id}/retry-payment` | Customer (Owner) | Create/retrieve PaymentIntent to retry payment on a pending/failed order |
| POST | `/orders/{id}/confirm-payment` | Customer (Owner) | Server-side reconciliation endpoint: fetches PaymentIntent directly from Stripe API (never trusts client) to verify & confirm if webhook is delayed |
| GET | `/orders` | Customer | Paginated order history for authenticated customer only |
| GET | `/orders/{id}` | Customer (Owner) | Single order details (strict ownership check — returns 403/404 for other customers' orders) |
| POST | `/stripe/webhook` | Public (Stripe Signature Verified) | Handles `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled` with signature check, idempotency, server-side Stripe verification, and `lockForUpdate()` stock deduction |

## 5. Admin / Staff Authentication (`/api/v1/admin`)
| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/admin/login` | Public (throttled: 5 attempts) | Staff login via dedicated `admin` guard (issues token with `staff` ability, returns staff profile + roles + effective permissions) |
| POST | `/admin/logout` | Staff | Revoke current staff token |
| POST | `/admin/password/forgot` | Public (throttled) | Staff password reset email |
| POST | `/admin/password/reset` | Public | Reset staff password |
| GET | `/admin/me` | Staff | Current staff profile, roles, and effective permissions list |
| PATCH | `/admin/profile` | Staff | Update staff name / email |
| PUT | `/admin/profile/password` | Staff | Update staff password (requires current password) |

## 6. Admin Panel APIs (`/api/v1/admin`, permission-protected)
| Module | Endpoints | Required Permissions |
|---|---|---|
| Dashboard | `GET /admin/dashboard` | `reports.view` |
| Products | `GET /admin/products`, `POST /admin/products`, `GET /admin/products/{id}`, `PUT/POST /admin/products/{id}`, `DELETE /admin/products/{id}`, `PATCH /admin/products/{id}/stock`, `DELETE /admin/products/{id}/images/{imageId}` | `products.view`, `products.create`, `products.edit`, `products.delete`, `products.update_stock` |
| Categories | `GET /admin/categories`, `POST /admin/categories`, `GET /admin/categories/{id}`, `PUT /admin/categories/{id}`, `DELETE /admin/categories/{id}` | `categories.view`, `categories.create`, `categories.edit`, `categories.delete` |
| Orders | `GET /admin/orders`, `GET /admin/orders/{id}`, `PATCH /admin/orders/{id}/status`, `POST /admin/orders/{id}/cancel` | `orders.view`, `orders.update_status`, `orders.cancel` |
| Coupons | `GET /admin/coupons`, `POST /admin/coupons`, `GET /admin/coupons/{id}`, `PUT /admin/coupons/{id}`, `DELETE /admin/coupons/{id}` | `coupons.view`, `coupons.create`, `coupons.edit`, `coupons.delete` |
| Customers | `GET /admin/customers`, `GET /admin/customers/{id}` | `customers.view`, `customers.view_orders` |
| Staff | `GET /admin/staff`, `POST /admin/staff`, `GET /admin/staff/{id}`, `PUT /admin/staff/{id}`, `DELETE /admin/staff/{id}` | `staff.view`, `staff.create`, `staff.edit`, `staff.delete` |
| Roles & Permissions | `GET /admin/roles`, `POST /admin/roles`, `GET /admin/roles/{id}`, `PUT /admin/roles/{id}`, `DELETE /admin/roles/{id}`, `GET /admin/permissions`, `PUT /admin/roles/{id}/permissions` | `roles.view`, `roles.create`, `roles.edit`, `roles.delete`, `permissions.assign` |
