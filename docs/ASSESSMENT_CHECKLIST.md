# Assessment Requirements Checklist

Legend:
- `[x]` Implemented and tested/verified
- `[~]` Partially implemented (see notes)
- `[ ]` Not implemented (optional/skipped bonus)

## 1. Workspace, Planning & Documentation
- [x] Read `Project Details.pdf` completely and extract requirements
- [x] Inspect all 8 local screenshots in `figma/` (no external Figma URL)
- [x] Inspect existing `ecommerce-backend/` and `ecommerce-frontend/` projects
- [x] Create `PROJECT_PLAN.md`, `DATABASE_DESIGN.md`, `API_DOCUMENTATION.md`, `FIGMA_DESIGN_SPEC.md`, `ASSESSMENT_CHECKLIST.md`
- [x] Complete `README.md` with setup, MySQL/Stripe/Mailpit instructions, demo credentials, and test commands
- [x] Complete `ARCHITECTURE.md` with live technical interview walkthrough explanations

## 2. Backend Foundation & Database (MySQL 8 + Laravel 13)
- [x] Laravel Sanctum (`^4.5`) & Stripe PHP SDK (`^23.0`) installed
- [x] MySQL `8.0.46` configured (`nocfinity_ecommerce` + `nocfinity_ecommerce_test`) & migrations run cleanly from fresh DB
- [x] Required tables migrated: `users`, `password_reset_tokens`, `personal_access_tokens`, `roles`, `permissions`, `role_user`, `permission_role`, `products`, `product_images`, `categories`, `carts`, `cart_items`, `orders`, `order_items`, `coupons`, `coupon_usages`, `payments`
- [x] Optional audit/idempotency tables migrated: `order_sequences`, `webhook_events`, `inventory_transactions`, `activity_logs`
- [x] `DECIMAL(12,2)` monetary columns & `bcmath` `Money` helper (zero floating-point money math)
- [x] Seeders verified on MySQL 8: Super Admin, 5 default roles, 30 permissions, role-permission assignments, 2-level category tree, 24 products with working local Figma images, 3 coupons, 4 customers, 5 staff, 8 sample orders across all statuses

## 3. Authentication & Security
- [x] Customer registration, login, logout, profile (`/me`), password reset email (Pest tested + live E2E verified)
- [x] Separate `admin` authentication guard (`App\Models\Staff`), staff login, logout, protected routes, profile, password update, password reset (Pest tested + live E2E verified)
- [x] Bcrypt password hashing & strong password validation (Pest tested)
- [x] Login throttling after 5 failed attempts (`429`) & generic authentication error messages (Pest tested for both customer & staff)
- [x] Token revocation on logout & secure `HttpOnly` cookie token storage via Next.js BFF Route Handlers (zero `localStorage` tokens, live E2E verified)
- [x] Security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`), CORS config, sensitive field exclusion, IDOR `OrderPolicy` enforcement (Pest tested)
- [ ] Optional Bonus: TOTP-based admin two-factor authentication (skipped per Section 22 prioritization)

## 4. Database-Driven RBAC
- [x] 5 default roles (`Super Admin`, `Admin`, `Product Manager`, `Order Manager`, `Support`) & 30 granular permissions seeded
- [x] Permission-based middleware (`permission:...`) protecting every admin endpoint (never hardcoded role-name checks alone)
- [x] Super Admin protection: has all permissions, cannot be deleted or stripped of privileges by other staff or role edits (Pest tested)
- [x] Custom role creation, permission matrix assignment, multi-role staff assignment (Pest tested)
- [x] Frontend dynamically hides unauthorized sidebar items, buttons, and actions

## 5. Catalog (Products & Categories)
- [x] 2-level parent-child categories CRUD with circular parent-child prevention (Pest tested)
- [x] Products CRUD with unique SKU validation, slug, regular/discount price, stock, active/inactive, multiple image uploads & image deletion (Pest tested)
- [x] Storefront listing: search, category filter (parent+child), price range filter, color/size filters, sorting (`price_asc`, `price_desc`, `newest`, `popular`), server-side pagination (Pest tested + live verified)
- [x] Product detail API with multiple images, SKU, stock availability, related products (live verified)

## 6. Cart, Coupons & Checkout
- [x] Persistent MySQL cart for authenticated customers (add, update qty, remove, view, subtotal, apply/remove coupon) (Pest tested + live E2E verified)
- [x] Bonus: Guest cart persistence (`localStorage`) + automatic merge on login (`POST /cart/merge`, Pest tested)
- [x] Out-of-stock & over-stock purchase prevention (Pest tested)
- [x] Backend-only coupon validation & calculation rejecting invalid, expired, inactive, exhausted, minimum-order, and per-customer limit violations (Pest tested)
- [x] Checkout form validation (Zod + Laravel Form Request) ignoring any frontend-tampered prices/totals/discounts (Pest tested)

## 7. Stripe Payments, Orders, Inventory & Emails
- [x] Stripe Test Mode PaymentIntent creation, server-side PaymentIntent verification (amount, currency, status), delayed reconciliation endpoint (`confirm-payment`), payment retries, failed & cancelled payments (Pest tested with mocked `StripeGatewayInterface`)
- [x] Stripe Webhook signature verification (`400` on forged signature) & duplicate webhook idempotency (`webhook_events.event_id`, Pest tested)
- [~] Live browser Stripe Elements card payment entry *(UI & Stripe Elements wired — requires user's real Stripe Test Mode `pk_test_`/`sk_test_`/`whsec_` keys in `.env` & `.env.local`)*
- [x] Concurrency-safe human-readable order numbers (`ORD-YYYY-NNNNNN` via locked `order_sequences`)
- [x] Order lifecycle (`pending -> paid -> processing -> shipped -> delivered` + `cancelled`) with strict transition validation (Pest tested)
- [x] Stock decremented ONLY after verified Stripe payment inside DB transaction with ascending-ID `lockForUpdate()`; failed payment never decrements stock (Pest tested)
- [x] Concurrent oversell after payment prevents negative stock, records payment accurately, and initiates safe refund / `needs_resolution` (Pest tested)
- [x] Cancelled paid orders restore stock atomically strictly once (`stock_restored_at` guard, Pest tested)
- [x] Queued email notifications for Order confirmed, Payment succeeded, Order status changed, Order cancelled, and Refund processed (Pest tested with `Mail::fake()`)

## 8. Customer Storefront UI (Strict Local Figma Match)
- [x] Homepage visually compared in headless Google Chrome at `1440px` & `390px` against `figma/Homepage.jpg` & `Homepage (1).jpg` (hero stats alignment and mobile hero crop refined and re-verified)
- [x] Category / Shop Listing visually compared at `1440px` & `390px` against `figma/Category Page.jpg` & `Category Page.png`
- [x] Product Detail visually compared at `1440px` & `390px` against `figma/Product Detail Page.jpg` & `Product Detail Page (1).jpg`
- [x] Cart page matched to `figma/Cart.jpg` & `Cart (1).jpg` and E2E API verified (add/coupon/remove)
- [x] Checkout, Order Confirmation & Tracking, Customer Auth & Profile pages implemented & compiled cleanly

## 9. Admin Panel UI (`shadcn/ui` + Tailwind + Recharts)
- [x] Responsive Admin Shell (permission-filtered sidebar, header, breadcrumbs, dark mode bonus, toasts, skeletons, dialogs) + Next.js 16 `proxy.ts` unauthenticated redirect verified (`307 -> /admin/login`)
- [x] Dashboard with real MySQL stats, 30-day Recharts sales chart, recent orders table, low-stock alerts (live API verified)
- [x] Full CRUD UIs for Products (multi-image upload + stock dialog), Categories, Orders (status updates + cancellation), Coupons, Customers (spending & order history), Staff, Roles & Permissions Matrix (Super Admin protected), Staff Profile & Password Update
- [ ] Optional Bonus: Docker Compose file & CSV export endpoint (documented as future improvements)

## 10. Automated Testing, Builds & Git Discipline
- [x] Pest feature test suite: **23/23 tests passing (177 assertions)** against MySQL `8.0.46`
- [x] Laravel Pint formatting passes (`{"tool":"pint","result":"passed"}`)
- [x] Next.js 16 frontend: `npm run lint` (**0 errors**), `npx tsc --noEmit` (**0 errors**), `npm run build` (**30/30 routes compiled**)
- [x] Clean milestone Git commits with zero secrets committed
