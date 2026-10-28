# Stock import guide — uploading stock from Excel or CSV

Stock can be received one product at a time, or uploaded as a spreadsheet. This
guide is for the person doing the upload: what the file must look like, what the
system checks, and what to do when a row is rejected.

The same instructions are built into the app (the upload window) and into the
sample workbook (its **Instructions** sheet), so nobody has to hunt for this
document.

---

## 1. The short version

1. In the app: **Inventory → Import from Excel/CSV → Download Excel sample**.
2. Fill one row per product **per branch** on the **Stock** sheet.
3. Delete the example rows.
4. Save as `.csv`, or keep the `.xlsx` — both upload.
5. Upload the file, check the preview, press **Import**.

Nothing is written to the database until you press Import, and a file with any
bad row is refused as a whole. You will never end up with half a delivery in
stock.

---

## 2. The columns

Columns are matched **by header name, not by position**, so you can move them
around. A header the system does not recognise stops the upload — that is
deliberate: silently ignoring a column called "Wholesale Price" would import the
wrong money.

| Column | Required | Format | Example | Notes |
|---|---|---|---|---|
| `Branch` | Recommended | Branch name or code | `Lilongwe` / `LLW` | Must exist in the system. Leave blank only if you choose a default branch in the upload window. |
| `Category` | **Yes** | Existing category name | `Smartphones` | Must already exist — see [Categories are never created by an upload](#5-rules-worth-knowing). |
| `Brand` | **Yes** | Text | `Samsung` | A new brand is added to the catalog automatically. |
| `Model` | **Yes** | Text | `Galaxy A15` | A new model is added to the catalog automatically. |
| `Color` | No | Text | `Blue` | Optional; used for display and grouping. |
| `Quantity` | **Yes** | Whole number, 1–100 | `5` | One device row is created per unit. Use a second row for more than 100 of the same product. |
| `Buying Price` | **Yes** | Number in MWK | `320000` | Cost per unit **for this delivery**. Currency symbols and thousands separators are accepted (`MWK 320,000`). |
| `Target Selling Price` | No | Number in MWK | `395000` | Selling price for this batch. Blank means "reuse the price already in use for this product". |
| `Serial Numbers` | No | List, comma or semicolon separated | `SM-001, SM-002` | One serial per unit. Blank means the system generates them. |
| `Supplier` | No | Text | `Mufasah Imports` | Copied onto the branch's receipt. |
| `Reference Number` | No | Text | `INV-2026-0104` | Invoice / delivery note, copied onto the receipt. |
| `Purchase Date` | No | `YYYY-MM-DD` | `2026-10-20` | Cannot be in the future. |
| `Purchase Location` | No | Text | `Lilongwe Warehouse` | Where the stock came from. |
| `Description` | No | Text | `Dual SIM` | Stored on each device of that row. |
| `Notes` | No | Text | `Opening stock` | Stored on the batch and each device. |

Accepted header spellings are forgiving within reason — `Qty`, `Units`,
`Cost`, `Purchase Price`, `Colour`, `IMEI`, `Date`, `Vendor` and similar all
resolve. Anything outside that list is an error rather than a guess.

---

## 3. A worked example

Two branches, one file, three products:

```csv
Branch,Category,Brand,Model,Color,Quantity,Buying Price,Target Selling Price,Serial Numbers,Supplier,Reference Number,Purchase Date
Lilongwe,Smartphones,Samsung,Galaxy A15,Blue,5,320000,395000,,Mufasah Imports,INV-2026-0104,2026-10-20
Lilongwe,Smartphones,Tecno,Spark 20,Black,3,185000,235000,,Mufasah Imports,INV-2026-0104,2026-10-20
Blantyre,Smartphones,Samsung,Galaxy A15,Blue,2,325000,400000,"SM-A155-0001, SM-A155-0002",Mufasah Imports,INV-2026-0105,2026-10-22
```

Uploading this produces:

- **2 receipts** — `REC-2026-00001` for Lilongwe, `REC-2026-00002` for Blantyre.
- **3 batches** — one per product line, each carrying its own cost and target
  price. Lilongwe's Galaxy A15 batch costs 320,000 and sells at 395,000;
  Blantyre's costs 325,000 and sells at 400,000. That is the point of a batch:
  the same product bought twice at different prices keeps both margins.
- **10 devices** — 5 + 3 + 2, each with a serial number, in stock, at the right
  branch. The two Blantyre units get the serials from the file; the other eight
  get generated serials.
- **10 opening movements** in the stock ledger, so every unit is traceable from
  the moment it exists.

---

## 4. What the upload does

**Step 1 — preview (nothing is saved).** The file is parsed and every row
checked. You see the branch, product, quantity and both prices per row, the
rows that would fail with the reason, and totals. A row that would reuse an
existing selling price is marked *inherited* so you can see the number that will
actually be used.

**Step 2 — import.** The file is re-parsed and re-validated, then written in a
single transaction. If any row fails, **nothing** is written and you get the same
row-level detail back. Fix the sheet and upload again.

Because the second step re-reads the file rather than trusting the preview, what
gets committed is always what was validated in that same request.

### Where the stock lands

| You see | Behind it |
|---|---|
| One receipt per branch | `inventory_receipts`, numbered `REC-YYYY-NNNNN` |
| One line per row | `inventory_batches`, carrying cost + target price |
| One unit per quantity | `devices`, status `in_stock`, linked to the batch |
| One ledger entry per unit | `inventory_movements`, type `receipt` |

The receipt notes record the uploaded filename, and an `inventory_import` entry
is written to the activity log with the row and unit counts.

---

## 5. Rules worth knowing

**Branches.** One row per product **per branch**. A file may mix branches freely
— that is the point — and each branch gets its own receipt. A branch is matched
by name or by code, case-insensitively, and "Lilongwe" also matches "Lilongwe
Branch". An inactive branch is refused. A store admin can only import into their
own branch; rows aimed elsewhere are rejected with an explanation.

**Categories are never created by an upload.** A typo would otherwise fork the
catalog ("Phone" beside "Phones") and split every report that groups by category.
Create genuinely new categories in **Product Catalog** first.

**Brands and models *are* created automatically**, exactly as the manual
"Receive Stock" form does, so a new phone can be received without catalog
housekeeping first.

**Prices belong to the batch, not the product.** Leaving `Target Selling Price`
blank reuses the price already in use for that product (the newest priced batch
in that branch first, then the catalog base price). It only fails when there is
no earlier price to inherit. `Buying Price` is never inferred — it is real cost
data for this delivery.

**Quantities.** 1–100 per row, mirroring the manual form. To receive 250 units,
use three rows.

**Serials.** Leave the column blank and serials are generated. Fill it in and you
must supply exactly one per unit; duplicates inside the file, or serials already
in the system, are rejected.

**All-or-nothing.** A rejected file writes nothing at all. This is deliberate: a
partially imported delivery is invisible until somebody counts the shelf.

**Limits.** 5 MB, 2,000 rows, 5,000 units per upload. Beyond that, split the file.

---

## 6. When a row is rejected

| Message | What to do |
|---|---|
| `Unrecognised column header(s): …` | A header is misspelled or is not a column the system knows. Rename it to one from the sample, or delete the column. |
| `Missing required column(s): …` | Add the missing column. `Category`, `Brand`, `Model`, `Quantity` and `Buying Price` are always required. |
| `More than one column maps to the same field` | Two headers mean the same thing (e.g. `Cost` and `Buying Price`). Keep one. |
| `Unknown category "Tablets". Known categories: …` | Create the category in Product Catalog, or correct the spelling. |
| `Unknown branch "Mzuzu". Known branches: …` | Correct the branch, or create it first. |
| `Branch is required` | The file has no `Branch` column and no default branch was chosen in the upload window. |
| `No Target Selling Price and none could be inherited` | Enter a selling price for that row — there is no earlier stock of that product to copy from. |
| `Serial Numbers lists 2 value(s) but Quantity is 3` | Provide one serial per unit, or clear the column to have them generated. |
| `Serial number(s) already in the system: …` | Those units are already in stock. Correct the serials. |
| `Purchase Date … is in the future` | Fix the date. |
| `Quantity "two" is not a whole number` | Quantities are digits only. |
| `You can only import stock for your own branch` | Your account is scoped to one branch; remove the other branches' rows. |
| `The file has more than 2000 rows` | Split it into several files. The ceiling is on rows read, not rows imported, so blank spacer rows count towards it. |
| `This file declares N units, more than the 5000-unit limit` | Split it. One upload cannot receive more than 5,000 units in total. |

Fix the rows marked in red and upload the same file again.

---

## 7. For developers

### Endpoints

All three sit behind `permission:manage-inventory`.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/v1/admin/inventory/import/template?format=xlsx\|csv` | Sample file. `xlsx` (default) carries an Instructions sheet. |
| `POST` | `/api/v1/admin/inventory/import/preview` | Parse + validate. Writes nothing. |
| `POST` | `/api/v1/admin/inventory/import` | Parse + validate + receive. |

Both `POST` endpoints take `multipart/form-data` with `file` (`.csv`, `.txt`,
`.xlsx`, ≤ 5 MB) and an optional `branch_id` used for rows with a blank `Branch`.

The import endpoint answers `201` on success, or `422` with the full report in
`data` when the file is not importable — a rejected file is a normal outcome, not
a server error.

### Code map

| File | Responsibility |
|---|---|
| `app/Support/Spreadsheet/SpreadsheetReader.php` | Dependency-free CSV/XLSX reader. Returns headers plus numbered rows. |
| `app/Services/InventoryImportService.php` | Header matching, row parsing, branch/category resolution, price inheritance, grouping, commit. |
| `app/Services/InventoryIntakeService.php` | The one implementation of intake: receipt → batch → devices → ledger. Shared with the manual form. |
| `app/Services/InventoryImportTemplate.php` | The sample `.csv` / `.xlsx`. |
| `app/Support/Inventory/InventoryBranchGuard.php` | "Which branch may this user write to" — shared with the manual form. |
| `app/Http/Controllers/Api/V1/Admin/AdminInventoryImportController.php` | HTTP layer only. |
| `web/src/components/inventory/ImportStockModal.tsx` | The upload window: preview, row errors, confirmation. |

The importer never writes stock itself. It translates rows into the same
`InventoryIntakeService` calls the manual receipt form makes, so batches, price
inheritance, serial generation and the movement ledger cannot drift between the
two entry points. Adding a column means touching `InventoryImportService::COLUMNS`,
`COLUMN_LABELS` and — for the sample — `InventoryImportTemplate`.

### Tests

- `tests/Unit/Support/Spreadsheet/SpreadsheetReaderTest.php` — encoding, delimiter
  detection, quoted fields, inline vs shared strings, sheet resolution via rels.
- `tests/Feature/Api/V1/Admin/AdminInventoryImportTest.php` — multi-branch
  grouping, price inheritance and parsing, per-row errors, all-or-nothing
  rollback, permissions, sample round-trip.
