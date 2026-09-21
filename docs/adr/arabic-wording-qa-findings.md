# Arabic wording QA findings

- Status: Open log, not a decision record
- Date opened: 2026-09-20
- Purpose: Record Arabic wording defects and owner wording decisions found during manual QA, so they can be fixed in one later pass
- Reviewer: Project owner, native Lebanese Arabic speaker
- Locales in scope: `ar-LB` and `ar-EG`
- Baseline: `develop` snapshot `bcc9efc7c1ecf48273f03c5c0e3b24a8aef0c350`

This file is a running list. It is stored beside the ADRs for convenience. It is not an architecture decision record and nothing in it is accepted or implemented until a separate change is made. It carries no record number so it never takes one reserved for a workstream ADR.

Status values used below: `open` means agreed and waiting for a fix, `decided by the owner` means the owner has chosen the target wording, `open question for the owner` means the fix cannot start until the owner answers, `fixed` means the change is implemented on a branch, and `partly fixed` means the wording is done but code work remains.

## Section 1: Sale screen, register mode picker

### 1.1 The picker label reads as "registration mode"

- Status: fixed on 2026-09-21 on branch `fix/arabic-wording`, both locales
- Screen: Sale screen, drop-down at the top of the register
- Current Arabic: `وضع التسجيل`
- English source: `Register Mode`
- Problem: `التسجيل` means recording or enrolling. The English `Register` means the cash register. An Arabic reader sees "sign-up mode" and cannot tell that the control chooses between selling and returning.
- Proposed Arabic: `نوع العملية`
- Reason: the control chooses a transaction type, so naming it by that is both accurate and self-explanatory.
- String: `Sales.mode`

### 1.2 The picker must offer exactly three choices

- Status: partly fixed on 2026-09-21 on branch `fix/arabic-wording`
- Done: the three labels now read as the owner chose, in both locales. The Egyptian return label was corrected to the owner's spelling.
- Not done: the option list itself still offers four or five entries. Trimming it to three needs a code change outside the language files and was left out of the wording branch on purpose. Recorded in ADR 0010 as superseding the 2026-09-20 register-mode decision.
- Screen: Sale screen, drop-down at the top of the register
- Decision: the drop-down shows only these three, in the owner's wording:
  - `عملية بيع`
  - `عرض أسعار`
  - `إسترجاع`
- Removed from the picker: `فاتورة` (invoice) and `طلب عمل` (work order). Neither is used in a supermarket or fast-food shop.
- Owner wording note: the owner chose `عملية بيع` over the shorter `بيع`. The shorter form was proposed and dismissed.
- Owner wording note: the owner confirmed `إسترجاع` as written. The alternative spelling `استرجاع` was raised and dismissed.
- Implementation note: no combination of existing settings produces exactly these three. With invoicing off the picker offers only sale and return, with no price offer. With invoicing on it offers four or five entries. Delivering the three-item list therefore needs a small code change in the function that builds the option list, not a settings change.
- Risk: invoice and work order remain reachable elsewhere in the application. Removing them from this picker hides them from the cashier. It does not delete existing invoices or work orders, and it does not change how any saved record behaves.
- Strings and code: `Sale_lib::get_register_mode_options()`; labels `Sales.sale`, `Sales.receipt`, `Sales.quote`, `Sales.work_order`, `Sales.invoice`, `Sales.return`

### 1.3 A stray hash sign prints on every paper receipt

- Status: fixed on 2026-09-21 on branch `fix/arabic-wording`, both locales
- Screens: Sale screen picker, and the heading at the top of every printed receipt
- Current Arabic: `عملية بيع #`
- English source: `Sales Receipt`
- Problem: the trailing `#` is left over from the English and has no number after it. It prints on every receipt, in all three receipt layouts and in emailed receipts.
- Proposed fix: remove the `#`.
- Severity: visible to every customer on every printed receipt.
- String: `Sales.receipt`

### 1.4 One text is doing two different jobs

- Status: open. Not attempted on `fix/arabic-wording` because it needs a new string and edits to the three receipt views, which is code, not translation.
- Problem: the same text is used as the name of the sale mode in the picker and as the heading printed at the top of the receipt. A mode name and a document heading are not the same kind of phrase, so no single wording fits both well.
- Proposed fix: separate them. Keep the owner's `عملية بيع` as the mode name. Give the printed receipt its own heading, for example `إيصال بيع`.
- Note: this needs a new string, because upstream shares one string across both uses.
- Strings and code: `Sales.receipt` used in `Sale_lib::get_register_mode_options()` and in the three receipt views

### 1.5 Missing hamza in the price offer label

- Status: fixed on 2026-09-21 on branch `fix/arabic-wording`, both locales
- Current Arabic: `عرض اسعار`
- Proposed Arabic: `عرض أسعار`
- Problem: the second word is missing its hamza.
- String: `Sales.quote`

### 1.6 Unused translation overstates what an invoice is

- Status: fixed on 2026-09-21 on branch `fix/arabic-wording`, both locales. The word "official" was removed and the entry was kept.
- Current Arabic: `البيع بفاتورة رسمية`
- English source: `Sale by Invoice`
- Problem: nothing in the application displays this text, so it is dead. It also adds the word "official", which in Lebanon suggests a tax document the software does not produce.
- Proposed fix: remove the word "official", or delete the entry.
- String: `Sales.sale_by_invoice`

## Section 2: Sale screen, item search and cart table

### 2.1 The word for a sold thing must be `سلعة`

- Status: fixed on 2026-09-21 on branch `fix/arabic-wording`, in the eleven listed Lebanese strings
- Screens: sale screen item search box, and the cart table headings
- Decision: wherever the application currently says `مادة` or `المادة`, it must say `سلعة` or `السلعة`.
- Reason: `مادة` means material or substance. A supermarket sells goods, and `سلعة` is the word a Lebanese shopper and cashier use.
- Scope found: 18 occurrences, all of them in the Lebanese sales strings. The Egyptian locale does not use this word at all.
- Affected strings, all in `app/Language/ar-LB/Sales.php`: `edit_item`, `error_editing_item`, `find_or_scan_item`, `find_or_scan_item_or_receipt`, `item_insufficient_of_stock`, `item_name`, `item_number`, `item_out_of_stock`, `new_item`, `no_items_in_cart`, `quantity_less_than_reorder_level`

### 2.2 A second word for the same thing is used everywhere else

- Status: closed on 2026-09-21. The owner's decision was applied on branch `fix/arabic-wording`: only the rare word changed, and the common word was left untouched in both locales. Both questions below are answered by that decision and need no further input.
- Decision, 2026-09-21: only the rare word changes. Replace `مادة` with `سلعة` in the Lebanese sales strings as described in 2.1, and leave `صنف` exactly as it is in both locales.
- Consequence accepted by the owner: the two locales keep `صنف` as the everyday word, and no reports, inventory or item management strings are touched.
- Problem: the application uses two different Arabic words for the same thing, and they appear one line apart on the same screen. The search box label says `باركود المادة`. The grey hint text inside the same box says `باركود الصنف`.
- Counts: `صنف` and its forms appear about 100 times in the Lebanese locale and about 117 times in the Egyptian locale. `مادة` appears 18 times, only in the Lebanese sales strings.
- Question: does `سلعة` replace `صنف` as well, or only `مادة`? If only `مادة` changes, the application will still carry two words for the same thing, just a different pair.
- Second question: does the change apply to the Egyptian locale too, or is `صنف` correct there?
- Why it matters: replacing only `مادة` is a small edit in one file. Replacing `صنف` as well touches most translated screens in both locales, including reports, inventory, and item management.
- Note: `صنف` is not wrong. It means a type or a kind, and it is the normal word in Egyptian retail software. The issue is that both words are in use at once.

### 2.3 Wrong verb form in the two search hints

- Status: fixed on 2026-09-21 on branch `fix/arabic-wording`, both locales
- Current Arabic: `ابداء بكتابة اسم أو مسح باركود الصنف...` and `ابداء بكتابة اسم العميل...`
- Problem: `ابداء` is not a command. The imperative is `ابدأ`.
- Proposed Arabic: `ابدأ بكتابة ...`
- Strings: `Sales.start_typing_item_name`, `Sales.start_typing_customer_name`

### 2.4 The Lebanese sales strings are written in Egyptian spelling

- Status: fixed on 2026-09-21 on branch `fix/arabic-wording`, for the Lebanese sales file only. The wider point stands: other Lebanese files were not checked and may carry the same pattern.
- Problem: the Lebanese sales file spells words the Egyptian way, using `ى` where Lebanese writing uses `ي`, and joining words that should be separate.
- Examples seen on this screen and nearby: `خطاء` for `خطأ`, `فى` for `في`, `لايوجد` for `لا يوجد`, `كافى` for `كافٍ`, `الايصال` for `الإيصال`.
- Proposed fix: one spelling pass over the whole Lebanese sales file rather than string-by-string fixes.
- Note: this is a symptom, not a one-off. The Lebanese file appears to have been copied from an Egyptian source. Other Lebanese files should be checked for the same pattern.
- Leftovers found on 2026-09-21 and deliberately not touched, because they are cosmetic and pre-date this work: `Sales.key_finish_sale` still reads `كمل واتمام`, where `كمل` looks like stray text from upstream and `واتمام` wants a hamza; `Sales.none_selected` still reads `بإختيار` instead of `باختيار`; `Sales.confirm_restore` still reads `انت` instead of `أنت`. Fold these into a later pass over the whole Lebanese tree rather than widening this one.

### 2.5 A second stray hash sign, in the cart table heading

- Status: fixed on 2026-09-21 on branch `fix/arabic-wording`
- Current Arabic: `مادة رقم #`
- English source: `Item Number`
- Problem: the same leftover `#` as finding 1.3. It shows as a column heading in the cart with nothing after it.
- Proposed fix: remove the `#`, and apply the word decision from 2.1, giving `رقم السلعة`.
- String: `Sales.item_number`

## Reference: what each register mode actually does

Recorded so later fixes do not change behavior by accident.

| Mode | Takes money | Moves stock | Counts in sales reports | Saved as |
| --- | --- | --- | --- | --- |
| Sale | yes | yes, down | yes | completed |
| Invoice | yes | yes, down | yes | completed |
| Return | yes, out of the drawer | yes, up | yes, as a minus | completed |
| Price offer | no | no | no | suspended |
| Work order | no | no | no | suspended |

Stock only moves when a sale is saved as completed, which is why price offers and work orders leave inventory untouched.
