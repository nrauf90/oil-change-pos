# PRODUCT REQUIREMENT DOCUMENT (PRD) / BUILD PROMPT

You are an expert software architect and full-stack developer. Build a specialized Point of Sale (POS) system for an automotive oil change and car repair shop. 

The primary business logic requires complete pricing flexibility: prices are NOT fixed by inventory items. The salesperson must have total control to override or manually input charges for products, labor, and repairs based on negotiation, vehicle complexity, or customer profiling.

---

## 1. USER AUTHENTICATION & MULTIPLE ROLES

Implement a secure authentication system with role-based access control (RBAC). The system must recognize three distinct roles:

1. **Admin / Owner:** Full system access. Can view financial dashboards, modify master inventory reference costs, delete transactions, view historical logs, and add/remove users.

2. **Manager / Front-Desk Cashier:** Can create sales, add items "on-the-fly", record daily expenses, view daily cash-drawer reports, and look up past invoices. Restrict access to master margin analytics or system deletion tools.

3. **Technician / Mechanic:** Read-only access to view conversational scripts, lookup previous vehicle service history by phone number, and log multi-point inspection notes. Cannot create sales bills, view pricing, or view cash flow data.

---

## 2. ADVANCED INVENTORY MANAGEMENT MODULE

Provide a dedicated backend management interface for the Inventory (accessible by Admin/Manager):

- **Full Inventory Screen:** A clear datatable tracking all `Items` (Products & Repairs).

- **Stock Management:** For items classified as `Products`, track an optional `Current Stock Level` and `Low Stock Alert Threshold`. 

- **Stock Deductions:** Even though prices are typed in manually during checkout, selecting a Product item from the cart must accurately deduct exactly 1 unit (or specified quantity) from the inventory stock upon closing the sale.

- **Reference Costing:** Track the actual cost price `unit_cost`) to allow admins to assess profit margins based on the *manually input sale price* versus the static product cost.

---

## 3. FLEXIBLE SALES & BILLING INTERFACE (THE MAIN ENGINE)

The billing interface must allow a seamless workflow for creating a custom sale without breaking UI state:

### A. Customer & Vehicle Identification

- Text inputs for: Customer Name, Mobile Number (WhatsApp-enabled text field), Vehicle Model/Year, License Plate Number, and Current Odometer Mileage.

### B. Line-Item Generation 

The salesperson must be able to build an invoice using three distinct manual methods simultaneously:

1. **Inventory Product Selection:** The user selects a product. The **price field stays blank or editable**. The salesperson manually types in the exact amount being charged to *this specific customer*.

2. **Inventory Repair Selection:** The user selects a predefined repair task, but the **charge field remains fully manual and editable**.

3. **Pure Custom Entry:** A blank text row allowing the user to type a custom description and manually assign a price.

### C. "On-The-Fly" Item Creation

- While building a bill/cart, if a product or repair item does not exist in the dropdown list, provide a fast "Quick Add New Item" button/modal pop-up right next to the dropdown. 

- When clicked, a small form opens to type the **Item Name**, select the **Type (Product or Repair)**, and input an initial stock/cost. 

- Upon saving, this new item must instantly inject into the master inventory database *and* automatically populate into the current cart row so the user can immediately type its manual sale price **without losing or resetting any typed data on the rest of the bill**.

### D. Manual Labor & Maintenance Add-ons

- Provide dedicated input fields for adding **Custom Labor Charges** and **Miscellaneous Repair Activity Fees** directly to the bottom of the invoice. 

### E. Final Total Calculation

- The system must add up the manually entered product prices + manual repair fees + manual labor + manual misc fees to display the Final Payable Total. 

---

## 4. DAILY EXPENSES & CASH FLOW MODULE

Track shop overhead directly alongside your revenue to calculate the actual net daily cash in the drawer.

1. **Expense Logging Form:** A simple interface to log outlays on the fly. Fields: `Expense Category` (e.g., Shop Supplies, Refreshments, Utility, Tea/Lunch, Parts Procurement), `Amount`, `Description`, and `Logged By`.

2. **Cash Flow Summary (Shift / Day Reconciliation):** 

   - **Cash In:** Total of all manually charged invoices finalized today.

   - **Cash Out:** Total of all expenses logged today.

   - **Net Cash Position:** `Total Cash In - Total Cash Out`. Show this large and clear so managers can reconcile the physical cash drawer at the end of the day.

---

## 5. TECHNICAL SPECIFICATIONS & DB UPDATES

- **Database Schema (Extended):** 

  - `Users`: `id`, `username`, `password_hash`, `role` (Admin, Manager, Technician).

  - `Items`: `id`, `name`, `type` (Product/Repair), `unit_cost`, `stock_level`, `low_stock_alert`.

  - `Sales`: `id`, `cashier_id`, `customer_name`, `phone`, `vehicle_plate`, `mileage`, `labor_charge`, `misc_charge`, `total_amount`, `timestamp`.

  - `Sale_Items`: `id`, `sale_id`, `item_id`, `item_name`, `quantity`, `manually_charged_price`.

  - `Expenses`: `id`, `user_id`, `category`, `amount`, `description`, `timestamp`.

- **UI/UX Style:** High-contrast, mobile/tablet-friendly layout for grease-stained environments. State preservation on the checkout page must be bulletproof so quick-adding items or navigating sub-tabs never resets active cart state.

---

## 6. CONVERSATIONAL POS SCRIPT COMPONENT (FOR SCREEN DISPLAY)

Integrate a UI sidebar or popup widget containing conversational scripts for the counter staff based on the billing workflow. It should feature:

1. **Check-In Prompts:** Script for asking name, plate number, and mileage to save in the system.

2. **Upsell Scripts:** Conversational guides explaining the differences between Conventional, Semi-Synthetic, and Full Synthetic oil.

3. **Objection Handlers:** Scripts addressing common statements like "Synthetic oil is too expensive" or "Why do you need my phone number?"

---

### EXECUTION STEP

Please build the entire frontend, backend, and database tracking system using this exact logic. Ensure that state handling on the sale screen is fully robust during "on-the-fly" item creation. Provide a functional, clean, and highly modular codebase.

