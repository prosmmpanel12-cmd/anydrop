# AnyDrop Docs — Arrangement Kaise Kiya Gaya (Index)

Zip mein total **68 documentation files** (.md) thi, bikhri hui — kuch root
mein, kuch `docs/` mein, kuch `docs/restorent/` mein. Maine sabko padh kar,
content ke hisaab se **6 folders** mein arrange kiya hai. App ka actual code
(Android/PHP source) bilkul chheda nahi gaya hai — sirf documentation
reorganize hui hai.

## Folder Structure

### 📁 01_General_Project — 23 files
Woh docs jo **kisi ek app tak limited nahi** — poore project ko cover karte
hain (schema, API contract, phase plans, bug tracker, status/recall/pending
logs, security roadmap, deploy/build cheat-sheets). Ye sabse pehle padhne
chahiye kyunki inme overall picture hai.
Important: `00_README.md` (master blueprint), `01_Database_Schema.md`,
`02_API_Contract.md`, `Status.md`, `recall.md`, `PENDING.md`, `done.md`.

### 📁 02_Restaurant — 14 files
Restaurant Partner App se related sab kuch: full scope doc, UI plan, known
issues, session handovers (`docs/restorent/` folder ka sara content yahin
aaya), order pause toggle, restaurant-side offers creation (combo/bundle,
coupon stacking, apply-mode UI).

### 📁 03_Customer — 11 files
Customer App se related: Zomato-style UI upgrade plan (`features.md`), item
detail sync/share, coupons page, map pin-drop + address photo, scheduled
orders, aur customer-facing offers UI (badges, pills, checkout strip, offers
browse screen).

### 📁 04_Admin — 17 files
Admin Panel se related: full spec, payment/UPI gateway architecture, offers
pricing & commission polish, restaurant/customer suspend feature, order
control, analytics, accounts & cash-flow / area-cash management, admin RBAC
gap docs.

### 📁 05_Rider — 1 file
**Note:** Is project mein abhi ek alag "Rider App" ka code nahi hai — sirf
plan hai (Phase 4, `00_README.md` mein mentioned: restaurant apne riders
create karta hai, koi public rider signup nahi). Sirf `03_Live_Tracking.md`
hi hai jo purely rider-side GPS tracking/live-location design cover karta
hai, isliye wahi is folder mein hai. Rider se related aur mentions (jaise
`02_API_Contract.md` ke rider endpoints) General folder ke docs mein bhi
milenge kyunki wo poore system ko cover karte hain.

### 📁 06_Payment_Gateway_Reference — 2 files
Third-party "UPI Pe" gateway SDK ke reference README/readme — ye AnyDrop ka
apna doc nahi hai, bas reference ke liye rakha gaya tha, isliye alag rakha.

## Kuch docs do apps ko touch karte hain — decision kaise liya

Kai "Offers" feature ke handover docs (docs 29–40) restaurant + customer +
admin, teeno ko touch karte hain (ek hi feature multiple sessions mein
banaya gaya). Har file ko uske **primary work area** ke hisaab se rakha gaya
hai — jaise "Restaurant Offer creation UI" wala Restaurant mein, "Customer
offer badges/checkout" wala Customer mein, "Offers engine backend + admin
visibility" wala Admin mein. Agar koi file dhundhne mein confusion ho to
yeh file naam se search kar sakte hain — sab original filenames preserve
kiye gaye hain.

## ⚠️ Multi-App Docs — jo file 1 se zyada app/plan cover karti hai

Neeche wo saari files hain jo sirf ek app tak limited nahi thi — inko maine
unke **primary/majority work** ke hisaab se ek folder mein rakha hai, lekin
yahan clearly likh raha hoon ki wo **aur kaunse app/area ko bhi touch**
karti hain. Agar kisi specific app (jaise sirf Customer) ka pura context
chahiye to in files ko bhi zaroor check karna.

| File | Rakha Gaya (Folder) | Bhi Touch Karta Hai |
|---|---|---|
| `docs/00_README.md` | General | Restaurant + Customer + Admin + Rider (master blueprint, sab cover karta hai) |
| `docs/02_API_Contract.md` | General | Restaurant + Customer + Admin + Rider (sabke API endpoints ek hi jagah) |
| `docs/06_Phase_3.6_UI_Fixes_And_New_Features.md` | General | Restaurant + Customer + Admin |
| `docs/07_Phase_3.7_Bug_Tracker.md` | General | Restaurant + Customer + Admin |
| `docs/09_Auto_Bestseller_Discount_And_Git_Push.md` | General | Restaurant (bestseller/discount menu-item feature) |
| `docs/21_Production_Feature_Gap_Plan.md` | General | Restaurant + Customer + Admin + Rider (planned) |
| `docs/22_UI_UX_Overhaul_Feedback_2026-08-18.md` | General | Restaurant + Customer |
| `HANDOVER_TAGS_FEATURE.md` | General | Restaurant (tags add karna) + Customer (Home filter chips) |
| `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` | Restaurant | Customer (rating dena customer app se hota hai) |
| `docs/16_Handover_I4_Followups_And_Order_Toggle.md` | Restaurant | Customer ("temporarily unavailable" badge customer app pe dikhta hai) |
| `docs/29_Handover_2026-08-24_Offers_Engine_Backend_Built.md` | Admin | Restaurant (offers restaurant hi banata hai) |
| `docs/30_Handover..._Offers_Restaurant_UI_Started_Not_Finished.md` | Restaurant | Admin (backend engine docs/29 se linked) |
| `docs/31_Handover..._FreeDelivery_Notice_Scroll_Fix.md` | Restaurant | Admin |
| `docs/32_Handover..._Customer_App_Offer_Badges.md` | Customer | Restaurant (badges restaurant menu offers se aate hain) |
| `docs/33_Handover..._Offer_Coupon_Toggle_And_Badges_Extended_Partial.md` | Customer | Restaurant |
| `docs/34_Handover..._Offer_Pills_And_Checkout_Strip.md` | Customer | Restaurant |
| `docs/35_Handover..._Offers_Screen_Backend_And_Models_Partial.md` | Customer | Admin/Backend (naya endpoint) |
| `docs/35_Handover_Checkout_Items_And_CouponBased_Offers.md` | Customer | Restaurant (coupon-based offers restaurant banata hai) |
| `docs/36_Handover..._Offers_Screen_Adapter_Activity_And_Home_Wiring_Complete.md` | Customer | Restaurant |
| `docs/37_Handover..._Offer_Coupon_Stacking_Toggle_UI_Wired.md` | Restaurant | Admin (`allow_coupon_stacking` setting) |
| `docs/38_Handover..._Offer_ApplyMode_CouponBased_UI_Wired.md` | Restaurant | Admin (backend apply_mode/code) |
| `docs/39_Handover..._Offer_ApplyMode_Admin_Visibility_And_Error_Path_Verified.md` | Admin | Restaurant (restaurant-created offers) + Customer (checkout error path) |
| `docs/40_Plan_Combo_Bundle_Offer_Type_2026-08-25.md` | Restaurant | Admin + Customer (combo offer end-to-end sabko touch karta hai) |
| `docs/20_Offers_Pricing_UI_Polish_Notes.md` | Admin | Restaurant (restaurant offer-creation UI polish) |
| `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` | Admin | Customer (payment/OTP email checkout flow) |
| `docs/23_Native_UPI_Payment_Gateway_Architecture_2026-08-23.md` | Admin | Customer (checkout payment) + Backend |
| `docs/25/26/27/28_Handover_..._Suspend_...` (4 files) | Admin | Restaurant + Customer (suspend feature dono app ke accounts affect karta hai) |
| `docs/12_Handover_H6_Map_PinDrop_Photo.md` | Customer | Rider (isme ek poora "Rider navigation, background tracking, drop-off OTP" planning section bhi hai, jo Rider-app scope ka hai, doc ke andar hi clearly likha hai) |

## Naam clash fix

Do jagah original filenames same/similar the (`00_Status.md`,
`NEXT_SESSION_PROMPT.md`) — inko **Restaurant** folder mein prefix
(`Restorent_`) de diya gaya hai taaki confuse na ho.
