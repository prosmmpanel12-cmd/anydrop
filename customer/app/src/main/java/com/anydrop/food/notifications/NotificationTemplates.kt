package com.anydrop.food.notifications

/**
 * Phase J — the 40-50 template pool `docs/Status.md`'s roadmap called for,
 * replacing `MealReminderWorker`'s old two-hardcoded-strings setup. Kept as
 * an on-device resource (not a backend table) since there's no real push
 * channel yet to deliver a server-picked template through anyway — see
 * `docs/features.md` §I6 ("still not started"). If/when FCM lands, this
 * pool is the natural thing to move server-side so copy can be updated
 * without an app release; until then, [DailyEngagementScheduler] does the
 * picking/rotation entirely on-device via [EngagementNotificationHistory].
 *
 * Categories, per the roadmap's explicit split:
 *  - HUNGER: craving/appetite-trigger copy, no offer framing
 *  - OFFER: discount/deal-style copy (generic — doesn't reference a real
 *    live coupon, since this pool has no connection to the coupons table;
 *    a genuinely offer-specific push is `NotificationHelper.showOfferNotification`,
 *    a separate code path already wired for that)
 *  - REENGAGEMENT: "haven't ordered in a while" / miss-you framing
 *
 * 45 templates total (within the 40-50 ask), split roughly evenly. Each
 * has a stable [id] — DO NOT renumber/reuse an id once shipped, since
 * [EngagementNotificationHistory]'s rotation window is keyed on these ids
 * and a reused id would let a "recently shown" template look fresh again
 * (or vice versa) purely from an id collision.
 */
object NotificationTemplates {

    enum class Category { HUNGER, OFFER, REENGAGEMENT }

    data class Template(
        val id: Int,
        val category: Category,
        val title: String,
        val message: String
    )

    val all: List<Template> = listOf(
        // ---- HUNGER (1-15) ----
        Template(1, Category.HUNGER, "Kuch chatpata ho jaye? \uD83C\uDF36\uFE0F", "Your cravings called — they want something spicy. Order now on Anydrop."),
        Template(2, Category.HUNGER, "Bhookh lagi hai? \uD83C\uDF5B", "Stop scrolling, start ordering. Your favourite dish is one tap away."),
        Template(3, Category.HUNGER, "Tummy's rumbling \uD83D\uDE0B", "That craving isn't going anywhere on its own. Order something good."),
        Template(4, Category.HUNGER, "Ek plate ban jaye? \uD83C\uDF5C", "Whatever you're craving right now, Anydrop's got it covered."),
        Template(5, Category.HUNGER, "Feeling peckish? \uD83C\uDF7D\uFE0F", "A little something now beats a lot of regret later. Order up."),
        Template(6, Category.HUNGER, "Chai ke saath kuch aur? \u2615", "Snack time calls for backup. Browse what's nearby."),
        Template(7, Category.HUNGER, "Midnight cravings hitting? \uD83C\uDF19", "Good food doesn't watch the clock. See who's still open."),
        Template(8, Category.HUNGER, "Spice level: craving \uD83C\uDF36\uFE0F", "Your taste buds are asking questions only a good meal can answer."),
        Template(9, Category.HUNGER, "Time for a food break \u23F0", "Step away from the to-do list for a minute — order something first."),
        Template(10, Category.HUNGER, "That craving again? \uD83D\uDE0B", "You know the one. Go on, order it."),
        Template(11, Category.HUNGER, "Garma-garam kuch chahiye? \uD83D\uDD25", "Something hot and fresh is closer than you think."),
        Template(12, Category.HUNGER, "Snack attack incoming \uD83C\uDF7F", "Beat it before it beats you. Order a little something."),
        Template(13, Category.HUNGER, "Meetha ho jaye? \uD83C\uDF70", "A little dessert never hurt anyone. Check what's on the menu."),
        Template(14, Category.HUNGER, "Weekend mood, weekend food \uD83C\uDF89", "Treat yourself — you've earned it. Order something indulgent."),
        Template(15, Category.HUNGER, "Rainy day, comfort food day \uD83C\uDF27\uFE0F", "Nothing beats good weather food. See what's nearby."),

        // ---- OFFER (16-30) ----
        Template(16, Category.OFFER, "Deals are waiting \uD83C\uDFF7\uFE0F", "There are offers on the table right now — go see what's cooking."),
        Template(17, Category.OFFER, "Don't miss out! \uD83D\uDCB8", "Today's a good day to save while you eat. Check current offers."),
        Template(18, Category.OFFER, "Extra savings alert \uD83D\uDD14", "A little discount goes a long way. See what's available today."),
        Template(19, Category.OFFER, "Your wallet will thank you \uD83D\uDE4C", "Good food, better prices — check today's deals."),
        Template(20, Category.OFFER, "Offers won't last forever \u23F3", "Grab a good deal before it's gone. Browse now."),
        Template(21, Category.OFFER, "Psst — check this out \uD83D\uDC40", "There's something worth seeing in today's offers."),
        Template(22, Category.OFFER, "Treat yourself for less \uD83D\uDCB0", "Why pay full price when there's a deal waiting?"),
        Template(23, Category.OFFER, "Value meal energy \uD83C\uDF7D\uFE0F", "More food, less spend — see today's offers."),
        Template(24, Category.OFFER, "Save some, eat some \uD83D\uDE0B", "A good deal tastes even better. Check what's live now."),
        Template(25, Category.OFFER, "Discounts, decoded \uD83D\uDD0D", "We've lined up a few good ones for you today."),
        Template(26, Category.OFFER, "It pays to check back \uD83D\uDCC8", "Offers refresh often — today might be your day."),
        Template(27, Category.OFFER, "Small price, big flavour \u2728", "Something tasty just got a little more affordable."),
        Template(28, Category.OFFER, "Budget-friendly bites \uD83D\uDCB5", "Good food doesn't have to cost a lot today."),
        Template(29, Category.OFFER, "First come, first served \uD83C\uDFC3", "Good deals move fast — take a look before they're gone."),
        Template(30, Category.OFFER, "A little discount never hurt \uD83D\uDE0F", "See what's on offer before you decide what's for dinner."),

        // ---- REENGAGEMENT (31-45) ----
        Template(31, Category.REENGAGEMENT, "Missed you! \uD83D\uDC4B", "It's been a while — come see what's new on Anydrop."),
        Template(32, Category.REENGAGEMENT, "Where'd you go? \uD83E\uDD14", "Your favourites are still here, waiting for you."),
        Template(33, Category.REENGAGEMENT, "Long time, no order \uD83D\uDC40", "A lot's changed since your last order — take a look."),
        Template(34, Category.REENGAGEMENT, "We kept your seat warm \uD83E\uDE91", "Come back and see what's cooking near you."),
        Template(35, Category.REENGAGEMENT, "Your usual is still here \uD83D\uDCCD", "Craving something familiar? It's just a tap away."),
        Template(36, Category.REENGAGEMENT, "New places have joined \uD83C\uDF89", "A few new restaurants are live near you — go explore."),
        Template(37, Category.REENGAGEMENT, "It's been quiet without you \uD83D\uDE22", "Come back for a bite whenever you're ready."),
        Template(38, Category.REENGAGEMENT, "Ready for round two? \uD83D\uDD01", "Whatever you loved last time, it's probably still on the menu."),
        Template(39, Category.REENGAGEMENT, "A lot's cooking near you \uD83C\uDF73", "New dishes, new places — come see what you've missed."),
        Template(40, Category.REENGAGEMENT, "Hey stranger \uD83D\uDC4B", "It's been a minute. Let's fix that with a good meal."),
        Template(41, Category.REENGAGEMENT, "We miss having you around \uD83D\uDC99", "Come back whenever you're hungry — we'll be here."),
        Template(42, Category.REENGAGEMENT, "Your cart's feeling lonely \uD83D\uDED2", "Nothing in there right now — let's change that."),
        Template(43, Category.REENGAGEMENT, "Still hungry for more? \uD83C\uDF7D\uFE0F", "Your next favourite dish might just be a scroll away."),
        Template(44, Category.REENGAGEMENT, "One tap away from a good meal \uD83D\uDC4D", "Whenever you're free, we're ready."),
        Template(45, Category.REENGAGEMENT, "Let's catch up over food \uD83C\uDF74", "It's been a while — come see what's new.")
    )

    fun byId(id: Int): Template? = all.firstOrNull { it.id == id }
}
